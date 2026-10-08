<?php
declare(strict_types=1);

/**
 * Facebook Messenger, Instagram Direct, and comments on Facebook and Instagram posts.
 *
 * Built on the same connection as Meta Lead Ads (includes/meta_leads.php): the client connects
 * their Facebook Page once, we keep the Page's token (encrypted), and the Page's webhook —
 * webhook_leads.php — now carries messages and comments as well as lead forms.
 *
 * Everything lands where WhatsApp already lands:
 *   messages   the Inbox (messages.channel = messenger | instagram), the CRM, automations, the AI agent
 *   comments   the Comments page (social_comments), moderation rules, automations
 *
 * Which of this a client has is the platform admin's choice (Admin → client → Channels,
 * client_has_channel()); an event for a channel the client does not have is ignored.
 */

require_once __DIR__ . '/meta_leads.php';      // meta_cfg, meta_http, meta_page_token, crm
require_once __DIR__ . '/inbox.php';           // msg_log, push
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/credits.php';


/** messenger | instagram, as people see them. */
function social_channel_label(string $ch): string
{
    return ['whatsapp' => 'WhatsApp', 'messenger' => 'Messenger', 'instagram' => 'Instagram'][$ch] ?? ucfirst($ch);
}

/* ───────────────────────── Graph ───────────────────────── */

/** A Graph call with a JSON body (the Send API and comment actions). Returns ['ok','http','json','error','code']. */
function social_graph(string $method, string $path, array $body, string $token): array
{
    $url = wa_graph_base() . '/' . ltrim($path, '/');
    $url .= (str_contains($url, '?') ? '&' : '?') . 'access_token=' . rawurlencode($token);
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method];
    if ($method !== 'GET' && $method !== 'DELETE') {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    } elseif ($body) {
        curl_setopt($ch, CURLOPT_URL, $url . '&' . http_build_query($body));
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $http === 0) return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'Could not reach Facebook: ' . $cerr, 'code' => ''];
    $json = json_decode((string) $raw, true);
    $ok = $http >= 200 && $http < 300 && is_array($json) && !isset($json['error']);
    $err = $json['error'] ?? [];
    return ['ok' => $ok, 'http' => $http, 'json' => $json, 'error' => $ok ? '' : (string) ($err['message'] ?? ('HTTP ' . $http)),
            'code' => $ok ? '' : trim((string) ($err['code'] ?? '') . (isset($err['error_subcode']) ? '/' . $err['error_subcode'] : ''), '/')];
}

/* ───────────────────────── Pages ───────────────────────── */

function social_pages(int $clientId): array
{
    try { return db_all("SELECT * FROM meta_pages WHERE client_id=? ORDER BY name", [$clientId]); } catch (Throwable $e) { return []; }
}

/** The connected Page for a Facebook Page id or an Instagram account id, for one client or all. */
function social_pages_for(string $id, string $platform): array
{
    $col = $platform === 'instagram' ? 'ig_user_id' : 'page_id';
    try { return db_all("SELECT * FROM meta_pages WHERE $col=?", [$id]); } catch (Throwable $e) { return []; }
}

/** Tell Meta which fields to send for this Page — one call, the union of everything it is on for. */
function social_page_apply(array $page): array
{
    $page = db_row("SELECT * FROM meta_pages WHERE id=?", [(int) $page['id']]) ?: $page;
    $fields = meta_page_fields($page);
    $r = $fields
        ? meta_http('POST', $page['page_id'] . '/subscribed_apps', ['subscribed_fields' => implode(',', $fields), 'access_token' => meta_page_token($page)])
        : meta_http('DELETE', $page['page_id'] . '/subscribed_apps', ['access_token' => meta_page_token($page)]);
    db_run("UPDATE meta_pages SET last_error=? WHERE id=?", [$r['ok'] ? null : mb_substr($r['error'], 0, 255), (int) $page['id']]);
    return $r;
}

/** Find the Instagram professional account linked to this Page, and remember it. */
function social_page_link_ig(array $page): array
{
    $r = meta_http('GET', $page['page_id'], ['fields' => 'instagram_business_account{id,username}', 'access_token' => meta_page_token($page)]);
    if (!$r['ok']) return $r;
    $ig = (array) ($r['json']['instagram_business_account'] ?? []);
    db_run("UPDATE meta_pages SET ig_user_id=?, ig_username=? WHERE id=?",
           [($ig['id'] ?? '') !== '' ? (string) $ig['id'] : null, ($ig['username'] ?? '') !== '' ? (string) $ig['username'] : null, (int) $page['id']]);
    return ['ok' => true, 'ig' => $ig];
}

