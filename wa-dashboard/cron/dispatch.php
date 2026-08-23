<?php
declare(strict_types=1);

/**
 * Unified worker — run once per minute:
 *
 *   * * * * * php /path/wa-dashboard/cron/dispatch.php
 *
 * Or over HTTP: https://app.example.com/wa-dashboard/cron/dispatch.php?token=<webhook_verify_token>
 *
 * Does three things under a single-runner MySQL lock:
 *   1. Sends campaign messages IN PARALLEL (fast bulk).
 *   2. Resumes automation timer-waits.
 *   3. Imports Google-Sheet leads for qualifiers.
 * This is the ONLY cron you need — it also runs the automation work, so the
 * separate cron/automation.php is optional.
 */

require __DIR__ . '/../includes/config_loader.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/crypto.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/whatsapp.php';
require __DIR__ . '/../includes/credits.php';
require __DIR__ . '/../includes/campaign.php';
require __DIR__ . '/../includes/notify.php';
require __DIR__ . '/../includes/ai.php';
require __DIR__ . '/../includes/automation.php';
require_once __DIR__ . '/../includes/push.php';

if (PHP_SAPI !== 'cli') {
    if (!hash_equals((string) config('webhook_verify_token'), (string) ($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=UTF-8');
    // Keep running even if the triggering (fire-and-forget) request disconnects,
    // so an instant "send now" kick from campaign creation completes the batch.
    ignore_user_abort(true);
    @set_time_limit(0);
}

function out(string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . "\n"; }

$pdo = db();
if ((int) $pdo->query("SELECT GET_LOCK('wa_dispatch', 0)")->fetchColumn() !== 1) {
    out('Another worker is running — exiting.');
    exit;
}

try {
    /* ══ CAMPAIGNS (parallel) ══ */
    // Reclaim messages orphaned by a worker that died mid-run (claimed but never sent).
    db_run("UPDATE campaign_messages SET status='queued', updated_at=NOW()
              WHERE status='sending' AND updated_at < (NOW() - INTERVAL 5 MINUTE)");

    $promoted = db_run(
        "UPDATE campaigns SET status='sending', started_at=COALESCE(started_at,NOW())
          WHERE status='scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()"
    );
    if ($promoted) out("Promoted {$promoted} scheduled campaign(s).");

    $perClientCap = (int) config('send_batch_per_run', 300);
    $globalCap    = (int) config('send_batch_global', 1000);
    $parallel     = max(1, (int) config('send_parallel', 30));
    $sentTotal = 0; $failedTotal = 0;
    $touchedCampaigns = [];
    $noCreditClients  = [];

    $clients = db_all(
        "SELECT DISTINCT cl.*
           FROM clients cl
           JOIN campaigns c        ON c.client_id = cl.id AND c.status = 'sending'
           JOIN campaign_messages m ON m.campaign_id = c.id AND m.status = 'queued'
          WHERE cl.status = 'active'"
    );

    foreach ($clients as $client) {
        if ($sentTotal + $failedTotal >= $globalCap) break;
        $cid = (int) $client['id'];
        $limit = max(0, min($perClientCap, $globalCap - ($sentTotal + $failedTotal)));
        if ($limit === 0) break;

        // Personal numbers send in paced slots. slot_budget() is 0 while the client is in
        // its cooldown, and the same budget is shared with qualifier/automation sends below
        // so the three modules can't each burn a full slot.
        $budget = slot_budget($client);
        if ($budget <= 0) { out("Client {$cid}: personal slot cooling down — skipped."); continue; }
        $limit = min($limit, $budget);

        $ids = array_column(db_all(
            "SELECT m.id FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
              WHERE m.client_id = ? AND m.status = 'queued' AND c.status = 'sending'
              ORDER BY m.id ASC LIMIT {$limit}", [$cid]
        ), 'id');
        if (!$ids) continue;

        $ph = implode(',', array_fill(0, count($ids), '?'));
        db_run("UPDATE campaign_messages SET status='sending', updated_at=NOW() WHERE id IN ($ph) AND status='queued'", $ids);
        $messages = db_all("SELECT * FROM campaign_messages WHERE id IN ($ph)", $ids);

        // Reserve credits + build the send items (keyed by message id).
        $tplCache = [];
        $items = [];
        $anyMedia = false;
        foreach ($messages as $m) {
            $campId = (int) $m['campaign_id'];
            $touchedCampaigns[$campId] = true;
            if (!isset($tplCache[$campId])) {
                // LEFT JOIN: a personal-channel campaign has no template — it carries its
                // own text in campaigns.body_text.
                $row = db_row("SELECT t.wa_name, t.language, t.components, t.body_text,
                                      c.variable_map, c.body_text AS campaign_text
                                 FROM campaigns c LEFT JOIN templates t ON t.id=c.template_id
                                WHERE c.id=?", [$campId]);
                if ($row && !empty($row['wa_name'])) {
                    $comps = json_decode((string) $row['components'], true) ?: [];
                    $row['has_media'] = wa_template_has_media($comps);
                    // Upload the header image ONCE per campaign and send by media id. Sending a
                    // link made Meta re-download it for every recipient, which throttles on
                    // shared hosting and shows up as #131053 on big sends. Resolved here (not at
                    // creation) so a campaign scheduled weeks out can't ship an expired id.
                    $row['media_id'] = null;
                    // Meta media ids are a Cloud API concept. A personal-channel client has no
                    // Meta credentials, so resolving one there just fails with an
                    // "Authentication Error" on every campaign — skip it.
                    if ($row['has_media'] && !channel_is_personal($client)) {
                        $vm  = json_decode((string) $row['variable_map'], true) ?: [];
                        $url = trim((string) (campaign_config($vm)['header_media'] ?? ''));
                        if ($url !== '') $row['media_id'] = wa_resolve_media($client, $url);
                    }
                }
                $tplCache[$campId] = $row;
            }
            $tpl = $tplCache[$campId];
            $plainText = trim((string) ($tpl['campaign_text'] ?? ''));
            if (!$tpl || ($plainText === '' && empty($tpl['wa_name']))) {
                db_run("UPDATE campaign_messages SET status='failed', error_code='no_template', error_title='Template missing', updated_at=NOW() WHERE id=?", [(int) $m['id']]);
                $failedTotal++; continue;
            }
            if (credits_adjust($cid, -1, 'send', $campId) === null) {
                db_run("UPDATE campaign_messages SET status='failed', error_code='no_credits', error_title='Insufficient credits', updated_at=NOW() WHERE id=?", [(int) $m['id']]);
                $failedTotal++; $noCreditClients[$cid] = (string) $client['name']; continue;
            }
            if (!empty($tpl['has_media'])) $anyMedia = true;
            $comps = json_decode((string) $m['rendered_components'], true) ?: [];
            if (!isset($comps['text'])) {
                $comps = wa_apply_media_id($comps, $tpl['media_id'] ?? null);   // no-op without an id
            }
            $items[(int) $m['id']] = [
                'to' => (string) $m['phone_e164'],
                'name' => (string) ($tpl['wa_name'] ?: 'Message'), 'lang' => (string) ($tpl['language'] ?? 'en'),
                // Set only for plain-text (personal channel) campaigns.
                'text' => (string) ($comps['text'] ?? ''),
                'image' => (string) ($comps['image'] ?? ''),
                'components' => isset($comps['text']) ? [] : $comps,
                'campId' => $campId, 'contact' => (int) $m['contact_id'],
                // Used only by the personal channel, which renders the template to text.
                'tpl' => $tpl,
                'cfg' => campaign_config(json_decode((string) $tpl['variable_map'], true) ?: []),
                'contact_row' => db_row("SELECT * FROM contacts WHERE id=?", [(int) $m['contact_id']]) ?: ['name' => ''],
            ];
        }

        // Media templates get gentler concurrency — even by id, Meta is stricter about them.
        // A personal number goes strictly one at a time (see the sequential sender below).
        $isPersonal = channel_is_personal($client);
        $chunkSize  = $isPersonal ? 1 : ($anyMedia ? max(1, (int) config('send_parallel_media', 10)) : $parallel);

        // Send in parallel chunks.
        $firstChunk = true;
        foreach (array_chunk($items, $chunkSize, true) as $chunk) {
            if ($isPersonal) {
                // Pace between messages, but don't pay the delay before the very first one.
                if (!$firstChunk) slot_pace_sleep();
                $firstChunk = false;
                $res = [];
                foreach ($chunk as $mid => $it) {
                    // A plain-text campaign already has its text rendered per recipient at
                    // creation; anything else is a template rendered to text. An image rides
                    // with that text as its caption — one message, not two.
                    if ($it['image'] !== '') {
                        $res[$mid] = channel_send_image($client, (string) $it['to'], $it['image'], $it['text']);
                    } elseif ($it['text'] !== '') {
                        $res[$mid] = channel_send_text($client, (string) $it['to'], $it['text']);
                    } else {
                        $res[$mid] = channel_send_template($client, (string) $it['to'], $it['tpl'], $it['cfg'], $it['contact_row'], 'campaign_resolve_value');
                    }
                }
            } else {
                $res = wa_send_template_batch($client, $chunk);
            }

            // Retry transient failures once before giving up — a single blip used to fail the
            // message permanently, which looked like random losses on large sends.
            $retry = [];
            foreach ($isPersonal ? [] : $res as $mid => $r) {
                if (empty($r['ok']) && wa_error_is_transient((string) ($r['error_code'] ?? ''), (string) ($r['error_title'] ?? ''))) {
                    $retry[$mid] = $chunk[$mid];
                }
            }
            if ($retry) {
                usleep(500000); // 0.5s breather
                foreach (wa_send_template_batch($client, $retry) as $mid => $r2) $res[$mid] = $r2;
            }

            foreach ($res as $mid => $r) {
                $it = $chunk[$mid];
                if ($r['ok']) {
                    db_run("UPDATE campaign_messages SET status='sent', wa_message_id=?, sent_at=NOW(), updated_at=NOW(), error_code=NULL, error_title=NULL WHERE id=?", [$r['wamid'], (int) $mid]);
                    $sentTotal++;
                } else {
                    credits_adjust($cid, 1, 'refund_failed', $it['campId']);
                    db_run("UPDATE campaign_messages SET status='failed', error_code=?, error_title=?, updated_at=NOW() WHERE id=?",
                        [substr((string) $r['error_code'], 0, 32), substr((string) $r['error_title'], 0, 255), (int) $mid]);
                    $failedTotal++;
                }
                if ((int) $it['contact'] > 0) {
                    $logText = $it['text'] !== '' ? $it['text'] : '📄 Template: ' . $it['name'];
                    if ($it['image'] !== '') $logText = '🖼️ ' . ($it['text'] !== '' ? $it['text'] : 'Image');
                    msg_log($cid, (int) $it['contact'], 'out', $logText, [
                        'type' => $it['image'] !== '' ? 'image' : 'template', 'source' => 'campaign',
                        'status' => $r['ok'] ? 'sent' : 'failed', 'wamid' => $r['wamid'] ?? null,
                        'error' => $r['ok'] ? null : (string) $r['error_title'],
                    ]);
                }
                // A send attempt spends slot budget even when it fails: the number still
                // reached out to WhatsApp, which is exactly what the pacing protects.
                slot_consume($client, 1);
            }
        }
        if ($isPersonal) out("Client {$cid}: personal slot used " . count($items) . " message(s).");
    }

    foreach (array_keys($touchedCampaigns) as $campId) {
        $before = db_row("SELECT status FROM campaigns WHERE id=?", [(int) $campId]);
        campaign_refresh_counts((int) $campId);
        $after = db_row("SELECT c.*, cl.name AS client_name FROM campaigns c JOIN clients cl ON cl.id=c.client_id WHERE c.id=?", [(int) $campId]);
        if ($after && $after['status'] === 'completed' && ($before['status'] ?? '') !== 'completed') {
            notify_admin('Campaign completed: ' . $after['name'],
                "Client: {$after['client_name']}\nCampaign: {$after['name']}\nSent: {$after['sent_count']}/{$after['total_count']}\nDelivered: {$after['delivered_count']}  Read: {$after['read_count']}  Failed: {$after['failed_count']}\n");
        }
    }
    foreach ($noCreditClients as $clName) {
        notify_admin('Low credits: ' . $clName, "Client \"{$clName}\" ran out of credits mid-campaign. Some messages were skipped until you top up.");
    }
    out("Campaigns: sent={$sentTotal} failed={$failedTotal} campaigns=" . count($touchedCampaigns) . '.');

    /* ══ AUTOMATIONS (own lock so a separate automation cron can't double-run) ══ */
    if ((int) $pdo->query("SELECT GET_LOCK('wa_automation', 0)")->fetchColumn() === 1) {
        try {
            $resumed  = automation_tick();
            $leads    = automation_ingest_sheets();
            $outreach = automation_send_outreach();
            $noAns    = automation_sweep_no_answer((int) config('no_answer_hours', 24));
            // One push per client with pending inbound, however many messages arrived.
            $pushes   = push_dispatch();
            out("Automation: resumed={$resumed} sheet_leads={$leads} outreach_sent={$outreach} no_answer={$noAns} pushes={$pushes}.");
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('wa_automation')");
        }
    }

    // Start the cooldown for every personal client that sent anything this run. Done once,
    // at the end, so campaigns + qualifier + automation share a single slot.
    foreach (db_all("SELECT * FROM clients WHERE channel='personal'") as $pc) slot_close($pc);

    // Heartbeat — lets the Health Check page confirm the cron is actually running.
    @touch(__DIR__ . '/.heartbeat');
} finally {
    $pdo->query("SELECT RELEASE_LOCK('wa_dispatch')");
}
