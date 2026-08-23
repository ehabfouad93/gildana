<?php
declare(strict_types=1);

/**
 * Inbound receiver for the PERSONAL-number channel.
 *
 * The gateway that holds a client's WhatsApp Web session posts here when their number
 * receives a message. The client is identified by the secret in the URL
 * (?k=<personal_hook_secret>), which is generated per client when their instance is created.
 *
 * After normalising the payload this joins the SAME path as the Cloud API webhook —
 * contact upsert → msg_log() → push flag → STOP handling → automation_handle_inbound() —
 * so the Inbox, keyword/welcome/default flows, the AI agent and human takeover all behave
 * identically on both channels.
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
require __DIR__ . '/includes/automation.php';   // pulls in channel.php + inbox.php
require_once __DIR__ . '/includes/push.php';

// Some gateways probe the URL with a GET before they will save it.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain');
    echo 'ok';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

/* ── identify the client from the URL secret ── */
$secret = (string) ($_GET['k'] ?? '');
if ($secret === '' || strlen($secret) < 16) {
    http_response_code(403);
    exit('bad key');
}
$client = db_row("SELECT * FROM clients WHERE personal_hook_secret = ?", [$secret]);
if (!$client || ($client['status'] ?? '') !== 'active') {
    http_response_code(403);
    exit('unknown instance');
}

// Ack immediately: gateways retry on a slow response, and a retry would double-handle.
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) exit;

// Best-effort raw log, same as the Cloud webhook (ignore duplicate deliveries).
try {
    $key = substr(hash('sha256', 'pw' . $raw), 0, 60);
    db_run("INSERT IGNORE INTO webhook_events (event_key, payload, received_at) VALUES (?,?,NOW())", [$key, $raw]);
} catch (Throwable $e) { /* non-fatal */ }

$cid = (int) $client['id'];

/* ── connection-state events keep the client's status in sync (QR scanned, phone offline) ── */
$ev = strtolower((string) ($data['event'] ?? ''));
if (strpos($ev, 'connection') !== false) {
    $state = strtolower((string) ($data['data']['state'] ?? $data['state'] ?? ''));
    $mapped = in_array($state, ['open', 'connected'], true) ? 'connected'
            : (in_array($state, ['connecting', 'qr'], true) ? 'qr_pending' : 'disconnected');
    db_run("UPDATE clients SET personal_status=?, personal_connected_at=IF(?='connected', NOW(), personal_connected_at) WHERE id=?",
        [$mapped, $mapped, $cid]);
    exit;
}

/* ── inbound message ── */
$in = pw_parse_inbound($data);
if ($in === null) exit;               // status/echo/group event — nothing to do
if (!empty($in['from_me'])) exit;     // our own outgoing message echoed back

$from = (string) $in['from'];
$text = (string) $in['text'];
$type = (string) $in['type'];
if ($from === '') exit;

// Upsert the contact and open the conversation.
$contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $from]);
if (!$contact) {
    db_run("INSERT INTO contacts (client_id,phone_e164,name,opt_in_status,source,created_at,last_inbound_at)
            VALUES (?,?,?, 'in','inbound',NOW(),NOW())", [$cid, $from, (string) $in['name']]);
    $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $from]);
} else {
    db_run("UPDATE contacts SET last_inbound_at=NOW() WHERE id=?", [(int) $contact['id']]);
    $contact['last_inbound_at'] = date('Y-m-d H:i:s');
}
if (!$contact) exit;

msg_log($cid, (int) $contact['id'], 'in', $text !== '' ? $text : '[' . $type . ']', [
    'type' => $type, 'source' => 'inbound', 'wamid' => $in['wamid'] !== '' ? $in['wamid'] : null,
]);

// Flag for a push; the worker sends it (a slow webhook gets retried).
push_queue_client($cid);

// STOP opt-out short-circuits the bot, exactly as on the Cloud channel.
if (preg_match('/^(STOP|UNSUBSCRIBE|إلغاء|الغاء|توقف)\b/u', strtoupper($text))) {
    db_run("UPDATE contacts SET opt_in_status='out', opted_out_at=NOW() WHERE id=?", [(int) $contact['id']]);
    exit;
}

// A personal number has no interactive buttons, so a numbered reply ("2") or the button
// title arrives as ordinary text; the engine's existing free-text matching resolves it.
automation_handle_inbound($client, $contact, ['type' => $type, 'text' => $text, 'button_id' => '']);