/** Switch one use of a Page on or off: msg_on, ig_msg_on, comments_on, ig_comments_on. */
function social_page_set(array $page, string $what, bool $on): array
{
    if (!in_array($what, ['msg_on', 'ig_msg_on', 'comments_on', 'ig_comments_on'], true)) return ['ok' => false, 'error' => 'Unknown switch.'];
    if ($on && str_starts_with($what, 'ig_') && empty($page['ig_user_id'])) {
        $l = social_page_link_ig($page);
        $page = db_row("SELECT * FROM meta_pages WHERE id=?", [(int) $page['id']]) ?: $page;
        if (empty($page['ig_user_id'])) return ['ok' => false, 'error' => $l['ok'] ?? false
            ? 'This Page has no Instagram professional account linked. Link one in the Instagram app (Settings → Account type → Professional, then connect the Facebook Page).'
            : ($l['error'] ?? 'Could not read the Page.')];
    }
    db_run("UPDATE meta_pages SET `$what`=? WHERE id=?", [$on ? 1 : 0, (int) $page['id']]);
    return social_page_apply($page);
}

/* ───────────────────────── people ───────────────────────── */

/**
 * The contact for someone writing on Messenger or Instagram, made the first time they write.
 * Their name and picture come from Meta; they have no phone number until they give one.
 *
 * @return array{0:array,1:bool} [contact row, created now]
 */
function social_contact(array $client, array $page, string $platform, string $sid, string $nameHint = ''): array
{
    $cid = (int) $client['id'];
    $col = $platform === 'instagram' ? 'ig_sid' : 'fb_psid';
    $c = db_row("SELECT * FROM contacts WHERE client_id=? AND $col=?", [$cid, $sid]);
    if ($c) return [$c, false];

    $name = $nameHint; $avatar = null; $username = null;
    $fields = $platform === 'instagram' ? 'name,username,profile_pic' : 'first_name,last_name,profile_pic';
    $p = social_graph('GET', $sid, ['fields' => $fields], meta_page_token($page));
    if ($p['ok']) {
        $j = (array) $p['json'];
        $name = trim((string) ($j['name'] ?? trim(($j['first_name'] ?? '') . ' ' . ($j['last_name'] ?? ''))) ?: $name);
        $avatar = (string) ($j['profile_pic'] ?? '') ?: null;
        $username = (string) ($j['username'] ?? '') ?: null;
    }
    if ($name === '' && $username) $name = '@' . $username;
    try {
        $id = db_insert("INSERT INTO contacts (client_id,phone_e164,$col,ig_username,social_avatar,social_page_id,name,opt_in_status,source,platform,created_at)
                         VALUES (?,NULL,?,?,?,?,?,'in',?,?,NOW())",
                        [$cid, $sid, $username, $avatar, (string) $page['page_id'], mb_substr($name, 0, 160),
                         $platform === 'instagram' ? 'instagram_dm' : 'messenger', $platform === 'instagram' ? 'instagram' : 'messenger']);
    } catch (Throwable $e) {
        // Two deliveries of the first message at once: the other one made it.
        $c = db_row("SELECT * FROM contacts WHERE client_id=? AND $col=?", [$cid, $sid]);
        if ($c) return [$c, false];
        throw $e;
    }
    if (db_has_column('contacts', 'code') && function_exists('crm_code')) db_run("UPDATE contacts SET code=COALESCE(code, ?) WHERE id=?", [crm_code($id), $id]);
    return [db_row("SELECT * FROM contacts WHERE id=?", [$id]), true];
}

/** The 24-hour window on Messenger / Instagram: they wrote within the last day. */
function social_window_open(array $contact, string $platform): bool
{
    $last = $contact[$platform === 'instagram' ? 'ig_last_in_at' : 'fb_last_in_at'] ?? null;
    return $last && strtotime((string) $last) > time() - 86400;
}

/** The ways this person can be reached: [whatsapp => phone, messenger => psid, instagram => igsid]. */
function social_reach(array $contact): array
{
    return array_filter(['whatsapp' => (string) ($contact['phone_e164'] ?? ''), 'messenger' => (string) ($contact['fb_psid'] ?? ''),
                         'instagram' => (string) ($contact['ig_sid'] ?? '')], 'strlen');
}

/** The Page a contact talks to (the one they last wrote to), for sending. */
function social_page_for_contact(array $client, array $contact, string $platform): ?array
{
    $pid = (string) ($contact['social_page_id'] ?? '');
    $pages = social_pages((int) $client['id']);
    foreach ($pages as $p) if ($pid !== '' && $p['page_id'] === $pid && ($platform !== 'instagram' || $p['ig_user_id'])) return $p;
    foreach ($pages as $p) if ($platform === 'instagram' ? (int) $p['ig_msg_on'] : (int) $p['msg_on']) return $p;
    return null;
}

/* ───────────────────────── sending ───────────────────────── */

/**
 * Send one message on Messenger or Instagram. $message is the Send API "message" object.
 * Same result shape as the WhatsApp senders, so every caller (Inbox, automations, campaigns)
 * handles it the same way: ['ok','wamid','error_code','error_title'].
 *
 * $tag: a Messenger message tag for outside the 24-hour window (HUMAN_AGENT, when the app has it).
 */
