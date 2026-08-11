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
        foreach ($messages as $m) {
            $campId = (int) $m['campaign_id'];
            $touchedCampaigns[$campId] = true;
            if (!isset($tplCache[$campId])) {
                $tplCache[$campId] = db_row("SELECT t.wa_name, t.language FROM campaigns c JOIN templates t ON t.id=c.template_id WHERE c.id=?", [$campId]);
            }
            $tpl = $tplCache[$campId];
            if (!$tpl) { db_run("UPDATE campaign_messages SET status='failed', error_code='no_template', error_title='Template missing', updated_at=NOW() WHERE id=?", [(int) $m['id']]); $failedTotal++; continue; }
            if (credits_adjust($cid, -1, 'send', $campId) === null) {
                db_run("UPDATE campaign_messages SET status='failed', error_code='no_credits', error_title='Insufficient credits', updated_at=NOW() WHERE id=?", [(int) $m['id']]);
                $failedTotal++; $noCreditClients[$cid] = (string) $client['name']; continue;
            }
            $items[(int) $m['id']] = [
                'to' => (string) $m['phone_e164'], 'name' => (string) $tpl['wa_name'], 'lang' => (string) $tpl['language'],
                'components' => json_decode((string) $m['rendered_components'], true) ?: [],
                'campId' => $campId, 'contact' => (int) $m['contact_id'],
            ];
        }

        // Send in parallel chunks.
        foreach (array_chunk($items, $parallel, true) as $chunk) {
            $res = wa_send_template_batch($client, $chunk);
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
                    msg_log($cid, (int) $it['contact'], 'out', '📄 Template: ' . $it['name'], [
                        'type' => 'template', 'source' => 'campaign',
                        'status' => $r['ok'] ? 'sent' : 'failed', 'wamid' => $r['wamid'] ?? null,
                        'error' => $r['ok'] ? null : (string) $r['error_title'],
                    ]);
                }
            }
        }
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
            out("Automation: resumed={$resumed} sheet_leads={$leads} outreach_sent={$outreach} no_answer={$noAns}.");
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('wa_automation')");
        }
    }

    // Heartbeat — lets the Health Check page confirm the cron is actually running.
    @touch(__DIR__ . '/.heartbeat');
} finally {
    $pdo->query("SELECT RELEASE_LOCK('wa_dispatch')");
}
