<?php
declare(strict_types=1);

/**
 * Unified worker — run once per minute:
 *
 *   * * * * * php /path/wa-dashboard/cron/dispatch.php
 *
 * Or over HTTP, with the token in a header (not the URL — access logs keep those):
 *   curl -H "X-Cron-Token: <webhook_verify_token>" https://app.example.com/cron/dispatch.php
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
    /* Prefer the header. The ?token= form still works for one release so an existing cron
       entry keeps running, but it leaks into access logs, proxy logs and Referer headers —
       and anyone holding it can start the worker. */
    $expected = (string) config('webhook_verify_token');
    $header   = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    $query    = (string) ($_GET['token'] ?? '');
    if ($header !== '' ? !hash_equals($expected, $header) : !hash_equals($expected, $query)) {
        http_response_code(403);
        exit('Forbidden');
    }
    if ($header === '' && $query !== '') {
        error_log('dispatch: token passed in the URL is deprecated — send it as the X-Cron-Token header instead.');
    }
    header('Content-Type: text/plain; charset=UTF-8');
    // Keep running even if the triggering (fire-and-forget) request disconnects,
    // so an instant "send now" kick from campaign creation completes the batch.
    ignore_user_abort(true);
    @set_time_limit(0);
}

function out(string $msg): void { echo '[' . date('H:i:s') . '] ' . $msg . "\n"; }

/* Retry policy for transient WhatsApp failures (rate limits, Meta 5xx, network blips).
   Attempt 1 fails → retry in ~1m, then ~5m, then ~25m; after that the message is 'dead'
   and waits for a human on the Needs attention page rather than disappearing silently. */
const SEND_MAX_ATTEMPTS   = 4;
const SEND_BACKOFF_BASE_SEC = 60;

$pdo = db();

/* This worker's identity. Rows are claimed under it so a worker only ever sends the
   messages it actually won, even when several workers run at once. */
$workerId = substr(bin2hex(random_bytes(8)) . '-' . getmypid(), 0, 40);

/* The global lock now guards only the *claim* step. Sending happens under a per-client
   lock further down, so one tenant stuck on a slow upload no longer stalls everyone
   else — and a second worker can pick up other tenants in parallel. */
function claim_lock(PDO $pdo): bool
{
    return (int) $pdo->query("SELECT GET_LOCK('wa_claim', 5)")->fetchColumn() === 1;
}
function claim_unlock(PDO $pdo): void { $pdo->query("SELECT RELEASE_LOCK('wa_claim')"); }