function social_send(array $client, array $contact, string $platform, array $message, string $tag = '', array $recipient = []): array
{
    $sid = (string) ($contact[$platform === 'instagram' ? 'ig_sid' : 'fb_psid'] ?? '');
    if (!$recipient && $sid === '') return ['ok' => false, 'wamid' => null, 'error_code' => 'no_id', 'error_title' => 'This person has not written to you on ' . social_channel_label($platform) . '.'];
    $page = social_page_for_contact($client, $contact, $platform);
    if (!$page) return ['ok' => false, 'wamid' => null, 'error_code' => 'no_page', 'error_title' => 'No Facebook Page is connected for ' . social_channel_label($platform) . '.'];
    $body = ['recipient' => $recipient ?: ['id' => $sid], 'message' => $message];
    if ($tag !== '') $body += ['messaging_type' => 'MESSAGE_TAG', 'tag' => $tag];
    elseif (!isset($recipient['notification_messages_token'])) $body['messaging_type'] = 'RESPONSE';
    $r = social_graph('POST', 'me/messages', $body, meta_page_token($page));
    return ['ok' => $r['ok'], 'wamid' => $r['ok'] ? (string) ($r['json']['message_id'] ?? '') : null,
            'error_code' => $r['code'], 'error_title' => $r['ok'] ? null : social_explain_error($r['error'], $r['code'])];
}

function social_send_text(array $client, array $contact, string $platform, string $text): array
{
    return social_send($client, $contact, $platform, ['text' => mb_substr($text, 0, 2000)]);
}

function social_send_image(array $client, array $contact, string $platform, string $link, string $caption = ''): array
{
    $r = social_send($client, $contact, $platform, ['attachment' => ['type' => 'image', 'payload' => ['url' => $link, 'is_reusable' => true]]]);
    // Messenger and Instagram have no captions: the words follow as their own message.
    if ($r['ok'] && trim($caption) !== '') social_send_text($client, $contact, $platform, $caption);
    return $r;
}

/** Choices as quick replies (up to 13, titles up to 20 characters) — Messenger's and Instagram's buttons. */
function social_send_choices(array $client, array $contact, string $platform, string $text, array $choices): array
{
    $qr = [];
    foreach (array_slice($choices, 0, 13) as $c) {
        $qr[] = ['content_type' => 'text', 'title' => mb_substr((string) $c['title'], 0, 20), 'payload' => (string) $c['id']];
    }
    return social_send($client, $contact, $platform, ['text' => mb_substr($text !== '' ? $text : 'Choose:', 0, 2000), 'quick_replies' => $qr]);
}

/** Meta's error, in words a person can act on. */
function social_explain_error(string $err, string $code): string
{
    if (str_contains($code, '2018278') || stripos($err, 'outside of allowed window') !== false || stripos($err, '24 hour') !== false) {
        return 'More than 24 hours since they last wrote — Meta only allows a reply within 24 hours.';
    }
    if (str_starts_with($code, '551') || stripos($err, 'not available') !== false) return 'This person can\'t receive messages from the Page right now.';
    if (str_starts_with($code, '190') || stripos($err, 'access token') !== false) return 'The Page connection expired — reconnect Facebook in CRM → Facebook & Instagram.';
    if (str_starts_with($code, '10') || str_starts_with($code, '200')) return 'The Page has not allowed this yet (Meta permission): ' . $err;
    return $err !== '' ? $err : 'Facebook refused the message.';
}

/* ───────────────────────── inbound ───────────────────────── */

/**
 * One messaging event from the Page webhook (Messenger) or the Instagram webhook.
 * Returns what happened, for the webhook's reply and the tests.
 */
