<?php
declare(strict_types=1);
/**
 * Unified message log + live Inbox helpers (WhatsApp-style two-way conversations).
 * Every inbound (webhook) and outbound (automation/agent/manual/campaign) message is
 * recorded in `messages`; the Inbox UIs read threads from here and can reply within the
 * 24-hour customer-service window.
 */
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/channel.php';
require_once __DIR__ . '/credits.php';

/** Record one message. Returns the row id. $opts: type, wamid, status, error, source.
 *  Never throws — logging must not break sending if the `messages` table is missing
 *  (e.g. migration 008 not yet applied). */
function msg_log(int $clientId, int $contactId, string $direction, string $body, array $opts = []): int
{
    try {
        return db_insert(
            "INSERT INTO messages (client_id,contact_id,direction,type,body,wa_message_id,status,error_title,source,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())",
            [
                $clientId, $contactId, $direction, (string) ($opts['type'] ?? 'text'), $body,
                $opts['wamid'] ?? null, $opts['status'] ?? null, $opts['error'] ?? null, $opts['source'] ?? null,
            ]
        );
    } catch (Throwable $e) {
        error_log('msg_log skipped: ' . $e->getMessage());
        return 0;
    }
}

/** Within the 24h window (free-form text allowed) of the contact's last inbound? */
function inbox_window_open(array $contact, array $client = []): bool
{
    // The 24-hour rule belongs to Meta's Cloud API. A personal number sends ordinary
    // messages, so an agent can always reply from the Inbox on that channel.
    if ($client && function_exists('channel_is_personal') && channel_is_personal($client)) return true;
    $last = $contact['last_inbound_at'] ?? null;
    return $last && strtotime((string) $last) > time() - 86400;
}