try {
    /* ══ CAMPAIGNS (parallel) ══ */
    /* Reclaim messages orphaned by a worker that died mid-run.
       Critically, this is now split by whether an attempt was ever recorded:

         - no send_attempts row  → the process died BEFORE calling WhatsApp. Nothing was
                                   sent and nothing was charged for it, so it is safe to
                                   put back in the queue.
         - an attempt row exists → the call was made and the outcome never came back. The
                                   message may well have been delivered. Re-sending would
                                   double-message the customer and double-charge the client,
                                   which is exactly the bug this replaces, so it goes to
                                   'review' for a human instead of back to 'queued'. */
    db_run("UPDATE campaign_messages m
              LEFT JOIN send_attempts a ON a.campaign_message_id = m.id
               SET m.status='queued', m.claimed_at=NULL, m.claimed_by=NULL, m.updated_at=NOW()
             WHERE m.status='sending' AND m.claimed_at < (NOW() - INTERVAL 5 MINUTE)
               AND a.id IS NULL");

    $stranded = db_run("UPDATE campaign_messages m
                          JOIN send_attempts a ON a.campaign_message_id = m.id
                           SET m.status='review', m.error_code='unknown_outcome',
                               m.error_title='Send attempted, result never confirmed', m.updated_at=NOW()
                         WHERE m.status='sending' AND m.claimed_at < (NOW() - INTERVAL 5 MINUTE)
                           AND a.outcome='unknown'");
    if ($stranded) out("{$stranded} message(s) with an unconfirmed send moved to review — not resent.");

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

        /* Take this tenant's lock. If another worker already has it, skip to the next
           tenant instead of waiting — this is what stops one slow client blocking the
           rest of the run. */
        if ((int) $pdo->query("SELECT GET_LOCK('wa_dispatch_{$cid}', 0)")->fetchColumn() !== 1) {
            out("Client {$cid}: already being sent by another worker — skipped.");
            continue;
        }

        try {
        /* ── Claim, under the short global lock so two workers can't select the same rows ── */
        if (!claim_lock($pdo)) { out("Client {$cid}: claim lock busy — skipped."); continue; }
        try {
            $ids = array_column(db_all(
                "SELECT m.id FROM campaign_messages m JOIN campaigns c ON c.id = m.campaign_id
                  WHERE m.client_id = ? AND m.status = 'queued' AND c.status = 'sending'
                    AND (m.next_attempt_at IS NULL OR m.next_attempt_at <= NOW())
                  ORDER BY m.id ASC LIMIT {$limit}", [$cid]
            ), 'id');
            if (!$ids) continue;

            $ph = implode(',', array_fill(0, count($ids), '?'));
            db_run("UPDATE campaign_messages
                       SET status='sending', claimed_at=NOW(), claimed_by=?, updated_at=NOW()
                     WHERE id IN ($ph) AND status='queued'", array_merge([$workerId], $ids));
        } finally {
            claim_unlock($pdo);
        }

        /* Read back ONLY the rows this worker actually won. The previous code re-selected
           every id it had looked at, regardless of which ones the UPDATE managed to claim. */
        $messages = db_all(
            "SELECT * FROM campaign_messages WHERE id IN ($ph) AND claimed_by = ? ORDER BY id ASC",
            array_merge($ids, [$workerId]));
        if (!$messages) continue;

        /* Load every contact for the batch in ONE query, keyed by id. This used to be a
           separate SELECT per message — hundreds of extra round-trips on a 300-message run. */
        $contactIds = array_values(array_filter(array_map(fn($m) => (int) $m['contact_id'], $messages)));
        $contactsById = [];
        if ($contactIds) {
            $cph = implode(',', array_fill(0, count($contactIds), '?'));
            foreach (db_all("SELECT * FROM contacts WHERE id IN ($cph)", $contactIds) as $c) {
                $contactsById[(int) $c['id']] = $c;
            }
        }

        /* One reservation for the whole batch instead of a transaction per message. */
        $reserve = credits_reserve($cid, count($messages), null);
        $creditsLeft = (int) $reserve['granted'];
        $reserveTxn  = $reserve['txn_id'];
        if ($creditsLeft === 0) {
            db_run("UPDATE campaign_messages SET status='failed', error_code='no_credits',
                           error_title='Insufficient credits', claimed_by=NULL, updated_at=NOW()
                     WHERE id IN ($ph) AND claimed_by=?", array_merge($ids, [$workerId]));
            $failedTotal += count($messages);
            $noCreditClients[$cid] = (string) $client['name'];
            continue;
        }
        $reservedCount = $creditsLeft;   // remember what we took, to release the unspent part

        // Build the send items (keyed by message id). Credits are already reserved above.
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
                db_run("UPDATE campaign_messages SET status='failed', error_code='no_template', error_title='Template missing', claimed_by=NULL, updated_at=NOW() WHERE id=?", [(int) $m['id']]);
                $failedTotal++; continue;
            }
            // Draw from the batch reservation rather than opening a transaction per message.
            if ($creditsLeft <= 0) {
                db_run("UPDATE campaign_messages SET status='queued', claimed_by=NULL, claimed_at=NULL, updated_at=NOW() WHERE id=?", [(int) $m['id']]);
                $noCreditClients[$cid] = (string) $client['name']; continue;
            }
            $creditsLeft--;
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
                // From the single batched lookup above, not a query per message.
                'contact_row' => $contactsById[(int) $m['contact_id']] ?? ['name' => ''],
            ];
        }

        // Media templates get gentler concurrency — even by id, Meta is stricter about them.
        // A personal number goes strictly one at a time (see the sequential sender below).
        $isPersonal = channel_is_personal($client);
        $chunkSize  = $isPersonal ? 1 : ($anyMedia ? max(1, (int) config('send_parallel_media', 10)) : $parallel);

        // Send in parallel chunks.
        $firstChunk = true;
        foreach (array_chunk($items, $chunkSize, true) as $chunk) {
            /* Record the attempt BEFORE calling WhatsApp. If the process dies at any point
               from here on, this row is the evidence that a call may have gone out — the
               reclaim sweep reads it and sends the message to review instead of resending. */
            $attemptNo = [];
            foreach ($chunk as $mid => $it) {
                $attemptNo[$mid] = (int) db_val("SELECT attempt_count FROM campaign_messages WHERE id=?", [(int) $mid]) + 1;
                db_run("INSERT INTO send_attempts (campaign_message_id, client_id, attempt_no, outcome, started_at)
                        VALUES (?,?,?,'unknown',NOW())
                        ON DUPLICATE KEY UPDATE started_at=VALUES(started_at), outcome='unknown'",
                    [(int) $mid, $cid, $attemptNo[$mid]]);
                db_run("UPDATE campaign_messages SET attempt_count=? WHERE id=?", [$attemptNo[$mid], (int) $mid]);
            }

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

            foreach ($res as $mid => $r) {
                $it   = $chunk[$mid];
                $code = (string) ($r['error_code'] ?? '');
                $ttl  = (string) ($r['error_title'] ?? '');

                /* Close the attempt row with a definite outcome. Anything still 'unknown'
                   after this point means the process died mid-call. */
                db_run("UPDATE send_attempts SET outcome=?, wa_message_id=?, error_code=?, error_title=?, finished_at=NOW()
                         WHERE campaign_message_id=? AND attempt_no=?",
                    [$r['ok'] ? 'ok' : 'failed', $r['wamid'] ?? null,
                     substr($code, 0, 32), substr($ttl, 0, 255), (int) $mid, $attemptNo[$mid]]);

                if ($r['ok']) {
                    db_run("UPDATE campaign_messages SET status='sent', wa_message_id=?, sent_at=NOW(), updated_at=NOW(), error_code=NULL, error_title=NULL, claimed_by=NULL WHERE id=?", [$r['wamid'], (int) $mid]);
                    // Keep the live progress moving without re-scanning the whole campaign;
                    // campaign_refresh_counts() reconciles at the end of the run.
                    campaign_bump_counts($it['campId'], 'sent_count');
                    $sentTotal++;
                } elseif (wa_error_is_transient($code, $ttl) && $attemptNo[$mid] < SEND_MAX_ATTEMPTS) {
                    /* Transient: schedule a later attempt instead of burning the message.
                       One retry 0.5s later was never enough for a rate limit or a Meta 5xx —
                       it just turned a blip into a permanent failure. Backoff with jitter so
                       a whole batch doesn't come back in lockstep. */
                    $delay = SEND_BACKOFF_BASE_SEC * (5 ** ($attemptNo[$mid] - 1));
                    $delay = (int) ($delay * (0.8 + (random_int(0, 40) / 100)));
                    db_run("UPDATE campaign_messages
                               SET status='queued', next_attempt_at=(NOW() + INTERVAL ? SECOND),
                                   error_code=?, error_title=?, claimed_by=NULL, claimed_at=NULL, updated_at=NOW()
                             WHERE id=?",
                        [$delay, substr($code, 0, 32), substr($ttl, 0, 255), (int) $mid]);
                    /* Refund now. The retry re-enters the queue and will reserve its own
                       credit when it is claimed again — keeping this one would bill twice. */
                    credits_adjust($cid, 1, 'refund_retry', $it['campId']);
                } else {
                    /* Permanent, or out of attempts. */
                    $dead = $attemptNo[$mid] >= SEND_MAX_ATTEMPTS && wa_error_is_transient($code, $ttl);
                    credits_adjust($cid, 1, 'refund_failed', $it['campId']);
                    db_run("UPDATE campaign_messages SET status=?, error_code=?, error_title=?, claimed_by=NULL, updated_at=NOW() WHERE id=?",
                        [$dead ? 'dead' : 'failed', substr($code, 0, 32), substr($ttl, 0, 255), (int) $mid]);
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

        } finally {
            /* Give back whatever the batch reserved but never spent — messages that failed,
               were skipped for a missing template, or never got sent at all. One ledger row. */
            if (isset($reservedCount) && $creditsLeft > 0) {
                credits_release($cid, $creditsLeft, null, 'refund_unused');
            }
            $reservedCount = null; $creditsLeft = 0;
            $pdo->query("SELECT RELEASE_LOCK('wa_dispatch_{$cid}')");
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

    /* ══ RETENTION ══
       Raw callback payloads and the inbound decision log both hold customers' message text
       and phone numbers, and nothing ever deleted them. Keep a bounded window instead. */
    $retentionDays = (int) (db_val("SELECT v FROM app_settings WHERE k='retention_days'") ?: 30);
    if ($retentionDays > 0) {
        // Chunked so a first run over a long-neglected table can't lock it for minutes.
        $pruned = 0;
        do {
            $n = db_run("DELETE FROM webhook_events WHERE received_at < (NOW() - INTERVAL ? DAY) LIMIT 5000", [$retentionDays]);
            $pruned += $n;
        } while ($n === 5000);
        do {
            $n = db_run("DELETE FROM inbound_log WHERE created_at < (NOW() - INTERVAL ? DAY) LIMIT 5000", [$retentionDays]);
            $pruned += $n;
        } while ($n === 5000);
        if ($pruned) out("Retention: pruned {$pruned} row(s) older than {$retentionDays} day(s).");
    }

    // Heartbeat — lets the Health Check page confirm the cron is actually running.
    @touch(__DIR__ . '/.heartbeat');
} finally {
    // Per-client locks are released inside the loop; nothing global is held here any more.
    claim_unlock($pdo);
}