function social_inbound_event(array $page, string $platform, array $ev): string
{
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $page['client_id']]);
    if (!$client || ($client['status'] ?? '') !== 'active') return 'inactive';
    if (!client_has_channel($client, $platform)) return 'channel_off';
    if (!(int) $page[$platform === 'instagram' ? 'ig_msg_on' : 'msg_on']) return 'page_off';

    $msg = (array) ($ev['message'] ?? []);
    $own = $platform === 'instagram' ? (string) $page['ig_user_id'] : (string) $page['page_id'];
    $sender = (string) ($ev['sender']['id'] ?? '');
    if ($sender === '' || $sender === $own || !empty($msg['is_echo'])) return 'echo';     // our own message coming back

    // A read receipt: everything we sent before it was read.
    if (isset($ev['read'])) {
        $c = db_row("SELECT id FROM contacts WHERE client_id=? AND " . ($platform === 'instagram' ? 'ig_sid' : 'fb_psid') . "=?", [(int) $client['id'], $sender]);
        if ($c) {
            db_run("UPDATE messages SET status='read' WHERE contact_id=? AND channel=? AND direction='out' AND status IN ('sent','delivered')", [(int) $c['id'], $platform]);
            // …and the campaigns they were in.
            $camps = db_all("SELECT DISTINCT m.campaign_id FROM campaign_messages m JOIN campaigns k ON k.id=m.campaign_id
                              WHERE m.contact_id=? AND k.channel=? AND m.status IN ('sent','delivered')", [(int) $c['id'], $platform]);
            if ($camps) {
                db_run("UPDATE campaign_messages m JOIN campaigns k ON k.id=m.campaign_id SET m.status='read', m.read_at=NOW(), m.updated_at=NOW()
                         WHERE m.contact_id=? AND k.channel=? AND m.status IN ('sent','delivered')", [(int) $c['id'], $platform]);
                if (function_exists('campaign_refresh_counts')) foreach ($camps as $k) campaign_refresh_counts((int) $k['campaign_id']);
            }
        }
        return 'read';
    }
    // Agreed (or stopped agreeing) to receive offers — Marketing Messages.
    if (isset($ev['optin'])) return function_exists('social_optin_event') ? social_optin_event($client, $page, $sender, (array) $ev['optin']) : 'optin';

    $mid = (string) ($msg['mid'] ?? ($ev['postback']['mid'] ?? ''));
    if ($mid !== '' && db_val("SELECT 1 FROM messages WHERE client_id=? AND ext_id=? LIMIT 1", [(int) $client['id'], $mid])) return 'duplicate';

    [$contact, $isNew] = social_contact($client, $page, $platform, $sender);
    $col = $platform === 'instagram' ? 'ig_last_in_at' : 'fb_last_in_at';
    db_run("UPDATE contacts SET $col=NOW(), social_page_id=? WHERE id=?", [(string) $page['page_id'], (int) $contact['id']]);
    $contact[$col] = date('Y-m-d H:i:s');

    // What they sent: words, a tapped quick reply or button, a picture or file, a story reply.
    $text = trim((string) ($msg['text'] ?? ''));
    $payload = (string) ($msg['quick_reply']['payload'] ?? ($ev['postback']['payload'] ?? ''));
    if ($text === '' && isset($ev['postback'])) $text = trim((string) ($ev['postback']['title'] ?? ''));
    $type = 'text'; $mediaRef = null; $mediaMime = null;
    foreach ((array) ($msg['attachments'] ?? []) as $a) {
        $t = (string) ($a['type'] ?? '');
        $url = (string) ($a['payload']['url'] ?? '');
        if (in_array($t, ['image', 'video', 'audio', 'file'], true) && $url !== '') {
            $type = $t === 'file' ? 'document' : $t; $mediaRef = 'url:' . $url;
            $mediaMime = ['image' => 'image/jpeg', 'video' => 'video/mp4', 'audio' => 'audio/mp4'][$t] ?? null;
            break;
        }
        if (in_array($t, ['story_mention', 'share', 'ig_reel', 'reel'], true)) { $type = 'text'; if ($text === '') $text = $t === 'story_mention' ? '[mentioned you in their story]' : '[shared a post]'; }
    }
    $trigger = 'message';
    if (!empty($msg['reply_to']['story'])) { $trigger = 'story_reply'; if ($text === '') $text = '[replied to your story]'; }
    foreach ((array) ($msg['attachments'] ?? []) as $a) if (($a['type'] ?? '') === 'story_mention') $trigger = 'story_mention';
    $referral = (array) ($msg['referral'] ?? ($ev['referral'] ?? ($ev['postback']['referral'] ?? [])));
    if ($referral && ($referral['source'] ?? '') === 'ADS') $trigger = 'ad';

    $logBody = $text !== '' ? $text : '[' . $type . ']';
    msg_log((int) $client['id'], (int) $contact['id'], 'in', $logBody, ['type' => $type, 'source' => 'inbound', 'media_ref' => $mediaRef,
            'media_mime' => $mediaMime, 'channel' => $platform, 'ext' => $mid]);
    if (function_exists('push_queue_client')) push_queue_client((int) $client['id']);

    // Into the CRM: by itself when the account wants that, otherwise when someone adds them from the Inbox.
    if ($isNew) social_maybe_lead($client, $contact, $platform);
    if ($referral && function_exists('crm_set_origin')) {
        crm_set_origin((int) $contact['id'], ['platform' => $platform, 'meta_ad_id' => (string) ($referral['ad_id'] ?? '')]);
    }

    // STOP opt-out, the same words as WhatsApp.
    if (preg_match('/^(STOP|UNSUBSCRIBE|إلغاء|الغاء|توقف)\b/u', mb_strtoupper($text))) {
        db_run("UPDATE contacts SET opt_in_status='out', opted_out_at=NOW() WHERE id=?", [(int) $contact['id']]);
        return 'opted_out';
    }
    if (function_exists('automation_handle_social')) {
        automation_handle_social($client, db_row("SELECT * FROM contacts WHERE id=?", [(int) $contact['id']]), $platform, [
            'text' => $text, 'button_id' => $payload, 'trigger' => $trigger, 'referral' => $referral, 'page_id' => (string) $page['page_id']]);
    }
    return $isNew ? 'new_contact' : 'logged';
}

/** A new Messenger / Instagram person becomes a lead, when the account has switched that on. */
function social_maybe_lead(array $client, array $contact, string $platform): bool
{
    if (!function_exists('crm_enabled') || !crm_enabled($client)) return false;
    $s = crm_settings((int) $client['id']);
    if (!(int) ($s['social_auto_lead'] ?? 0)) return false;
    return social_add_lead($client, (int) $contact['id'], $platform, null);
}

/** Put a Messenger / Instagram person in the pipeline (automatically, or from the Inbox button). */
function social_add_lead(array $client, int $contactId, string $platform, ?int $by, $owner = 'auto'): bool
{
    $s = crm_settings((int) $client['id']);
    $stage = (int) ($s['social_lead_stage'] ?? 0) ?: null;
    try {
        return crm_add_lead($client, $contactId, $platform === 'instagram' ? 'instagram_dm' : 'messenger', $owner, $stage, $by);
    } catch (Throwable $e) {
        error_log('social_add_lead: ' . $e->getMessage());
        return false;
    }
}

/* ───────────────────────── the Inbox ───────────────────────── */

/** A reply typed in the Inbox, out on Messenger or Instagram. Same answer shape as inbox_send(). */
function inbox_send_social(array $client, int $contactId, string $body, string $platform): array
{
    $body = trim($body);
    if ($body === '') return ['ok' => false, 'error' => 'Type a message first.'];
    if (!client_has_channel($client, $platform)) return ['ok' => false, 'error' => social_channel_label($platform) . ' is not part of your plan.'];
    $contact = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$contactId, (int) $client['id']]);
    if (!$contact) return ['ok' => false, 'error' => 'Contact not found.'];
    if (!social_window_open($contact, $platform)) {
        return ['ok' => false, 'error' => 'More than 24 hours since they last wrote on ' . social_channel_label($platform) . ' — Meta only allows a reply within 24 hours of their message.'];
    }
    if (credits_adjust((int) $client['id'], -1, 'inbox', null) === null) return ['ok' => false, 'error' => 'No credits left.'];
    $res = social_send_text($client, $contact, $platform, $body);
    if (!$res['ok']) credits_adjust((int) $client['id'], 1, 'inbox_refund', null);
    $who = function_exists('sending_user') ? sending_user() : [];
    $id = msg_log((int) $client['id'], $contactId, 'out', $body, [
        'source' => 'manual', 'status' => $res['ok'] ? 'sent' : 'failed', 'error' => $res['error_title'] ?? null,
        'error_code' => (string) ($res['error_code'] ?? ''), 'sent_by' => (int) ($who['id'] ?? 0),
        'channel' => $platform, 'ext' => (string) ($res['wamid'] ?? '')]);
    if ($res['ok']) inbox_take_over((int) $client['id'], $contactId);
    return ['ok' => $res['ok'], 'error' => $res['ok'] ? '' : (string) $res['error_title'], 'id' => $id];
}