/** Thread list for a client: newest conversation first, with unread counts. */
function inbox_threads(int $clientId, string $q = '', int $limit = 200): array
{
    $params = [$clientId];
    $search = '';
    if ($q !== '') { $search = " AND (c.name LIKE ? OR c.phone_e164 LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
    $limit = max(1, min(500, $limit));
    return db_all(
        "SELECT c.id contact_id, c.phone_e164, c.name, c.last_inbound_at,
                m.body last_body, m.direction last_dir, m.type last_type, m.created_at last_at,
                (SELECT COUNT(*) FROM messages mi
                   WHERE mi.contact_id=c.id AND mi.direction='in'
                     AND mi.created_at > COALESCE(c.inbox_read_at,'2000-01-01')) unread
           FROM contacts c
           JOIN messages m ON m.id = (SELECT id FROM messages m2 WHERE m2.contact_id=c.id ORDER BY id DESC LIMIT 1)
          WHERE c.client_id=?{$search}
          ORDER BY m.id DESC
          LIMIT {$limit}",
        $params
    );
}

/** Messages in a thread after $afterId (ascending). */
function inbox_thread(int $clientId, int $contactId, int $afterId = 0, int $limit = 400): array
{
    $limit = max(1, min(1000, $limit));
    return db_all(
        "SELECT id, direction, type, body, status, error_title, created_at
           FROM messages WHERE client_id=? AND contact_id=? AND id>?
          ORDER BY id ASC LIMIT {$limit}",
        [$clientId, $contactId, $afterId]
    );
}

/** Mark a thread read (clears its unread count). */
function inbox_mark_read(int $clientId, int $contactId): void
{
    db_run("UPDATE contacts SET inbox_read_at=NOW() WHERE id=? AND client_id=?", [$contactId, $clientId]);
}

/** Total unread across all threads for a client (for a nav badge). */
function inbox_unread_total(int $clientId): int
{
    return (int) db_val(
        "SELECT COUNT(*) FROM messages m JOIN contacts c ON c.id=m.contact_id
          WHERE m.client_id=? AND m.direction='in' AND m.created_at > COALESCE(c.inbox_read_at,'2000-01-01')",
        [$clientId]
    );
}

/** Shared AJAX endpoint for the Inbox UIs. Echoes JSON + exits if it handled a request. */
function inbox_handle_ajax(array $client): void
{
    $cid = (int) $client['id'];
    $a = (string) ($_GET['ajax'] ?? $_POST['ajax'] ?? '');
    if ($a === '') return;

    if ($a === 'threads') {
        json_out(['ok' => true, 'threads' => inbox_threads($cid, trim((string) ($_GET['q'] ?? '')))]);
    }
    if ($a === 'thread') {
        $contactId = (int) ($_GET['contact'] ?? 0);
        $after     = (int) ($_GET['after'] ?? 0);
        $contact = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid]);
        if (!$contact) json_out(['ok' => false]);
        $msgs = inbox_thread($cid, $contactId, $after);
        inbox_mark_read($cid, $contactId);
        json_out([
            'ok' => true, 'messages' => $msgs, 'window_open' => inbox_window_open($contact, $client),
            'name' => (string) $contact['name'], 'phone' => (string) $contact['phone_e164'],
            'bot_paused' => inbox_bot_paused($contact),
        ]);
    }
    if ($a === 'send') {
        verify_csrf();
        json_out(inbox_send($client, (int) ($_POST['contact'] ?? 0), (string) ($_POST['body'] ?? '')));
    }
    // Live takeover: pause the bot for this contact / hand it back.
    if ($a === 'takeover' || $a === 'resume') {
        verify_csrf();
        $contactId = (int) ($_POST['contact'] ?? 0);
        if (!db_row("SELECT id FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid])) json_out(['ok' => false]);
        $a === 'takeover' ? inbox_take_over($cid, $contactId) : inbox_resume_bot($cid, $contactId);
        json_out(['ok' => true, 'bot_paused' => $a === 'takeover']);
    }
    json_out(['ok' => false]);
}

/** Send a manual reply (free-form text, 24h window only). Costs 1 credit. */
function inbox_send(array $client, int $contactId, string $body): array
{
    $body = trim($body);
    if ($body === '') return ['ok' => false, 'error' => 'Type a message first.'];
    $contact = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$contactId, (int) $client['id']]);
    if (!$contact) return ['ok' => false, 'error' => 'Contact not found.'];
    if (!inbox_window_open($contact, $client)) {
        return ['ok' => false, 'error' => 'Outside the 24-hour window — you can only reach this contact with an approved template (use Campaigns).'];
    }
    /* Only reachable inside the 24-hour window (checked just above), so this is always a
       service message — the category Meta does not charge for. */
    $cost = function_exists('billing_message_credits')
          ? billing_message_credits($client, (string) $contact['phone_e164'], 'service') : 1;
    $bal = credits_adjust((int) $client['id'], -$cost, 'inbox', null);
    if ($bal === null) return ['ok' => false, 'error' => 'No credits left.'];
    $res = channel_send_text($client, (string) $contact['phone_e164'], $body);
    if (empty($res['ok'])) {
        credits_adjust((int) $client['id'], $cost, 'inbox_refund', null);
    } elseif (function_exists('billing_record_messages')) {
        billing_record_messages($client, (string) $contact['phone_e164'], 'service', 1, 0.0, $cost);
    }
    $id = msg_log((int) $client['id'], $contactId, 'out', $body, [
        'source' => 'manual', 'status' => !empty($res['ok']) ? 'sent' : 'failed',
        'wamid' => $res['wamid'] ?? null, 'error' => $res['error_title'] ?? null,
    ]);
    // A human just replied → take the conversation over so the bot can't talk over them.
    if (!empty($res['ok'])) inbox_take_over((int) $client['id'], $contactId);
    return ['ok' => !empty($res['ok']), 'error' => !empty($res['ok']) ? '' : (string) ($res['error_title'] ?? 'Send failed.'), 'id' => $id];
}

/* ─────────────────────────────────────────────
   Human handoff (live takeover)
───────────────────────────────────────────── */

/** Pause the bot for this contact and stop any run that would resume it on a timer. */
function inbox_take_over(int $clientId, int $contactId, int $hours = 24): void
{
    try {
        db_run("UPDATE contacts SET bot_paused_until = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id=? AND client_id=?",
            [max(1, $hours), $contactId, $clientId]);
        // Otherwise a waiting_timer run could fire mid-conversation behind the agent's back.
        db_run("UPDATE flow_runs SET status='stopped', updated_at=NOW()
                 WHERE contact_id=? AND client_id=? AND status IN ('active','waiting_input','waiting_timer')",
            [$contactId, $clientId]);
    } catch (Throwable $e) {
        error_log('inbox_take_over skipped: ' . $e->getMessage());   // migration 009 not applied yet
    }
}

/** Hand the conversation back to the bot. */
function inbox_resume_bot(int $clientId, int $contactId): void
{
    try {
        db_run("UPDATE contacts SET bot_paused_until=NULL WHERE id=? AND client_id=?", [$contactId, $clientId]);
    } catch (Throwable $e) {
        error_log('inbox_resume_bot skipped: ' . $e->getMessage());
    }
}

/** Is the bot currently paused for this contact? */
function inbox_bot_paused(array $contact): bool
{
    $u = $contact['bot_paused_until'] ?? null;
    return $u !== null && strtotime((string) $u) > time();
}
