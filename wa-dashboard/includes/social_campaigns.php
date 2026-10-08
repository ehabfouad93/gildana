<?php
declare(strict_types=1);

/**
 * Campaigns on Messenger and Instagram.
 *
 *   window24  People who wrote to the Page in the last 24 hours. Meta allows any message inside
 *             that window, so these are ordinary messages. Checked again at the moment of sending:
 *             someone whose window closed while the campaign was queued is skipped, not spammed.
 *   optin     Messenger Marketing Messages: people who tapped "Get messages" on an opt-in request
 *             (sent from the Inbox while their window was open). Meta gives a token per person and
 *             frequency (daily / weekly / monthly); a campaign sends to the token at most once per
 *             that period, and never after the person stops it or the token expires.
 *
 * Messages queue in campaign_messages (recipient_ext = PSID / IGSID / opt-in token) and are sent by
 * cron/dispatch.php through social_campaign_dispatch(), with the same statuses, counts, credits
 * and failure reasons as WhatsApp campaigns.
 */

require_once __DIR__ . '/social.php';
require_once __DIR__ . '/campaign.php';

/** Hours of the 24-hour window we still treat as open when queueing: leaves time to send. */
const SOCIAL_WINDOW_HOURS = 23;

function social_campaign_period_days(?string $freq): int
{
    return ['DAILY' => 1, 'WEEKLY' => 7, 'MONTHLY' => 30][strtoupper((string) $freq)] ?? 1;
}

/**
 * Who a campaign would reach: [['contact_id' => …, 'ext' => …], …].
 * $listId narrows it to a contact list (optional).
 */