/**
 * Give a Messenger / Instagram person their phone number. When a contact with that number is
 * already in the account (they also wrote on WhatsApp, or were imported), the two become one:
 * the conversation, the lead and its history all end up on that contact.
 *
 * @return array{ok:bool, error?:string, contact_id?:int, merged?:bool}
 */
function social_attach_phone(array $client, int $contactId, string $raw): array
{
    $cid = (int) $client['id'];
    $c = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid]);
    if (!$c) return ['ok' => false, 'error' => 'Contact not found.'];
    $phone = normalize_phone($raw, (string) ($client['default_country'] ?? ''));
    if ($phone === '' || strlen($phone) < 8) return ['ok' => false, 'error' => 'That does not look like a phone number. Include the country code, e.g. +20 100 123 4567.'];
    if ((string) ($c['phone_e164'] ?? '') === $phone) return ['ok' => true, 'contact_id' => $contactId, 'merged' => false];
    if (!empty($c['phone_e164'])) return ['ok' => false, 'error' => 'This contact already has a number.'];

    $other = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=? AND id<>?", [$cid, $phone, $contactId]);
    if (!$other) {
        db_run("UPDATE contacts SET phone_e164=? WHERE id=?", [$phone, $contactId]);
        return ['ok' => true, 'contact_id' => $contactId, 'merged' => false];
    }
    // Keep the existing contact; carry the Messenger / Instagram identity over to it.
    $carry = [];
    foreach (['fb_psid', 'ig_sid', 'ig_username', 'social_avatar', 'fb_last_in_at', 'ig_last_in_at', 'social_page_id'] as $k) {
        if (!empty($c[$k]) && empty($other[$k])) $carry[$k] = $c[$k];
    }
    db_run("UPDATE contacts SET fb_psid=NULL, ig_sid=NULL WHERE id=?", [$contactId]);   // they are unique per account
    if (!function_exists('crm_merge') || !crm_merge($client, (int) $other['id'], $contactId, function_exists('crm_actor_id') ? crm_actor_id() : null)) {
        db_run("UPDATE contacts SET fb_psid=?, ig_sid=? WHERE id=?", [$c['fb_psid'], $c['ig_sid'], $contactId]);
        return ['ok' => false, 'error' => 'Could not join this person to the contact with that number.'];
    }
    if ($carry) {
        db_run("UPDATE contacts SET " . implode(',', array_map(fn($k) => "`$k`=?", array_keys($carry))) . " WHERE id=?",
               array_merge(array_values($carry), [(int) $other['id']]));
    }
    return ['ok' => true, 'contact_id' => (int) $other['id'], 'merged' => true];
}

