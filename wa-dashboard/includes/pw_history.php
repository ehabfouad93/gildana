<?php
declare(strict_types=1);

/**
 * A linked phone's past chats, brought into Revenect.
 *
 * When a phone links (QR or pairing code), WhatsApp hands the linked device a copy of the phone's
 * chat history — the same copy WhatsApp Web shows. The gateway is asked for the whole of it
 * (syncFullHistory) and passes it on two ways, both handled here and both safe to repeat:
 *
 *   - as it arrives: the MESSAGING_HISTORY_SET webhook event (webhook_personal.php)
 *   - afterwards: the messages the gateway stores, read page by page by the worker
 *     (pw_history_tick), which also covers a phone that was linked before this existed
 *
 * History is history: it fills the conversations in the Inbox (each on its own number), adds
 * people who were not contacts yet, and nothing else — no automation, AI reply, CRM lead,
 * notification or unread count comes from it. Group chats are left out. Each message is stored
 * once, whichever way it came (its WhatsApp message id).
 */

require_once __DIR__ . '/personal_wa.php';
require_once __DIR__ . '/sending.php';

function pw_history_ready(): bool
{
    static $ok = null;
    if ($ok === null) { try { db_val("SELECT 1 FROM pw_history LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

/**
 * Queue an import of this line's past chats (once at a time per line). $nudge: the phone has just
 * handed over another part of its history — a waiting import waits a little longer, so the parts
 * that arrive together are read in one go.
 */
function pw_history_start(array $client, ?string $line = null, bool $nudge = false, bool $now = false): void
{
    if (!pw_history_ready()) return;
    $line ??= wa_line_of($client);
    if (!wa_line_valid($line)) return;
    $open = db_row("SELECT id, status FROM pw_history WHERE client_id=? AND line=? AND status IN ('queued','running') ORDER BY id DESC LIMIT 1", [(int) $client['id'], $line]);
    if ($open) {
        if ($nudge && $open['status'] === 'queued') db_run("UPDATE pw_history SET next_at=GREATEST(next_at, NOW() + INTERVAL 90 SECOND) WHERE id=?", [(int) $open['id']]);
        return;
    }
    // A short wait: the phone sends its history in the first minutes after linking.
    // $now: asked for by hand (the Inbox's Sync button) — the next worker run starts it.
    db_insert("INSERT INTO pw_history (client_id,line,status,page,next_at,created_at,updated_at) VALUES (?,?, 'queued', 1, NOW() + INTERVAL ? SECOND, NOW(), NOW())",
              [(int) $client['id'], $line, $now ? 0 : 120]);
}

/** The latest import of a line, for Settings. */
function pw_history_last(int $clientId, string $line): ?array
{
    if (!pw_history_ready()) return null;
    return db_row("SELECT * FROM pw_history WHERE client_id=? AND line=? ORDER BY id DESC LIMIT 1", [$clientId, $line]) ?: null;
}

/**
 * Store one past message. $client is the row for the phone's line. Returns 'imported', 'seen'
 * (already here), or 'skipped' (a group, a status update, nothing to keep).
 */
function pw_history_store(array $client, array $raw): string
{
    if (str_contains((string) ($raw['key']['remoteJid'] ?? ''), '@broadcast')) return 'skipped';   // status updates
    $m = pw_parse_one($raw);
    if ($m === null) return 'skipped';
    $cid = (int) $client['id'];
    $wamid = (string) $m['wamid'];

    // The contact: by the exact address first, then the number — as for a live message — then,
    // for a hidden number, the id's digits it was filed under.
    $jid = (string) ($m['jid'] ?? '');
    $c = $jid !== '' ? db_row("SELECT * FROM contacts WHERE client_id=? AND wa_jid=?", [$cid, $jid]) : null;
    if (!$c) $c = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, (string) $m['from']]);
    $lidDigits = !empty($m['is_lid']) ? (preg_replace('/\D+/', '', explode('@', $jid)[0]) ?? '') : '';
    if (!$c && $lidDigits !== '') $c = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $lidDigits]);
    // The real number, where this message reveals it: a contact known only by WhatsApp's internal
    // id gets it (unless another contact already has that number). Done even for a message already
    // stored — re-syncing is how old conversations get their numbers.
    if ($c && !empty($m['mapped']) && (string) $c['phone_e164'] !== (string) $m['from']
        && ($lidDigits !== '' && (string) $c['phone_e164'] === $lidDigits || (string) $c['phone_e164'] === '')
        && !db_val("SELECT 1 FROM contacts WHERE client_id=? AND phone_e164=? AND id<>?", [$cid, (string) $m['from'], (int) $c['id']])) {
        db_run("UPDATE contacts SET phone_e164=?, wa_jid=COALESCE(wa_jid, NULLIF(?,'')) WHERE id=?", [(string) $m['from'], $jid, (int) $c['id']]);
        $c = db_row("SELECT * FROM contacts WHERE id=?", [(int) $c['id']]);
    }
    if ($wamid !== '' && db_val("SELECT 1 FROM messages WHERE wa_message_id=? AND client_id=? LIMIT 1", [$wamid, $cid])) return 'seen';
    if ($m['text'] === '' && $m['type'] === 'text') return 'skipped';                              // protocol messages, reactions…
    $at = $m['ts'] ? date('Y-m-d H:i:s', $m['ts']) : null;
    if (!$c) {
        // Someone from the phone's past: a contact (so the conversation has a name), not a new lead.
        $id = db_insert("INSERT INTO contacts (client_id,phone_e164,wa_jid,name,opt_in_status,source,created_at,inbox_read_at)
                         VALUES (?,?,?,?, 'in', 'phone_history', ?, NOW())",
                        [$cid, (string) $m['from'], $jid ?: null, $m['from_me'] ? '' : mb_substr((string) $m['name'], 0, 150), $at ?? date('Y-m-d H:i:s')]);
        $c = db_row("SELECT * FROM contacts WHERE id=?", [$id]);
    } elseif (!$m['from_me'] && trim((string) $c['name']) === '' && $m['name'] !== '') {
        db_run("UPDATE contacts SET name=? WHERE id=?", [mb_substr((string) $m['name'], 0, 150), (int) $c['id']]);
    }
    if (!$c) return 'skipped';
    $line = wa_line_of($client);
    msg_log($cid, (int) $c['id'], $m['from_me'] ? 'out' : 'in', $m['text'] !== '' ? $m['text'] : '[' . $m['type'] . ']', [
        'type' => $m['type'], 'source' => 'history', 'status' => $m['from_me'] ? 'sent' : null, 'wamid' => $wamid !== '' ? $wamid : null,
        'via' => $line[0] === 'u' ? 'own' : 'company_personal', 'line' => $line,
        'media_ref' => $m['media'][0] ?? null, 'media_mime' => $m['media'][1] ?? null, 'media_name' => $m['media'][2] ?? null,
        'at' => $at, 'quiet' => true,
    ]);
    // Never unread: the unread counts leave out source 'history' (inbox.php, view.php).
    return 'imported';
}