function social_campaign_audience(array $client, string $channel, string $kind, string $pageId, int $listId = 0): array
{
    $cid = (int) $client['id'];
    $listJoin = $listId > 0 ? " JOIN contact_list_members lm ON lm.contact_id=c.id AND lm.list_id=" . (int) $listId : '';
    if ($kind === 'optin') {
        if ($channel !== 'messenger' || !client_has_channel($client, 'social_mm')) return [];
        // Active, unexpired tokens whose period has passed since our last send to them.
        $rows = db_all("SELECT o.contact_id, o.token, o.frequency,
                               (SELECT MAX(m.sent_at) FROM campaign_messages m WHERE m.client_id=o.client_id AND m.recipient_ext=o.token AND m.status IN ('sent','delivered','read')) last_sent
                          FROM social_optins o JOIN contacts c ON c.id=o.contact_id $listJoin
                         WHERE o.client_id=? AND o.page_id=? AND o.status='active' AND o.token IS NOT NULL
                           AND (o.expires_at IS NULL OR o.expires_at > NOW()) AND COALESCE(c.opt_in_status,'') <> 'out'", [$cid, $pageId]);
        $out = [];
        foreach ($rows as $r) {
            if ($r['last_sent'] && strtotime((string) $r['last_sent']) > time() - social_campaign_period_days($r['frequency']) * 86400 + 3600) continue;
            $out[] = ['contact_id' => (int) $r['contact_id'], 'ext' => (string) $r['token']];
        }
        return $out;
    }
    if (!in_array($channel, ['messenger', 'instagram'], true) || !client_has_channel($client, $channel)) return [];
    [$idCol, $inCol] = $channel === 'instagram' ? ['ig_sid', 'ig_last_in_at'] : ['fb_psid', 'fb_last_in_at'];
    return array_map(fn($r) => ['contact_id' => (int) $r['id'], 'ext' => (string) $r[$idCol]],
        db_all("SELECT c.id, c.$idCol FROM contacts c $listJoin
                 WHERE c.client_id=? AND c.$idCol IS NOT NULL AND c.$inCol > NOW() - INTERVAL " . SOCIAL_WINDOW_HOURS . " HOUR
                   AND (c.social_page_id IS NULL OR c.social_page_id=?) AND COALESCE(c.opt_in_status,'') <> 'out'
                   AND c.deleted_at IS NULL", [$cid, $pageId]));
}

/** Create the campaign and queue one message per person. Returns [campaign id, error]. */
function social_campaign_create(array $client, array $in): array
{
    $cid = (int) $client['id'];
    $page = db_row("SELECT * FROM meta_pages WHERE client_id=? AND page_id=?", [$cid, (string) ($in['page_id'] ?? '')]);
    if (!$page) return [0, 'Choose the Facebook Page to send from.'];
    $aud = social_campaign_audience($client, (string) $in['channel'], (string) $in['kind'], (string) $page['page_id'], (int) ($in['list_id'] ?? 0));
    if (!$aud) return [0, 'Nobody can be reached with this audience right now.'];
    $status = !empty($in['scheduled_at']) ? 'scheduled' : 'sending';
    $vm = ['header_media' => (string) ($in['image'] ?? ''), 'buttons_social' => array_values(array_slice((array) ($in['choices'] ?? []), 0, 3))];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $camp = db_insert("INSERT INTO campaigns (client_id,name,channel,audience_kind,page_id,list_id,body_text,variable_map,status,scheduled_at,total_count,created_at,started_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?)",
                          [$cid, (string) $in['name'], (string) $in['channel'], (string) $in['kind'], (string) $page['page_id'], ((int) ($in['list_id'] ?? 0)) ?: null,
                           (string) $in['text'], json_encode($vm, JSON_UNESCAPED_UNICODE), $status, $in['scheduled_at'] ?: null, count($aud), $status === 'sending' ? date('Y-m-d H:i:s') : null]);
        foreach (array_chunk($aud, 500) as $chunk) {
            $vals = []; $p = [];
            foreach ($chunk as $a) { $vals[] = '(?,?,?,?,NULL,\'queued\',NOW())'; array_push($p, $camp, $cid, $a['contact_id'], $a['ext']); }
            db_run("INSERT INTO campaign_messages (campaign_id,client_id,contact_id,recipient_ext,phone_e164,status,updated_at) VALUES " . implode(',', $vals), $p);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('social_campaign_create: ' . $e->getMessage());
        return [0, 'Could not create the campaign. Please try again.'];
    }
    return [$camp, ''];
}

/**
 * Send queued Messenger / Instagram campaign messages. Called by cron/dispatch.php each run.
 * Returns [sent, failed].
 */
function social_campaign_dispatch(string $workerId, int $cap = 300): array
{
    if (!db_has_column('campaigns', 'channel')) return [0, 0];
    $sent = 0; $failed = 0; $touched = [];
    $camps = db_all("SELECT c.*, cl.status client_status FROM campaigns c JOIN clients cl ON cl.id=c.client_id
                      WHERE c.status='sending' AND c.channel IN ('messenger','instagram') AND cl.status='active' ORDER BY c.id");
    foreach ($camps as $camp) {
        if ($sent + $failed >= $cap) break;
        $cid = (int) $camp['client_id'];
        $client = db_row("SELECT * FROM clients WHERE id=?", [$cid]);
        $channel = (string) $camp['channel'];
        $kind = (string) $camp['audience_kind'];
        // Claim a batch for this worker.
        $ids = array_column(db_all("SELECT id FROM campaign_messages WHERE campaign_id=? AND status='queued' ORDER BY id LIMIT " . max(1, $cap - $sent - $failed), [(int) $camp['id']]), 'id');
        if (!$ids) { campaign_refresh_counts((int) $camp['id']); continue; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        db_run("UPDATE campaign_messages SET status='sending', claimed_at=NOW(), claimed_by=?, updated_at=NOW() WHERE id IN ($ph) AND status='queued'", array_merge([$workerId], $ids));
        $msgs = db_all("SELECT * FROM campaign_messages WHERE id IN ($ph) AND claimed_by=? ORDER BY id", array_merge($ids, [$workerId]));
        $vm = json_decode((string) $camp['variable_map'], true) ?: [];
        $okChannel = client_has_channel($client, $kind === 'optin' ? 'social_mm' : $channel);

        foreach ($msgs as $m) {
            $fail = function (string $code, string $title) use ($m, &$failed) {
                db_run("UPDATE campaign_messages SET status='failed', error_code=?, error_title=?, claimed_by=NULL, updated_at=NOW() WHERE id=?", [$code, $title, (int) $m['id']]);
                $failed++;
            };
            $contact = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [(int) $m['contact_id'], $cid]);
            if (!$okChannel) { $fail('channel_off', social_channel_label($channel) . ' is no longer part of the plan.'); continue; }
            if (!$contact || ($contact['opt_in_status'] ?? '') === 'out') { $fail('opted_out', 'They asked not to be messaged.'); continue; }
            $recipient = [];
            if ($kind === 'optin') {
                $o = db_row("SELECT * FROM social_optins WHERE client_id=? AND token=? LIMIT 1", [$cid, (string) $m['recipient_ext']]);
                if (!$o || $o['status'] !== 'active' || ($o['expires_at'] && strtotime((string) $o['expires_at']) < time())) { $fail('optin_ended', 'They stopped receiving offers, or the permission expired.'); continue; }
                $recipient = ['notification_messages_token' => (string) $m['recipient_ext']];
            } elseif (!social_window_open($contact, $channel)) {
                $fail('window_closed', 'More than 24 hours since they last wrote — Meta only allows a message within 24 hours.'); continue;
            }
            if (credits_adjust($cid, -1, 'campaign', (int) $camp['id']) === null) {
                $fail('no_credits', 'No credits left.'); continue;
            }
            $text = campaign_render_text((string) $camp['body_text'], $contact);
            $image = trim((string) ($vm['header_media'] ?? ''));
            $choices = array_values(array_filter((array) ($vm['buttons_social'] ?? []), fn($c) => trim((string) $c) !== ''));
            $msgBody = $choices
                ? ['text' => mb_substr($text, 0, 2000), 'quick_replies' => array_map(fn($c) => ['content_type' => 'text', 'title' => mb_substr((string) $c, 0, 20), 'payload' => 'camp_' . $camp['id'] . ':' . mb_substr((string) $c, 0, 40)], $choices)]
                : ['text' => mb_substr($text, 0, 2000)];
            if ($image !== '') {
                $ri = social_send($client, $contact, $channel, ['attachment' => ['type' => 'image', 'payload' => ['url' => $image, 'is_reusable' => true]]], '', $recipient);
                if (!$ri['ok']) { credits_adjust($cid, 1, 'refund_failed', (int) $camp['id']); $fail((string) $ri['error_code'], (string) $ri['error_title']); continue; }
            }
            $r = social_send($client, $contact, $channel, $msgBody, '', $recipient);
            if (!$r['ok']) {
                credits_adjust($cid, 1, 'refund_failed', (int) $camp['id']);
                $fail((string) $r['error_code'], (string) $r['error_title']);
                continue;
            }
            db_run("UPDATE campaign_messages SET status='sent', wa_message_id=?, sent_at=NOW(), claimed_by=NULL, error_code=NULL, error_title=NULL, updated_at=NOW() WHERE id=?",
                   [(string) $r['wamid'], (int) $m['id']]);
            msg_log($cid, (int) $contact['id'], 'out', $text, ['source' => 'campaign', 'status' => 'sent', 'channel' => $channel, 'ext' => (string) $r['wamid'],
                    'ref' => (int) $m['id']]);
            $sent++;
        }
        $touched[(int) $camp['id']] = true;
    }
    foreach (array_keys($touched) as $id) campaign_refresh_counts($id);
    return [$sent, $failed];
}

/* ───────────────────────── Marketing Messages opt-in ───────────────────────── */

/**
 * Ask someone on Messenger whether they want to receive offers (Meta's opt-in request card).
 * Only possible while their 24-hour window is open.
 */
function social_optin_request(array $client, int $contactId, string $title, string $frequency = 'WEEKLY', string $image = ''): array
{
    if (!client_has_channel($client, 'social_mm')) return ['ok' => false, 'error' => 'Marketing Messages are not part of your plan.'];
    $contact = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$contactId, (int) $client['id']]);
    if (!$contact || empty($contact['fb_psid'])) return ['ok' => false, 'error' => 'This person has not written to you on Messenger.'];
    if (!social_window_open($contact, 'messenger')) return ['ok' => false, 'error' => 'The request can only be sent within 24 hours of their last message.'];
    $title = mb_substr(trim($title) !== '' ? trim($title) : 'Offers and news', 0, 65);
    $freq = in_array(strtoupper($frequency), ['DAILY', 'WEEKLY', 'MONTHLY'], true) ? strtoupper($frequency) : 'WEEKLY';
    $payload = ['template_type' => 'notification_messages', 'title' => $title, 'payload' => 'optin:' . $contactId,
                'notification_messages_frequency' => $freq, 'notification_messages_reoptin' => 'ENABLED'];
    if ($image !== '') $payload['image_url'] = $image;
    $r = social_send($client, $contact, 'messenger', ['attachment' => ['type' => 'template', 'payload' => $payload]]);
    if (!$r['ok']) return ['ok' => false, 'error' => (string) $r['error_title']];
    $page = social_page_for_contact($client, $contact, 'messenger');
    db_run("INSERT INTO social_optins (client_id,contact_id,page_id,topic,frequency,status,asked_at) VALUES (?,?,?,?,?,'asked',NOW())
            ON DUPLICATE KEY UPDATE topic=VALUES(topic), frequency=VALUES(frequency), asked_at=NOW(), status=IF(status='active','active','asked')",
           [(int) $client['id'], $contactId, (string) ($page['page_id'] ?? ''), $title, $freq]);
    msg_log((int) $client['id'], $contactId, 'out', '🔔 Asked to receive offers: ' . $title, ['source' => 'manual', 'status' => 'sent', 'channel' => 'messenger', 'ext' => (string) $r['wamid']]);
    return ['ok' => true];
}

/** Meta's optin webhook: they agreed (token), stopped, or resumed. */
function social_optin_event(array $client, array $page, string $psid, array $optin): string
{
    if (($optin['type'] ?? '') !== 'notification_messages') return 'optin_other';
    if (!client_has_channel($client, 'social_mm')) return 'channel_off';
    [$contact] = social_contact($client, $page, 'messenger', $psid);
    $cid = (int) $client['id']; $ctId = (int) $contact['id'];
    $st = strtoupper((string) ($optin['notification_messages_status'] ?? ''));
    if ($st === 'STOP_NOTIFICATIONS') {
        db_run("UPDATE social_optins SET status='stopped', stopped_at=NOW() WHERE client_id=? AND contact_id=? AND page_id=?", [$cid, $ctId, (string) $page['page_id']]);
        return 'optin_stopped';
    }
    $token = (string) ($optin['notification_messages_token'] ?? '');
    if ($token === '' && $st === 'RESUME_NOTIFICATIONS') {
        db_run("UPDATE social_optins SET status='active', stopped_at=NULL WHERE client_id=? AND contact_id=? AND page_id=? AND token IS NOT NULL", [$cid, $ctId, (string) $page['page_id']]);
        return 'optin_resumed';
    }
    if ($token === '') return 'optin_incomplete';
    $exp = isset($optin['token_expiry_timestamp']) ? date('Y-m-d H:i:s', (int) floor(((int) $optin['token_expiry_timestamp']) / 1000)) : null;
    db_run("INSERT INTO social_optins (client_id,contact_id,page_id,token,topic,frequency,status,opted_in_at,expires_at) VALUES (?,?,?,?,?,?,'active',NOW(),?)
            ON DUPLICATE KEY UPDATE token=VALUES(token), topic=COALESCE(VALUES(topic),topic), frequency=VALUES(frequency), status='active', opted_in_at=NOW(), expires_at=VALUES(expires_at), stopped_at=NULL",
           [$cid, $ctId, (string) $page['page_id'], $token, ($optin['title'] ?? null) ?: null, strtoupper((string) ($optin['notification_messages_frequency'] ?? 'WEEKLY')), $exp]);
    msg_log($cid, $ctId, 'in', '🔔 Agreed to receive offers' . (!empty($optin['title']) ? ': ' . $optin['title'] : ''), ['source' => 'inbound', 'channel' => 'messenger']);
    return 'optin_active';
}