/* ───────────────────────── comments ───────────────────────── */

/** A post's words, link and picture — read once per post, then taken from the comments already stored. */
function social_post_info(array $page, string $platform, string $postId): array
{
    $have = db_row("SELECT post_link, post_text, post_image FROM social_comments WHERE client_id=? AND post_id=? AND post_link IS NOT NULL LIMIT 1",
                   [(int) $page['client_id'], $postId]);
    if ($have) return $have;
    $r = $platform === 'ig'
        ? social_graph('GET', $postId, ['fields' => 'caption,permalink,media_url,thumbnail_url'], meta_page_token($page))
        : social_graph('GET', $postId, ['fields' => 'message,permalink_url,full_picture'], meta_page_token($page));
    $j = (array) ($r['json'] ?? []);
    return ['post_link' => (string) ($j['permalink'] ?? ($j['permalink_url'] ?? '')) ?: null,
            'post_text' => mb_substr((string) ($j['caption'] ?? ($j['message'] ?? '')), 0, 600) ?: null,
            'post_image' => (string) ($j['thumbnail_url'] ?? ($j['media_url'] ?? ($j['full_picture'] ?? ''))) ?: null];
}

/**
 * One comment event from the Page (feed) or Instagram (comments) webhook: kept, moderated by the
 * account's rules, answered by its reply rules, and handed to automations.
 */
function social_comment_event(array $page, string $platform, array $v): string
{
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $page['client_id']]);
    if (!$client || ($client['status'] ?? '') !== 'active') return 'inactive';
    if (!client_has_channel($client, $platform === 'ig' ? 'ig_comments' : 'fb_comments')) return 'channel_off';
    if (!(int) $page[$platform === 'ig' ? 'ig_comments_on' : 'comments_on']) return 'page_off';

    if ($platform === 'fb') {
        if (($v['item'] ?? '') !== 'comment') return 'not_a_comment';
        $cid = (string) ($v['comment_id'] ?? ''); $verb = (string) ($v['verb'] ?? 'add');
        $postId = (string) ($v['post_id'] ?? ''); $text = (string) ($v['message'] ?? '');
        $from = (array) ($v['from'] ?? []); $parent = (string) ($v['parent_id'] ?? '');
        $fromPage = (string) ($from['id'] ?? '') === (string) $page['page_id'];
    } else {
        $cid = (string) ($v['id'] ?? ''); $verb = 'add';
        $postId = (string) ($v['media']['id'] ?? ''); $text = (string) ($v['text'] ?? '');
        $from = ['id' => (string) ($v['from']['id'] ?? ''), 'name' => (string) ($v['from']['username'] ?? '')];
        $parent = (string) ($v['parent_id'] ?? '');
        $fromPage = (string) $from['id'] === (string) $page['ig_user_id'];
    }
    if ($cid === '' || $postId === '') return 'incomplete';
    $clientId = (int) $client['id'];
    $row = db_row("SELECT * FROM social_comments WHERE client_id=? AND comment_id=?", [$clientId, $cid]);
    if ($verb === 'remove') { if ($row) db_run("UPDATE social_comments SET status='deleted', updated_at=NOW() WHERE id=?", [(int) $row['id']]); return 'removed'; }
    if ($verb === 'edited' && $row) { db_run("UPDATE social_comments SET body=?, updated_at=NOW() WHERE id=?", [$text, (int) $row['id']]); return 'edited'; }
    if ($row) return 'duplicate';

    // Who wrote it: a contact, so their comments, messages and lead are one person.
    $contactId = null;
    if (!$fromPage && ($from['id'] ?? '') !== '') {
        [$c] = social_contact($client, $page, $platform === 'ig' ? 'instagram' : 'messenger', (string) $from['id'], (string) ($from['name'] ?? ''));
        $contactId = (int) $c['id'];
        if ($platform === 'ig' && !empty($from['name']) && empty($c['ig_username'])) db_run("UPDATE contacts SET ig_username=? WHERE id=?", [(string) $from['name'], $contactId]);
    }
    $post = social_post_info($page, $platform, $postId);
    try {
        $id = db_insert("INSERT INTO social_comments (client_id,page_id,platform,post_id,post_link,post_text,post_image,comment_id,parent_id,author_id,author_name,contact_id,body,from_page,created_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
                        [$clientId, (string) $page['page_id'], $platform, $postId, $post['post_link'], $post['post_text'], $post['post_image'], $cid,
                         $parent !== '' ? $parent : null, (string) ($from['id'] ?? '') ?: null, mb_substr((string) ($from['name'] ?? ''), 0, 160) ?: null,
                         $contactId, $text, $fromPage ? 1 : 0]);
    } catch (Throwable $e) { return 'duplicate'; }
    if ($fromPage) return 'own';
    $cm = db_row("SELECT * FROM social_comments WHERE id=?", [$id]);

    if (social_moderate($client, $cm)) return 'moderated';
    social_reply_rules($client, $cm);
    $cm = db_row("SELECT * FROM social_comments WHERE id=?", [$id]);
    if ($contactId && function_exists('automation_handle_comment')) {
        automation_handle_comment($client, $cm, db_row("SELECT * FROM contacts WHERE id=?", [$contactId]));
    }
    if (function_exists('push_queue_client')) push_queue_client($clientId);
    return 'stored';
}