/** A batch of past messages (the history webhook, or a page from the gateway). Returns [imported, skipped]. */
function pw_history_store_all(array $client, array $records): array
{
    $n = [0, 0];
    foreach ($records as $r) {
        if (!is_array($r)) continue;
        try { $res = pw_history_store($client, $r); } catch (Throwable $e) { $res = 'skipped'; }
        if ($res === 'imported') $n[0]++; elseif ($res === 'skipped') $n[1]++;
    }
    return $n;
}

/**
 * Phones that were already linked before history import existed (or whose first import never
 * ran): import them once, by themselves.
 */
function pw_history_autostart(): void
{
    $have = fn(int $cid, string $line) => (bool) db_val("SELECT 1 FROM pw_history WHERE client_id=? AND line=? LIMIT 1", [$cid, $line]);
    foreach (db_all("SELECT * FROM clients WHERE status='active' AND personal_status='connected' AND COALESCE(personal_instance,'')<>''") as $c) {
        if (!$have((int) $c['id'], 'phone')) pw_history_start($c + ['__line' => 'phone'], 'phone');
    }
    try {
        foreach (db_all("SELECT id, client_id FROM wa_numbers WHERE kind='personal' AND active=1 AND status='connected'") as $n) {
            if (!$have((int) $n['client_id'], 'n' . $n['id'])) pw_history_start(['id' => (int) $n['client_id']], 'n' . $n['id']);
        }
        foreach (db_all("SELECT user_id, client_id FROM user_channels WHERE status='connected'") as $u) {
            if (!$have((int) $u['client_id'], 'u' . $u['user_id'])) pw_history_start(['id' => (int) $u['client_id']], 'u' . $u['user_id']);
        }
    } catch (Throwable $e) {}
}

/** The worker: read the gateway's stored messages for each queued line, a few pages a run. */
function pw_history_tick(int $pagesPerRun = 10): int
{
    if (!pw_history_ready() || !pw_configured()) return 0;
    pw_history_autostart();
    $done = 0;
    foreach (db_all("SELECT * FROM pw_history WHERE status IN ('queued','running') AND next_at <= NOW() ORDER BY id LIMIT 5") as $job) {
        $base = db_row("SELECT * FROM clients WHERE id=?", [(int) $job['client_id']]);
        $client = $base ? wa_line_client($base, (string) $job['line']) : null;
        if (!$client || ($client['personal_status'] ?? '') !== 'connected') {
            db_run("UPDATE pw_history SET status='failed', error=?, updated_at=NOW() WHERE id=?", ['The phone is not linked.', (int) $job['id']]);
            continue;
        }
        db_run("UPDATE pw_history SET status='running', updated_at=NOW() WHERE id=?", [(int) $job['id']]);
        $page = (int) $job['page']; $imp = 0; $skip = 0; $finished = false; $err = '';
        for ($i = 0; $i < $pagesPerRun; $i++, $page++) {
            $r = pw_fetch_messages($client, $page, 100);
            if (!$r['ok']) { $err = $r['error']; break; }
            if (!$r['records']) { $finished = true; break; }
            [$a, $b] = pw_history_store_all($client, $r['records']);
            $imp += $a; $skip += $b;
            if ($r['pages'] && $page >= $r['pages']) { $finished = true; $page++; break; }
            if ($page >= 2000) { $finished = true; break; }                       // 200,000 messages: enough
        }
        if ($err !== '') {
            $tries = (int) $job['tries'] + 1;
            db_run("UPDATE pw_history SET status=?, tries=?, error=?, imported=imported+?, skipped=skipped+?, page=?, next_at=NOW() + INTERVAL ? MINUTE, updated_at=NOW() WHERE id=?",
                   [$tries >= 5 ? 'failed' : 'running', $tries, mb_substr($err, 0, 255), $imp, $skip, $page, 5 * $tries, (int) $job['id']]);
            continue;
        }
        db_run("UPDATE pw_history SET status=?, page=?, pages=?, imported=imported+?, skipped=skipped+?, error=NULL, next_at=NOW(), updated_at=NOW() WHERE id=?",
               [$finished ? 'done' : 'running', $page, null, $imp, $skip, (int) $job['id']]);
        $done += $imp;
    }
    return $done;
}
