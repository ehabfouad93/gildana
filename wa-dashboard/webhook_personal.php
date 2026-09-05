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
/* Either the current secret or the one it just replaced. During a rotation the gateway may
   still be posting under the old URL for a moment, and rejecting those would silently drop
   real customer messages. */
$client = db_row(
    "SELECT * FROM clients WHERE personal_hook_secret = ? OR personal_hook_secret_prev = ?",
    [$secret, $secret]);
if (!$client || ($client['status'] ?? '') !== 'active') {
    http_response_code(403);
    exit('unknown instance');
}

/* A request on the CURRENT secret proves the gateway has moved over, so the previous one is
   no longer needed — retire it at the first real proof rather than on a timer. */
if (!empty($client['personal_hook_secret_prev']) && hash_equals((string) $client['personal_hook_secret'], $secret)) {
    db_run("UPDATE clients SET personal_hook_secret_prev=NULL WHERE id=?", [(int) $client['id']]);
}

// Ack immediately: gateways retry on a slow response, and a retry would double-handle.
// Read the body BEFORE releasing the connection — php://input is not readable afterwards.
$raw  = file_get_contents('php://input') ?: '';
http_response_code(200);
respond_and_continue('ok');

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

/* ── inbound messages ──
   One delivery can carry several: a phone coming back online, or someone sending three
   messages in a row. Handling only the first is why some arrived and some didn't. */
foreach (pw_parse_inbound_all($data) as $in) {
    if (!empty($in['from_me'])) {
        // Not noise: the echo of our own message is the one place WhatsApp reveals which
        // @lid a contact we already know is addressed by. Discarding it is what left
        // replies unmatched.
        pw_learn_from_echo($client, $in);
        continue;
    }
    pw_handle_inbound($client, $in);
}
exit;

function pw_handle_inbound(array $client, array $in): void
{
    $cid  = (int) $client['id'];
    $from = (string) $in['from'];
    $text = (string) $in['text'];
    $type = (string) $in['type'];
    $jid  = (string) ($in['jid'] ?? '');
    if ($from === '') return;

    /* ── Upsert the contact and open the conversation ──
       Match on the exact JID first. WhatsApp moves a person between their phone-number address
       and their @lid one, and matching only on digits then files the same human under two
       contacts — one of which can never be replied to. */
    $contact = null;
    if ($jid !== '') $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND wa_jid=?", [$cid, $jid]);
    if (!$contact)   $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $from]);
    // Last resort: rows created before wa_jid existed were filed under the LID's own digits.
    // Without this they never match, and each message from that person makes another contact.
    if (!$contact && $jid !== '' && !empty($in['is_lid'])) {
        $lidDigits = preg_replace('/\D+/', '', explode('@', $jid)[0]) ?? '';
        if ($lidDigits !== '' && $lidDigits !== $from) {
            $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $lidDigits]);
        }
    }

    if (!$contact) {
        db_run("INSERT INTO contacts (client_id,phone_e164,wa_jid,name,opt_in_status,source,created_at,last_inbound_at)
                VALUES (?,?,?,?, 'in','inbound',NOW(),NOW())", [$cid, $from, $jid ?: null, (string) $in['name']]);
        $contact = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $from]);
    } else {
        db_run("UPDATE contacts SET last_inbound_at=NOW(), wa_jid=COALESCE(NULLIF(?,''), wa_jid) WHERE id=?",
            [$jid, (int) $contact['id']]);

        // Heal a contact saved before the number was known: it was filed under the LID's digits,
        // which nothing can dial. Now that WhatsApp has sent the real number, correct it — unless
        // that number is already taken by another contact, in which case leave it alone rather
        // than break the (client_id, phone_e164) key. Replies work either way, via wa_jid.
        // Only when `from` is a REAL number. Without that guard a reply arriving from a bare
        // LID overwrites the contact's correct phone with the LID's digits — turning a
        // working contact into an undialable one, the opposite of healing.
        if ($from !== (string) $contact['phone_e164'] && !empty($in['mapped'])) {
            $taken = db_val("SELECT id FROM contacts WHERE client_id=? AND phone_e164=? AND id<>?",
                [$cid, $from, (int) $contact['id']]);
            if (!$taken) db_run("UPDATE contacts SET phone_e164=? WHERE id=?", [$from, (int) $contact['id']]);
        }
        $contact = db_row("SELECT * FROM contacts WHERE id=?", [(int) $contact['id']]);
    }
    if (!$contact) return;

    msg_log($cid, (int) $contact['id'], 'in', $text !== '' ? $text : '[' . $type . ']', [
        'type' => $type, 'source' => 'inbound', 'wamid' => $in['wamid'] !== '' ? $in['wamid'] : null,
    ]);

    // Flag for a push; the worker sends it (a slow webhook gets retried).
    push_queue_client($cid);

    // STOP opt-out short-circuits the bot, exactly as on the Cloud channel.
    if (preg_match('/^(STOP|UNSUBSCRIBE|إلغاء|الغاء|توقف)\b/u', strtoupper($text))) {
        db_run("UPDATE contacts SET opt_in_status='out', opted_out_at=NOW() WHERE id=?", [(int) $contact['id']]);
        return;
    }

    // A personal number has no interactive buttons, so a numbered reply ("2") or the button
    // title arrives as ordinary text; the engine's existing free-text matching resolves it.
    automation_handle_inbound($client, $contact, ['type' => $type, 'text' => $text, 'button_id' => '']);
}