/** Does a rule's match apply to this text? */
function social_rule_matches(array $client, array $rule, string $text): ?string
{
    $t = mb_strtolower($text);
    switch ($rule['match_kind']) {
        case 'any':   return 'any';
        case 'phone': return preg_match('/(\+?\d[\d\s\-().]{7,}\d)/u', $text) ? 'phone' : null;
        case 'link':  return preg_match('~(https?://|www\.|\b[\w-]+\.(com|net|org|io|me|ly|co)\b)~i', $text) ? 'link' : null;
        case 'ai':
            if (!function_exists('ai_classify')) return null;
            $labels = [['label' => 'spam', 'description' => 'spam, scams, advertising someone else, or abuse/insults'], ['label' => 'fine', 'description' => 'a normal comment or question']];
            return ai_classify($client, [['role' => 'user', 'text' => $text]], $labels) === 0 ? 'ai:spam' : null;
        default:
            foreach (array_filter(array_map(fn($k) => mb_strtolower(trim($k)), preg_split('/[\n,]+/', (string) $rule['keywords']))) as $kw) {
                if ($kw !== '' && str_contains($t, $kw)) return 'keyword:' . mb_substr($kw, 0, 30);
            }
            return null;
    }
}

function social_rule_applies(array $rule, array $cm): bool
{
    if ($rule['platform'] !== 'both' && $rule['platform'] !== $cm['platform']) return false;
    $posts = array_filter(array_map('trim', explode(',', (string) ($rule['posts'] ?? ''))));
    return !$posts || in_array((string) $cm['post_id'], $posts, true);
}

/** Moderation rules, top to bottom: the first that matches hides or deletes the comment. True when it acted. */
function social_moderate(array $client, array $cm): bool
{
    foreach (db_all("SELECT * FROM social_rules WHERE client_id=? AND kind='moderate' AND active=1 ORDER BY sort, id", [(int) $client['id']]) as $rule) {
        if (!social_rule_applies($rule, $cm)) continue;
        $why = social_rule_matches($client, $rule, (string) $cm['body']);
        if ($why === null) continue;
        $r = social_comment_action($client, $cm, $rule['action'] === 'delete' ? 'delete' : 'hide', 'rule:' . $rule['id']);
        db_run("UPDATE social_comments SET flagged=? WHERE id=?", [$why, (int) $cm['id']]);
        db_run("UPDATE social_rules SET hits=hits+1 WHERE id=?", [(int) $rule['id']]);
        return $r['ok'];
    }
    return false;
}

/** Simple auto-reply rules: keywords → a public reply and/or a private message. */
function social_reply_rules(array $client, array $cm): void
{
    foreach (db_all("SELECT * FROM social_rules WHERE client_id=? AND kind='reply' AND active=1 ORDER BY sort, id", [(int) $client['id']]) as $rule) {
        if (!social_rule_applies($rule, $cm) || social_rule_matches($client, $rule, (string) $cm['body']) === null) continue;
        $c = $cm['contact_id'] ? (db_row("SELECT * FROM contacts WHERE id=?", [(int) $cm['contact_id']]) ?: []) : [];
        $render = fn($t) => function_exists('auto_render') ? auto_render((string) $t, $c ?: ['name' => (string) $cm['author_name']], ['fields' => []]) : (string) $t;
        if (trim((string) $rule['reply_text']) !== '') social_comment_reply($client, $cm, $render($rule['reply_text']), 'public', 'rule:' . $rule['id']);
        if (trim((string) $rule['dm_text']) !== '')    social_comment_reply($client, $cm, $render($rule['dm_text']), 'private', 'rule:' . $rule['id']);
        db_run("UPDATE social_rules SET hits=hits+1 WHERE id=?", [(int) $rule['id']]);
        return;      // one reply rule per comment
    }
}

