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
            "INSERT INTO messages (client_id,contact_id,direction,type,body,wa_message_id,status,error_code,error_title,source,source_ref_id,created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())",
            [
                $clientId, $contactId, $direction, (string) ($opts['type'] ?? 'text'), $body,
                $opts['wamid'] ?? null, $opts['status'] ?? null,
                // The code is what the Inbox explains from; the title is Meta's raw wording,
                // kept so an unrecognised code still shows something truthful.
                isset($opts['error_code']) && $opts['error_code'] !== '' ? substr((string) $opts['error_code'], 0, 32) : null,
                $opts['error'] ?? null, $opts['source'] ?? null,
                // What to re-run to send this again: a campaign_messages id, or a flow_runs id.
                isset($opts['ref']) && (int) $opts['ref'] > 0 ? (int) $opts['ref'] : null,
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
    $rows = db_all(
        "SELECT id, direction, type, body, status, error_code, error_title, source, source_ref_id, created_at
           FROM messages WHERE client_id=? AND contact_id=? AND id>?
          ORDER BY id ASC LIMIT {$limit}",
        [$clientId, $contactId, $afterId]
    );
    /* Meta's own wording is all the thread used to show — "This message was not delivered to
       maintain healthy ecosystem engagement" tells an agent nothing about whether the lead is
       reachable, whether it was their fault, or what to do next. Attach the plain-language
       explanation here so every caller of the thread gets the same answer. */
    foreach ($rows as &$r) {
        if (($r['status'] ?? '') !== 'failed') continue;
        $ex = wa_error_explain((string) ($r['error_code'] ?? ''), (string) ($r['error_title'] ?? ''));
        $r['error_label']  = $ex['label'];
        $r['error_hint']   = strip_tags($ex['hint']);   // plain text: the thread escapes on render
        $r['error_action'] = $ex['action'];
        // Offer the button only when a resend can actually succeed AND we know what to re-run.
        $r['can_resend']   = $ex['action'] === 'later'
                          && (int) ($r['source_ref_id'] ?? 0) > 0
                          && in_array((string) ($r['source'] ?? ''), ['campaign', 'qualifier', 'automation'], true);
    }
    unset($r);
    return $rows;
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
    if ($a === 'resend') {
        verify_csrf();
        json_out(inbox_resend($client, (int) ($_POST['message'] ?? 0)));
    }
    if ($a === 'templates') {
        json_out(['ok' => true, 'templates' => inbox_templates($cid)]);
    }
    if ($a === 'send_template') {
        verify_csrf();
        $strs = fn($k): array => array_map('strval', (array) ($_POST[$k] ?? []));
        json_out(inbox_send_template(
            $client, (int) ($_POST['contact'] ?? 0), (int) ($_POST['template'] ?? 0),
            $strs('vars'), $strs('header_vars'), (string) ($_POST['header_media'] ?? '')));
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

/**
 * Send a failed message again, from the thread it failed in.
 *
 * The Inbox could already explain a failure but not act on it, so a client reading "this clears
 * by itself, try again tomorrow" had to go and find the campaign or qualifier it came from. This
 * re-runs the original work item rather than composing a new send: the template, its per-recipient
 * variables and the media are all already resolved on that row, and rebuilding them here would be
 * a second code path that could quietly disagree with the first.
 *
 * Two refusals matter more than the happy path:
 *
 *   - Only errors wa_error_explain() marks 'later' are resendable. A blocked number would return
 *     the identical error and cost another credit, so the button is not offered and this checks
 *     again server-side — the UI is a convenience, not the guard.
 *   - A message still queued or already sent is never re-queued. Without that check a double
 *     click, or a stale tab left open, sends the customer the same message twice and bills for it.
 *
 * @return array{ok:bool, error?:string}
 */
function inbox_resend(array $client, int $messageId): array
{
    $cid = (int) $client['id'];
    $m = db_row("SELECT * FROM messages WHERE id=? AND client_id=?", [$messageId, $cid]);
    if (!$m)                              return ['ok' => false, 'error' => 'Message not found.'];
    if (($m['status'] ?? '') !== 'failed') return ['ok' => false, 'error' => 'That message did not fail, so there is nothing to send again.'];

    $ex = wa_error_explain((string) ($m['error_code'] ?? ''), (string) ($m['error_title'] ?? ''));
    if ($ex['action'] !== 'later') {
        return ['ok' => false, 'error' => $ex['action'] === 'never'
            ? 'This number cannot receive the message, so sending it again will not help.'
            : 'Fix the problem first: ' . strip_tags($ex['hint'])];
    }

    $ref    = (int) ($m['source_ref_id'] ?? 0);
    $source = (string) ($m['source'] ?? '');
    if ($ref <= 0) {
        return ['ok' => false, 'error' => $source === 'manual'
            ? 'Type the message again to resend it.'
            : 'This message was sent before resending was supported — resend it from the campaign or qualifier.'];
    }

    if ($source === 'campaign') {
        $n = db_run("UPDATE campaign_messages
                        SET status='queued', attempt_count=0, next_attempt_at=NULL, claimed_by=NULL,
                            claimed_at=NULL, error_code=NULL, error_title=NULL, updated_at=NOW()
                      WHERE id=? AND client_id=? AND status IN ('failed','dead','review')", [$ref, $cid]);
        if (!$n) return ['ok' => false, 'error' => 'That message is already on its way.'];
        db_run("DELETE FROM send_attempts WHERE campaign_message_id=?", [$ref]);
    } elseif ($source === 'qualifier' || $source === 'automation') {
        // 'blocked' is where a failed outreach leaves the run; anything else is still running
        // and must not be restarted underneath itself.
        $n = db_run("UPDATE flow_runs SET status='queued', updated_at=NOW()
                      WHERE id=? AND client_id=? AND status='blocked'", [$ref, $cid]);
        if (!$n) return ['ok' => false, 'error' => 'That conversation has already moved on.'];
    } else {
        return ['ok' => false, 'error' => 'This message cannot be sent again automatically.'];
    }

    /* Mark the thread row so it stops offering the button and reads as handled. The real guard
       against a double send is the status check on the work item above — this is only what the
       thread shows. */
    db_run("UPDATE messages SET status='resent' WHERE id=?", [$messageId]);
    if (function_exists('trigger_worker')) trigger_worker();
    return ['ok' => true];
}

/**
 * Approved templates this client can send from the Inbox, with what each one needs filled in.
 *
 * The 24-hour window is the reason this exists. Once it closes the reply box is disabled and a
 * template is the ONLY thing that can reach the contact — but the Inbox offered no way to send
 * one, so an agent looking at a live conversation had to leave, build a one-person campaign, and
 * come back. The same picker doubles as the answer to a failed send: try a different template
 * rather than resending the one WhatsApp just held back.
 */
function inbox_templates(int $clientId): array
{
    $rows = db_all(
        "SELECT id, wa_name, language, category, components, body_text, variable_count
           FROM templates
          WHERE client_id=? AND LOWER(status)='approved'
          ORDER BY wa_name", [$clientId]);

    $out = [];
    foreach ($rows as $t) {
        $components = json_decode((string) $t['components'], true) ?: [];
        $spec = function_exists('wa_template_spec') ? wa_template_spec($components) : [];
        $hf   = strtoupper((string) ($spec['header']['format'] ?? ''));
        $out[] = [
            'id'          => (int) $t['id'],
            'name'        => (string) $t['wa_name'],
            'language'    => (string) $t['language'],
            'category'    => (string) $t['category'],
            // What the agent will actually be sending, placeholders and all, so they can tell
            // two similarly-named templates apart without opening the Templates page.
            'preview'     => channel_render_template_text($components, [], [], null, (string) ($t['body_text'] ?? '')),
            'body_vars'   => (int) ($spec['body_vars'] ?? 0),
            'header_vars' => $hf === 'TEXT' ? (int) ($spec['header']['text_vars'] ?? 0) : 0,
            'needs_media' => in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) ? strtolower($hf) : '',
        ];
    }
    return $out;
}

/**
 * Send one approved template to one contact from the Inbox.
 *
 * Values arrive as plain strings the agent typed and are wrapped as 'static' so they flow
 * through the same wa_build_components() path campaigns use — the Inbox does not get its own
 * notion of what a template parameter is.
 *
 * Unlike inbox_send() this is deliberately allowed OUTSIDE the 24-hour window, because that is
 * the case it exists for. It is billed as a real template message, not a free service reply.
 *
 * @param string[] $vars       body parameters, in order
 * @param string[] $headerVars header text parameters, in order
 */
function inbox_send_template(array $client, int $contactId, int $templateId,
                             array $vars = [], array $headerVars = [], string $headerMedia = ''): array
{
    $cid     = (int) $client['id'];
    $contact = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid]);
    if (!$contact) return ['ok' => false, 'error' => 'Contact not found.'];
    if (($contact['opt_in_status'] ?? '') === 'out') {
        return ['ok' => false, 'error' => 'This contact opted out, so we cannot message them.'];
    }

    $tpl = db_row("SELECT * FROM templates WHERE id=? AND client_id=?", [$templateId, $cid]);
    if (!$tpl) return ['ok' => false, 'error' => 'Template not found.'];
    if (strtolower((string) $tpl['status']) !== 'approved') {
        return ['ok' => false, 'error' => 'That template is not approved yet.'];
    }

    $components = json_decode((string) $tpl['components'], true) ?: [];
    $spec       = wa_template_spec($components);
    $hf         = strtoupper((string) ($spec['header']['format'] ?? ''));

    // Meta rejects the whole send when a parameter is missing (#132012), which costs an attempt
    // and tells the agent nothing. Check here where we can name the field instead.
    $needBody = (int) ($spec['body_vars'] ?? 0);
    for ($i = 1; $i <= $needBody; $i++) {
        if (trim((string) ($vars[$i - 1] ?? '')) === '') {
            return ['ok' => false, 'error' => "Fill in field {{{$i}}} before sending."];
        }
    }
    if (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && trim($headerMedia) === '') {
        return ['ok' => false, 'error' => 'This template needs a ' . strtolower($hf) . ' — paste its link first.'];
    }

    $mk  = fn(array $list): array => array_reduce(
        array_keys($list),
        function (array $c, int $i) use ($list): array {
            $c[(string) ($i + 1)] = ['source' => 'static', 'value' => (string) $list[$i]];
            return $c;
        }, []);
    $cfg = ['vars' => $mk(array_values($vars)), 'header_vars' => $mk(array_values($headerVars))];
    if (trim($headerMedia) !== '') $cfg['header_media'] = trim($headerMedia);

    $category = (string) ($tpl['category'] ?: 'utility');
    $cost = function_exists('billing_message_credits')
          ? billing_message_credits($client, (string) $contact['phone_e164'], $category) : 1;
    if (credits_adjust($cid, -$cost, 'inbox_template', null) === null) {
        return ['ok' => false, 'error' => 'No credits left.'];
    }

    $res = channel_send_template($client, (string) $contact['phone_e164'], $tpl, $cfg, $contact);
    if (empty($res['ok'])) {
        credits_adjust($cid, $cost, 'inbox_template_refund', null);
    } elseif (function_exists('billing_record_messages')) {
        billing_record_messages($client, (string) $contact['phone_e164'], $category, 1,
            billing_message_cost($client, (string) $contact['phone_e164'], $category), $cost);
    }

    // Log the words, not the template name — same reason the campaign path does.
    $body = channel_render_template_text($components, $cfg, $contact, null, (string) ($tpl['body_text'] ?? ''));
    if ($body === '') $body = '📄 Template: ' . $tpl['wa_name'];

    $id = msg_log($cid, $contactId, 'out', $body, [
        'type' => 'template', 'source' => 'manual',
        'status' => !empty($res['ok']) ? 'sent' : 'failed',
        'wamid' => $res['wamid'] ?? null,
        'error' => $res['error_title'] ?? null, 'error_code' => (string) ($res['error_code'] ?? ''),
    ]);
    // An agent sending by hand is taking the conversation over, same as a typed reply.
    if (!empty($res['ok'])) inbox_take_over($cid, $contactId);

    return ['ok' => !empty($res['ok']), 'id' => $id,
            'error' => !empty($res['ok']) ? '' : (string) ($res['error_title'] ?? 'Send failed.')];
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
