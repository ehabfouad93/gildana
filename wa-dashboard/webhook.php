<?php
declare(strict_types=1);

/**
 * Meta WhatsApp webhook receiver (one URL for all clients).
 *   GET  → verification handshake (hub.challenge).
 *   POST → delivery statuses (sent/delivered/read/failed) + inbound messages (STOP opt-out).
 *
 * Configure this URL in each client's WABA webhook settings, subscribed to the
 * `messages` field.
 */

require __DIR__ . '/includes/config_loader.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/crypto.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/campaign.php';
require __DIR__ . '/includes/credits.php';
require __DIR__ . '/includes/whatsapp.php';
require __DIR__ . '/includes/ai.php';
require __DIR__ . '/includes/notify.php';
require __DIR__ . '/includes/automation.php';
require_once __DIR__ . '/includes/push.php';

/* ── GET verification ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = (string) ($_GET['hub_mode'] ?? '');
    $token     = (string) ($_GET['hub_verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? '');
    if ($mode === 'subscribe' && hash_equals((string) config('webhook_verify_token'), $token)) {
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('Verification failed');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

/**
 * Which tenant is this callback for?
 *
 * Meta addresses every entry by the phone number it was sent to, and each tenant owns a
 * different number — so metadata.phone_number_id is the routing key. Used only to pick the
 * right signing secret; the payload is not trusted for anything else until it is verified.
 */
function webhook_client_for(array $data): ?array
{
    foreach ((array) ($data['entry'] ?? []) as $entry) {
        foreach ((array) ($entry['changes'] ?? []) as $change) {
            $pnid = (string) ($change['value']['metadata']['phone_number_id'] ?? '');
            if ($pnid === '') continue;
            $row = db_row("SELECT * FROM clients WHERE phone_number_id = ?", [$pnid]);
            if ($row) return $row;
        }
    }
    return null;
}

$raw = file_get_contents('php://input') ?: '';

$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(200); echo 'ok'; exit; }

/* ── Signature verification ──
   Each tenant brings their own Meta app (their own app_id, phone number and token), and
   Meta signs every callback with THAT app's secret. So the secret to check against depends
   on which tenant the payload is for — a single platform-wide value could only ever
   validate one of them. The per-client secret has been collected in Admin → client →
   Credentials all along; this is where it finally gets used.

   Parsing the JSON first is safe: it only tells us which client to look up, and nothing is
   written or acted on until the signature checks out. */
$sigClient = webhook_client_for($data);
$appSecret = '';
if ($sigClient && !empty($sigClient['app_secret_enc'])) {
    $appSecret = decrypt_secret((string) $sigClient['app_secret_enc']);
}
// Fall back to a platform-wide secret, for a deployment where every tenant shares one app.
if ($appSecret === '') $appSecret = (string) config('app_secret', '');

if ($appSecret !== '') {
    $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $appSecret);
    if (!$sig || !hash_equals($expected, $sig)) {
        error_log('webhook: bad signature for client ' . (int) ($sigClient['id'] ?? 0));
        http_response_code(403);
        exit('Bad signature');
    }
} else {
    /* No secret to check against. Unsigned callbacks let anyone who finds this URL forge
       delivery states, create contacts and trigger automations, so this is loud rather
       than silent — and the client's Health Check shows it in red until it is fixed.
       Rejecting outright is opt-in per client so that turning it on can be verified for
       one tenant before it applies to the rest. */
    $who = (int) ($sigClient['id'] ?? 0);
    error_log('webhook: UNSIGNED callback accepted for client ' . $who
            . ' — set that client\'s App Secret in Admin → client → Credentials.');
    if ($sigClient && !empty($sigClient['require_signed_webhook'])) {
        http_response_code(403);
        exit('Signature required');
    }
}

/* Record the event and use it to drop repeats. Meta re-delivers a callback it thinks we
   failed to ack, and re-running a delivery would fire the automation and the AI reply a
   second time — so the insert doubles as the dedupe key: if it changed no rows, we have
   handled this payload already. */