/**
 * Answer a comment: 'public' under it, or 'private' in their inbox (Meta allows one private reply per
 * comment, within 7 days of it). A private reply is also logged in their Inbox thread.
 */
function social_comment_reply(array $client, array $cm, string $text, string $how, string $by = ''): array
{
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'error' => 'Write the reply first.'];
    $page = db_row("SELECT * FROM meta_pages WHERE client_id=? AND page_id=?", [(int) $client['id'], (string) $cm['page_id']]);
    if (!$page) return ['ok' => false, 'error' => 'The Page is no longer connected.'];
    $tok = meta_page_token($page);
    if ($how === 'private') {
        if ((int) $cm['replied_private']) return ['ok' => false, 'error' => 'A private reply was already sent for this comment — Meta allows one.'];
        if (strtotime((string) $cm['created_at']) < time() - 7 * 86400) return ['ok' => false, 'error' => 'Private replies are only possible within 7 days of the comment.'];
        $r = social_graph('POST', 'me/messages', ['recipient' => ['comment_id' => (string) $cm['comment_id']], 'message' => ['text' => mb_substr($text, 0, 2000)]], $tok);
        if (!$r['ok']) return ['ok' => false, 'error' => social_explain_error($r['error'], $r['code'])];
        db_run("UPDATE social_comments SET replied_private=1, acted_by=COALESCE(acted_by, ?), updated_at=NOW() WHERE id=?", [$by ?: null, (int) $cm['id']]);
        if ($cm['contact_id']) {
            msg_log((int) $client['id'], (int) $cm['contact_id'], 'out', $text, ['source' => str_starts_with($by, 'user:') ? 'manual' : 'automation',
                    'status' => 'sent', 'channel' => $cm['platform'] === 'ig' ? 'instagram' : 'messenger', 'ext' => (string) ($r['json']['message_id'] ?? '')]);
        }
        return ['ok' => true];
    }
    $r = social_graph('POST', $cm['comment_id'] . ($cm['platform'] === 'ig' ? '/replies' : '/comments'), ['message' => mb_substr($text, 0, 2000)], $tok);
    if (!$r['ok']) return ['ok' => false, 'error' => social_explain_error($r['error'], $r['code'])];
    db_run("UPDATE social_comments SET replied_public=1, acted_by=COALESCE(acted_by, ?), updated_at=NOW() WHERE id=?", [$by ?: null, (int) $cm['id']]);
    // Our reply, shown under theirs straight away (the webhook echo, when it comes, is a duplicate).
    try {
        db_insert("INSERT INTO social_comments (client_id,page_id,platform,post_id,post_link,post_text,post_image,comment_id,parent_id,author_name,body,from_page,created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,1,NOW())",
                  [(int) $client['id'], $cm['page_id'], $cm['platform'], $cm['post_id'], $cm['post_link'], $cm['post_text'], $cm['post_image'],
                   (string) ($r['json']['id'] ?? ('own_' . bin2hex(random_bytes(6)))), $cm['comment_id'], (string) ($page['name'] ?? ''), $text]);
    } catch (Throwable $e) {}
    return ['ok' => true];
}

/** Hide, show again, or delete a comment on Facebook / Instagram. */
function social_comment_action(array $client, array $cm, string $what, string $by = ''): array
{
    $page = db_row("SELECT * FROM meta_pages WHERE client_id=? AND page_id=?", [(int) $client['id'], (string) $cm['page_id']]);
    if (!$page) return ['ok' => false, 'error' => 'The Page is no longer connected.'];
    $tok = meta_page_token($page);
    $r = match ($what) {
        'hide', 'unhide' => social_graph('POST', (string) $cm['comment_id'], $cm['platform'] === 'ig' ? ['hide' => $what === 'hide'] : ['is_hidden' => $what === 'hide'], $tok),
        'delete'         => social_graph('DELETE', (string) $cm['comment_id'], [], $tok),
        default          => ['ok' => false, 'error' => 'Unknown action.', 'code' => ''],
    };
    if (!$r['ok']) return ['ok' => false, 'error' => social_explain_error((string) $r['error'], (string) ($r['code'] ?? ''))];
    db_run("UPDATE social_comments SET status=?, acted_by=?, updated_at=NOW() WHERE id=?",
           [['hide' => 'hidden', 'unhide' => 'visible', 'delete' => 'deleted'][$what], $by ?: null, (int) $cm['id']]);
    return ['ok' => true];
}
