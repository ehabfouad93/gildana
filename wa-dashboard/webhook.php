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

$raw = file_get_contents('php://input') ?: '';

/* ── Signature verification (if app secret configured) ── */
$appSecret = (string) config('app_secret', '');
if ($appSecret !== '') {
    $sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $appSecret);
    if (!$sig || !hash_equals($expected, $sig)) {
        http_response_code(403);
        exit('Bad signature');
    }
}

// Always ack fast so Meta doesn't retry; process inline (payloads are small).
http_response_code(200);

$data = json_decode($raw, true);
if (!is_array($data)) { echo 'ok'; exit; }

// Best-effort raw log for debugging (ignore dup key collisions).
try {
    $key = substr(hash('sha256', $raw), 0, 60);
    db_run("INSERT IGNORE INTO webhook_events (event_key, payload, received_at) VALUES (?,?,NOW())", [$key, $raw]);
} catch (Throwable $e) { /* non-fatal */ }

/** WhatsApp status → our progression rank (forward-only, never downgrade). */
function status_rank(string $s): int
{
    return ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5][$s] ?? 0;
}

$touchedCampaigns = [];

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

echo 'ok';