$duplicate = false;
try {
    $key = substr(hash('sha256', $raw), 0, 60);
    $duplicate = db_run("INSERT IGNORE INTO webhook_events (event_key, payload, received_at) VALUES (?,?,NOW())", [$key, $raw]) === 0;
} catch (Throwable $e) { /* non-fatal — better to process twice than not at all */ }

/* Ack Meta NOW, then keep working with the connection closed.
   Setting a 200 status was never enough: PHP holds the response until the script ends,
   so Meta sat waiting through the whole automation and AI run. On a busy account that
   blocks a PHP worker per inbound message and pushes Meta into timeout-retries — which
   is exactly what produces duplicate automation runs. */
http_response_code(200);
respond_and_continue('ok');

if ($duplicate) exit;   // already handled on the first delivery

/** WhatsApp status → our progression rank (forward-only, never downgrade). */
function status_rank(string $s): int
{
    return ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5][$s] ?? 0;
}

$touchedCampaigns = [];
$pushQueued = false;

foreach (($data['entry'] ?? []) as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $value = $change['value'] ?? [];
        $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
        $client = $phoneNumberId !== ''
            ? db_row("SELECT * FROM clients WHERE phone_number_id = ?", [$phoneNumberId])
            : null;

        /* ── Delivery statuses ── */
        foreach (($value['statuses'] ?? []) as $st) {
            $wamid  = (string) ($st['id'] ?? '');
            $status = strtolower((string) ($st['status'] ?? ''));
            $ts     = isset($st['timestamp']) ? date('Y-m-d H:i:s', (int) $st['timestamp']) : date('Y-m-d H:i:s');
            if ($wamid === '' || $status === '') continue;

            // Automation (flow) message delivery status — forward-only.
            if ($status === 'delivered') {
                db_run("UPDATE flow_messages SET status='delivered' WHERE wa_message_id=? AND status='sent'", [$wamid]);
            } elseif ($status === 'read') {
                db_run("UPDATE flow_messages SET status='read' WHERE wa_message_id=? AND status IN ('sent','delivered')", [$wamid]);
            } elseif ($status === 'failed') {
                $fe = $st['errors'][0] ?? [];
                db_run("UPDATE flow_messages SET status='failed', error_title=? WHERE wa_message_id=? AND status='sent'",
                    [substr((string) ($fe['title'] ?? ($fe['message'] ?? 'Failed')), 0, 255), $wamid]);
            }

            // Inbox: forward-only status for any outbound message (automation / manual / campaign).
            // Guarded so a missing `messages` table (migration 008 not yet applied) can't break the webhook.
            if (in_array($status, ['delivered', 'read', 'failed'], true)) {
                try {
                    $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
                    if ($status === 'failed') {
                        $fe = $st['errors'][0] ?? [];
                        db_run("UPDATE messages SET status='failed', error_title=? WHERE wa_message_id=? AND (status IS NULL OR status NOT IN ('read'))",
                            [substr((string) ($fe['title'] ?? ($fe['message'] ?? 'Failed')), 0, 255), $wamid]);
                    } else {
                        db_run("UPDATE messages SET status=? WHERE wa_message_id=? AND COALESCE(FIELD(status,'sent','delivered','read'),0) < ?",
                            [$status, $wamid, $rank[$status]]);
                    }
                } catch (Throwable $e) { /* inbox table not ready — ignore */ }
            }

            $msg = db_row("SELECT id, campaign_id, status FROM campaign_messages WHERE wa_message_id = ? LIMIT 1", [$wamid]);
            if (!$msg) continue;

            // Only move forward.
            if (status_rank($status) <= status_rank((string) $msg['status']) && $status !== 'failed') continue;

            if ($status === 'delivered') {
                db_run("UPDATE campaign_messages SET status='delivered', delivered_at=COALESCE(delivered_at,?), updated_at=NOW() WHERE id=?", [$ts, $msg['id']]);
            } elseif ($status === 'read') {
                db_run("UPDATE campaign_messages SET status='read', read_at=COALESCE(read_at,?), delivered_at=COALESCE(delivered_at,?), updated_at=NOW() WHERE id=?", [$ts, $ts, $msg['id']]);
            } elseif ($status === 'sent') {
                db_run("UPDATE campaign_messages SET status='sent', sent_at=COALESCE(sent_at,?), updated_at=NOW() WHERE id=?", [$ts, $msg['id']]);
            } elseif ($status === 'failed') {
                $err = $st['errors'][0] ?? [];
                if (!in_array($msg['status'], ['delivered', 'read'], true)) {
                    db_run("UPDATE campaign_messages SET status='failed', error_code=?, error_title=?, updated_at=NOW() WHERE id=?",
                        [substr((string) ($err['code'] ?? ''), 0, 32), substr((string) ($err['title'] ?? ($err['message'] ?? 'Failed')), 0, 255), $msg['id']]);
                }
            }
            $touchedCampaigns[(int) $msg['campaign_id']] = true;
        }

        /* ── Inbound messages → 24h window, STOP opt-out, automation engine ── */
        if ($client) {
            $cid = (int) $client['id'];
            foreach (($value['messages'] ?? []) as $in) {
                $from = preg_replace('/\D+/', '', (string) ($in['from'] ?? ''));
                if ($from === '') continue;

                // Parse message payload → text / button.
                $mtype = (string) ($in['type'] ?? 'text');
                $text = ''; $buttonId = '';
                if ($mtype === 'text') {
                    $text = trim((string) ($in['text']['body'] ?? ''));
                } elseif ($mtype === 'interactive') {
                    $br = $in['interactive']['button_reply'] ?? ($in['interactive']['list_reply'] ?? []);
                    $buttonId = (string) ($br['id'] ?? '');
                    $text = trim((string) ($br['title'] ?? ''));
                } elseif ($mtype === 'button') {
                    $buttonId = (string) ($in['button']['payload'] ?? '');
                    $text = trim((string) ($in['button']['text'] ?? ''));
                }

                // Upsert contact + open the 24h window.
                $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $from]);
                if (!$contact) {
                    $profileName = (string) ($value['contacts'][0]['profile']['name'] ?? '');
                    db_run("INSERT INTO contacts (client_id,phone_e164,name,opt_in_status,source,created_at,last_inbound_at)
                            VALUES (?,?,?, 'in','inbound',NOW(),NOW())", [$cid, $from, $profileName]);
                    $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $from]);
                } else {
                    db_run("UPDATE contacts SET last_inbound_at=NOW() WHERE id=?", [(int) $contact['id']]);
                    $contact['last_inbound_at'] = date('Y-m-d H:i:s');
                }

                // Log the inbound message into the unified Inbox.
                $logBody = $text !== '' ? $text : '[' . $mtype . ']';
                msg_log($cid, (int) $contact['id'], 'in', $logBody, [
                    'type' => $mtype, 'source' => 'inbound', 'wamid' => (string) ($in['id'] ?? '') ?: null,
                ]);

                // Flag the client for a push. This is one cheap UPSERT — the actual
                // HTTPS pushes happen in the background worker, because Meta retries a
                // slow webhook. Repeats collapse into the single outbox row.
                push_queue_client($cid);
                $pushQueued = true;

                // STOP opt-out short-circuits the bot.
                if (preg_match('/^(STOP|UNSUBSCRIBE|إلغاء|الغاء|توقف)\b/u', strtoupper($text))) {
                    db_run("UPDATE contacts SET opt_in_status='out', opted_out_at=NOW() WHERE id=?", [(int) $contact['id']]);
                    continue;
                }
                if (!$contact) continue;

                automation_handle_inbound($client, $contact, ['type' => $mtype, 'text' => $text, 'button_id' => $buttonId]);
            }
        }
    }
}

foreach (array_keys($touchedCampaigns) as $campId) {
    campaign_refresh_counts((int) $campId);
}

// Fire-and-forget: makes the push land in seconds instead of waiting for the 5-min cron.
if ($pushQueued) trigger_worker();
// The response was already sent by respond_and_continue() above.
