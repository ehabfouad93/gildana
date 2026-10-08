<?php
declare(strict_types=1);

/**
 * Control over the lead data — the part a sales manager worries about when salespeople leave:
 *
 *   phone numbers   hidden from Sales if the account wants (they still call and WhatsApp from the
 *                   buttons, and each number shown is recorded)
 *   permissions     export, see phone numbers, delete — ticked per person on the Team page
 *   recycle bin     a deleted lead can be brought back for 30 days
 *   requests        a salesperson asks to delete a lead (or to add one, if the account wants that
 *                   approved), and a manager decides
 *   imports         every import is recorded with the leads it made, and can be undone
 *   field history   every change to a lead's details is in its history, old → new
 *
 * Loaded by crm.php. Every function tolerates migration 051 not having run yet.
 */

const CRM_BIN_DAYS = 30;

/* ───────────────────────── permissions beyond pages ───────────────────────── */

/**
 * Things an Admin can let a person do, ticked on the Team page beside the CRM pages. Unlike
 * pages, these are off unless ticked: a new salesperson should not be able to walk out with
 * the phone list on their first day because nobody unticked a box.
 */
function perm_crm_actions(): array
{
    return [
        'export' => 'Export leads to Excel',
        'phones' => 'See phone numbers',
        'delete' => 'Delete leads (without asking a manager)',
        'origin' => 'See where leads come from: campaign, ad set, ad, direct from Meta',
    ];
}

/** May the signed-in person see a lead's campaign, ad set, ad and whether it came straight from Meta? Admins always; others when ticked. */
function crm_origin_visible(): bool
{
    static $cache = null;
    return $cache ??= function_exists('perm_context') && perm_context()[0] ? can_crm_action('origin') : true;
}

function can_crm_action(string $key): bool
{
    [$u] = perm_context();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'admin' || user_client_role($u) === 'admin') return true;
    if (!can_use('crm')) return false;
    $raw = $u['crm_pages'] ?? null;
    return $raw !== null && in_array($key, array_map('trim', explode(',', (string) $raw)), true);
}

/* ───────────────────────── phone numbers ───────────────────────── */

/** Are phone numbers hidden from the signed-in person? */
function crm_phone_hidden(): bool
{
    static $cache = null;
    if ($cache !== null) return $cache;
    [$u, $c] = perm_context();
    if (!$u || !$c) return $cache = false;
    if (!(int) (crm_settings((int) $c['id'])['hide_phones'] ?? 0)) return $cache = false;
    return $cache = !can_crm_action('phones');
}

/** +201012345840 → +20 ••• ••• 840: enough to tell two leads apart, not enough to dial. */
function crm_phone_mask(string $e164): string
{
    $d = preg_replace('/\D+/', '', $e164);
    if (strlen($d) < 6) return '•••';
    return '+' . substr($d, 0, 2) . ' ••• ••• ' . substr($d, -3);
}

/** The phone as this person may see it. */
function crm_phone_show(string $e164): string
{
    if (trim($e164) === '') return '';        // a Messenger / Instagram person with no number yet
    return crm_phone_hidden() ? crm_phone_mask($e164) : '+' . $e164;
}

/** Someone pressed Call or WhatsApp on a hidden number: hand it over, and write down who. */
function crm_phone_reveal(array $client, int $contactId, int $by, string $why): ?string
{
    $c = db_row("SELECT id, phone_e164, owner_user_id FROM contacts WHERE id=? AND client_id=?", [$contactId, (int) $client['id']]);
    if (!$c || !crm_can_see($c)) return null;
    crm_audit((int) $client['id'], $by, 'reveal', $contactId, $why);
    return (string) $c['phone_e164'];
}

/* ───────────────────────── the record of who did what ───────────────────────── */

function crm_audit(int $clientId, ?int $userId, string $action, ?int $contactId = null, ?string $detail = null, ?int $n = null): void
{
    try {
        db_run("INSERT INTO crm_audit (client_id,user_id,action,contact_id,detail,n,created_at) VALUES (?,?,?,?,?,?,NOW())",
               [$clientId, $userId ?: null, $action, $contactId, $detail !== null ? mb_substr($detail, 0, 1000) : null, $n]);
    } catch (Throwable $e) { error_log("crm_audit $action: " . $e->getMessage()); }
}

function crm_audit_words(array $a): string
{
    return match ((string) $a['action']) {
        'export'      => 'Exported ' . number_format((int) $a['n']) . ' lead' . ((int) $a['n'] === 1 ? '' : 's') . ' to Excel',
        'reveal'      => 'Opened a phone number to ' . ($a['detail'] === 'whatsapp' ? 'WhatsApp' : 'call'),
        'delete'      => 'Deleted a lead',
        'restore'     => 'Brought a lead back from the bin',
        'purge'       => 'Emptied ' . (int) $a['n'] . ' lead' . ((int) $a['n'] === 1 ? '' : 's') . ' from the bin',
        'undo_import' => 'Undid an import' . ($a['detail'] ? ' — ' . $a['detail'] : ''),
        default       => (string) $a['action'],
    };
}

/* ───────────────────────── field history ───────────────────────── */

/**
 * Write each changed field of a lead into its history. $before/$after are rows or partial rows;
 * only the keys named in $labels are compared. Values are shown the way people read them.
 */
function crm_log_fields(int $clientId, int $contactId, array $before, array $after, ?int $by, array $labels): void
{
    foreach ($labels as $k => $label) {
        if (!array_key_exists($k, $after)) continue;
        $old = trim((string) ($before[$k] ?? '')); $new = trim((string) ($after[$k] ?? ''));
        if ($k === 'deal_value') { $old = $old !== '' ? number_format((float) $old) : ''; $new = $new !== '' ? number_format((float) $new) : ''; }
        if ($old === $new) continue;
        try {
            db_run("INSERT INTO crm_events (client_id,contact_id,user_id,kind,field,from_val,to_val,created_at) VALUES (?,?,?,'field',?,?,?,NOW())",
                   [$clientId, $contactId, $by, mb_substr($label, 0, 40), $old !== '' ? mb_substr($old, 0, 255) : null, $new !== '' ? mb_substr($new, 0, 255) : null]);
        } catch (Throwable $e) {
            crm_log($clientId, $contactId, 'field', $old !== '' ? mb_substr($old, 0, 64) : null, mb_substr($label . ': ' . $new, 0, 64), $by);
        }
    }
}

/** The fields tracked on a lead, with the words the history uses for them. */
function crm_tracked_fields(int $clientId): array
{
    $f = ['name' => 'Name', 'email' => 'Email', 'deal_value' => 'Deal value', 'project' => 'Project', 'unit_type' => 'Unit type',
          'budget' => 'Budget', 'payment_pref' => 'Paying', 'qualification' => 'Qualified', 'data_type' => 'Fresh / cold', 'campaign' => 'Campaign'];
    foreach (crm_fields($clientId, false) as $cf) $f['cf:' . $cf['fkey']] = (string) $cf['label'];
    return $f;
}

/** A lead as the history compares it: names instead of ids, words instead of codes. */
function crm_field_snapshot(int $clientId, array $lead): array
{
    $pn = crm_project_names($clientId);
    $s = [
        'name' => (string) ($lead['name'] ?? ''), 'email' => (string) ($lead['email'] ?? ''), 'deal_value' => $lead['deal_value'] ?? '',
        'project' => (string) ($pn[(int) ($lead['project_id'] ?? 0)] ?? ''), 'unit_type' => (string) ($lead['unit_type'] ?? ''),
        'budget' => (string) ($lead['budget'] ?? ''),
        'payment_pref' => ['cash' => 'Cash', 'installments' => 'Instalments'][(string) ($lead['payment_pref'] ?? '')] ?? '',
        'qualification' => crm_qualifications()[(string) ($lead['qualification'] ?? '')] ?? '',
        'data_type' => crm_data_types()[(string) ($lead['data_type'] ?? '')] ?? '', 'campaign' => (string) ($lead['campaign'] ?? ''),
    ];
    $custom = crm_custom_get($lead);
    foreach (crm_fields($clientId, false) as $cf) $s['cf:' . $cf['fkey']] = crm_custom_show($cf, $custom[$cf['fkey']] ?? '');
    return $s;
}

/** Re-read a lead and log what changed since $before (a snapshot). */
function crm_log_changes(int $clientId, int $contactId, array $before, ?int $by): void
{
    $now = db_row("SELECT * FROM contacts WHERE id=?", [$contactId]);
    if (!$now) return;
    $after = crm_field_snapshot($clientId, $now);
    crm_log_fields($clientId, $contactId, $before, $after, $by, crm_tracked_fields($clientId));
    $changed = [];
    foreach ($after as $k => $v) if (($before[$k] ?? null) !== $v) $changed[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
    if ($changed && function_exists('crm_hook')) crm_hook('lead.updated', $clientId, $contactId, ['changes' => $changed]);
}

/* ───────────────────────── recycle bin ───────────────────────── */

/**
 * Delete a lead: out of the pipeline, every list and report, its automatic messages stopped — and
 * kept in the bin for 30 days in case it was a mistake. The contact and its conversation stay.
 */
function crm_delete_lead(array $client, int $contactId, ?int $by): bool
{
    if (!db_has_column('contacts', 'deleted_at')) return false;
    $cid = (int) $client['id'];
    $c = db_row("SELECT id, stage_id FROM contacts WHERE id=? AND client_id=? AND stage_id IS NOT NULL", [$contactId, $cid]);
    if (!$c) return false;
    db_run("UPDATE contacts SET deleted_at=NOW(), deleted_by=?, deleted_stage_id=stage_id, stage_id=NULL WHERE id=?", [$by, $contactId]);
    crm_log($cid, $contactId, 'deleted', (string) $c['stage_id'], null, $by);
    if (function_exists('crm_seq_stop_all')) crm_seq_stop_all($contactId, 'The lead was deleted.');
    try { db_run("UPDATE crm_msg_queue SET status='skipped', error='The lead was deleted.' WHERE contact_id=? AND status='queued'", [$contactId]); } catch (Throwable $e) {}
    crm_audit($cid, $by, 'delete', $contactId);
    if (function_exists('crm_hook')) crm_hook('lead.deleted', $cid, $contactId);
    return true;
}

/** Bring a lead back from the bin, to the stage it was in (or the first, if that stage is gone). */
function crm_restore_lead(array $client, int $contactId, ?int $by): bool
{
    $cid = (int) $client['id'];
    $c = db_row("SELECT id, deleted_stage_id FROM contacts WHERE id=? AND client_id=? AND deleted_at IS NOT NULL", [$contactId, $cid]);
    if (!$c) return false;
    $stage = (int) ($c['deleted_stage_id'] ?? 0);
    if (!$stage || !isset(crm_stage_map($cid)[$stage])) $stage = crm_first_stage($cid);
    db_run("UPDATE contacts SET stage_id=?, deleted_at=NULL, deleted_by=NULL, deleted_stage_id=NULL WHERE id=?", [$stage, $contactId]);
    crm_log($cid, $contactId, 'restored', null, (string) $stage, $by);
    crm_audit($cid, $by, 'restore', $contactId);
    return true;
}

/** What is in the bin, newest first. */
function crm_bin(int $clientId): array
{
    try {
        return db_all("SELECT c.id, c.name, c.phone_e164, c.code, c.deleted_at, c.deleted_stage_id, c.owner_user_id,
                              COALESCE(NULLIF(u.name,''), u.email) AS deleted_by_name, COALESCE(NULLIF(o.name,''), o.email) AS owner_name
                         FROM contacts c LEFT JOIN users u ON u.id=c.deleted_by LEFT JOIN users o ON o.id=c.owner_user_id
                        WHERE c.client_id=? AND c.deleted_at IS NOT NULL ORDER BY c.deleted_at DESC LIMIT 500", [$clientId]);
    } catch (Throwable $e) { return []; }
}

/** After 30 days a lead leaves the bin for good (the contact and its messages stay in Contacts). */
function crm_bin_purge(): int
{
    try {
        $rows = db_all("SELECT id, client_id FROM contacts WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL " . CRM_BIN_DAYS . " DAY LIMIT 1000");
    } catch (Throwable $e) { return 0; }
    $by = [];
    foreach ($rows as $r) {
        db_run("UPDATE contacts SET deleted_at=NULL, deleted_by=NULL, deleted_stage_id=NULL, owner_user_id=NULL WHERE id=?", [(int) $r['id']]);
        $by[(int) $r['client_id']] = ($by[(int) $r['client_id']] ?? 0) + 1;
    }
    foreach ($by as $cid => $n) crm_audit($cid, null, 'purge', null, null, $n);
    return count($rows);
}

/* ───────────────────────── requests a manager decides ───────────────────────── */

/** A salesperson asks to delete one of their leads. */
function crm_request_delete(array $client, int $contactId, int $by, string $reason): array
{
    $cid = (int) $client['id'];
    if (db_val("SELECT COUNT(*) FROM crm_requests WHERE client_id=? AND kind='delete' AND contact_id=? AND status='pending'", [$cid, $contactId])) {
        return ['ok' => false, 'error' => 'A manager has already been asked to delete this lead.'];
    }
    $reason = mb_substr(trim($reason), 0, 255);
    if ($reason === '') return ['ok' => false, 'error' => 'Say why it should be deleted.'];
    $id = db_insert("INSERT INTO crm_requests (client_id,kind,user_id,contact_id,reason,created_at) VALUES (?,'delete',?,?,?,NOW())", [$cid, $by, $contactId, $reason]);
    foreach (crm_admin_ids($cid) as $a) crm_notice($cid, $a, 'request', $contactId, ['req' => $id, 'what' => 'delete', 'by' => crm_user_name($by)]);
    crm_log($cid, $contactId, 'del_request', null, mb_substr($reason, 0, 64), $by);
    return ['ok' => true, 'id' => $id];
}

/** A salesperson's new lead, waiting for a manager (when the account asks for approval). */
function crm_request_create(array $client, int $by, array $lead): array
{
    $cid = (int) $client['id'];
    $id = db_insert("INSERT INTO crm_requests (client_id,kind,user_id,payload,reason,created_at) VALUES (?,'create',?,?,?,NOW())",
                    [$cid, $by, json_encode($lead, JSON_UNESCAPED_UNICODE), mb_substr(trim((string) ($lead['note'] ?? '')), 0, 255) ?: null]);
    foreach (crm_admin_ids($cid) as $a) crm_notice($cid, $a, 'request', null, ['req' => $id, 'what' => 'create', 'by' => crm_user_name($by), 'name' => (string) ($lead['name'] ?? '')]);
    return ['ok' => true, 'id' => $id];
}

function crm_requests(int $clientId, string $status = 'pending'): array
{
    try {
        return db_all("SELECT r.*, COALESCE(NULLIF(u.name,''), u.email) AS by_name, COALESCE(NULLIF(d.name,''), d.email) AS decided_name,
                              c.name AS lead_name, c.phone_e164, c.code, c.owner_user_id
                         FROM crm_requests r LEFT JOIN users u ON u.id=r.user_id LEFT JOIN users d ON d.id=r.decided_by
                         LEFT JOIN contacts c ON c.id=r.contact_id
                        WHERE r.client_id=? AND r.status" . ($status === 'pending' ? "='pending'" : "<>'pending'") . "
                        ORDER BY r.created_at DESC LIMIT 200", [$clientId]);
    } catch (Throwable $e) { return []; }
}

/**
 * A manager decides. Approving a delete puts the lead in the bin; approving a new lead adds it,
 * owned by whoever asked. Either way the person who asked is told.
 */
function crm_request_decide(array $client, int $requestId, bool $approve, int $by, string $answer = ''): array
{
    $cid = (int) $client['id'];
    $r = db_row("SELECT * FROM crm_requests WHERE id=? AND client_id=? AND status='pending'", [$requestId, $cid]);
    if (!$r) return ['ok' => false, 'error' => 'That request was already decided.'];
    $contactId = $r['contact_id'] !== null ? (int) $r['contact_id'] : null;
    if ($approve && $r['kind'] === 'delete') {
        if (!crm_delete_lead($client, (int) $contactId, $by)) return ['ok' => false, 'error' => 'That lead is no longer in the pipeline.'];
    }
    if ($approve && $r['kind'] === 'create') {
        $made = crm_create_lead($client, json_decode((string) $r['payload'], true) ?: [], (int) $r['user_id'], $by);
        if (!$made['ok']) return $made;
        $contactId = $made['id'];
    }
    db_run("UPDATE crm_requests SET status=?, decided_by=?, decided_at=NOW(), answer=?, contact_id=COALESCE(contact_id, ?) WHERE id=?",
           [$approve ? 'approved' : 'refused', $by, mb_substr(trim($answer), 0, 255) ?: null, $contactId, $requestId]);
    crm_notice($cid, (int) $r['user_id'], 'request_done', $contactId, ['what' => $r['kind'], 'ok' => $approve, 'answer' => trim($answer),
               'name' => (string) ((json_decode((string) $r['payload'], true) ?: [])['name'] ?? '')]);
    return ['ok' => true, 'contact_id' => $contactId];
}

/**
 * Add a lead as typed on the Add lead form (also used when a manager approves one). A number that
 * is already a lead is refused, saying whose.
 *
 * @return array{ok:bool, id?:int, error?:string, reused?:bool}
 */
function crm_create_lead(array $client, array $d, $owner, ?int $by): array
{
    $cid = (int) $client['id'];
    $phone = normalize_phone((string) ($d['phone'] ?? ''), (string) ($client['default_country'] ?? ''));
    if ($phone === '') return ['ok' => false, 'error' => 'Enter a valid phone number, with the country code or a local number.'];
    $name = trim((string) ($d['name'] ?? '')); $email = trim((string) ($d['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'error' => 'That email address does not look right.'];
    $existing = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
    if ($existing && $existing['stage_id'] !== null) {
        $who = crm_can_see($existing) ? ' (' . crm_user_name($existing['owner_user_id'] !== null ? (int) $existing['owner_user_id'] : null) . ')' : '';
        return ['ok' => false, 'error' => 'That number is already a lead' . $who . '.', 'id' => crm_can_see($existing) ? (int) $existing['id'] : 0, 'exists' => true];
    }
    if ($existing) {
        db_run("UPDATE contacts SET name=COALESCE(NULLIF(?,''),name), email=COALESCE(NULLIF(?,''),email), deleted_at=NULL, deleted_by=NULL, deleted_stage_id=NULL WHERE id=?",
               [$name, $email, (int) $existing['id']]);
        $contactId = (int) $existing['id'];
    } else {
        $contactId = db_insert("INSERT INTO contacts (client_id,phone_e164,name,email,opt_in_status,source,created_at) VALUES (?,?,?,?, 'in','manual',NOW())",
                               [$cid, $phone, $name, $email !== '' ? $email : null]);
    }
    if (db_has_column('contacts', 'project_id')) {
        $pj = (int) ($d['project_id'] ?? 0);
        db_run("UPDATE contacts SET project_id=?, unit_type=NULLIF(?,''), budget=NULLIF(?,'') WHERE id=?",
               [isset(crm_project_names($cid)[$pj]) ? $pj : null, mb_substr(trim((string) ($d['unit_type'] ?? '')), 0, 80),
                mb_substr(trim((string) ($d['budget'] ?? '')), 0, 80), $contactId]);
    }
    crm_add_lead($client, $contactId, 'manual', $owner === '' || $owner === null ? 'auto' : $owner, (int) ($d['stage_id'] ?? 0) ?: null, $by);
    if (($camp = trim((string) ($d['campaign'] ?? ''))) !== '') crm_set_origin($contactId, ['campaign' => $camp]);
    $fu = trim((string) ($d['followup'] ?? ''));
    db_run("UPDATE contacts SET deal_value=?, next_followup_at=? WHERE id=?",
           [crm_deal_value((string) ($d['deal_value'] ?? $d['value'] ?? ''), $phone), $fu !== '' && strtotime($fu) ? date('Y-m-d H:i:s', strtotime($fu)) : null, $contactId]);
    crm_add_note($client, $contactId, (string) ($d['note'] ?? ''), $by);
    return ['ok' => true, 'id' => $contactId, 'reused' => (bool) $existing];
}

/* ───────────────────────── imports ───────────────────────── */

function crm_imports(int $clientId): array
{
    try {
        return db_all("SELECT i.*, COALESCE(NULLIF(u.name,''), u.email) AS by_name,
                              (SELECT COUNT(*) FROM crm_import_items t WHERE t.import_id=i.id AND t.new_lead=1) AS undoable
                         FROM crm_imports i LEFT JOIN users u ON u.id=i.user_id WHERE i.client_id=? ORDER BY i.id DESC LIMIT 100", [$clientId]);
    } catch (Throwable $e) { return []; }
}

/**
 * Undo an import: take out the leads it made. Contacts it created, that nobody has talked to
 * since, go entirely; anything someone has already worked on (an activity, a message, a stage
 * move by a person) is kept and counted, so undoing never throws away a salesperson's work.
 * Details it updated on existing contacts are not rolled back — that is said on the screen.
 *
 * @return array{ok:bool, removed?:int, kept?:int, error?:string}
 */
function crm_import_undo(array $client, int $importId, int $by): array
{
    $cid = (int) $client['id'];
    $imp = db_row("SELECT * FROM crm_imports WHERE id=? AND client_id=?", [$importId, $cid]);
    if (!$imp) return ['ok' => false, 'error' => 'Import not found.'];
    if ($imp['undone_at']) return ['ok' => false, 'error' => 'That import was already undone.'];
    $removed = 0; $kept = 0;
    foreach (db_all("SELECT * FROM crm_import_items WHERE import_id=? AND new_lead=1", [$importId]) as $it) {
        $id = (int) $it['contact_id'];
        $c = db_row("SELECT id, stage_id, created_at FROM contacts WHERE id=? AND client_id=?", [$id, $cid]);
        if (!$c) continue;
        $worked = (int) db_val("SELECT COUNT(*) FROM crm_notes WHERE contact_id=? AND created_at > ?", [$id, $imp['created_at']])
                + (int) db_val("SELECT COUNT(*) FROM messages WHERE contact_id=? AND created_at > ?", [$id, $imp['created_at']])
                + (int) db_val("SELECT COUNT(*) FROM crm_events WHERE contact_id=? AND user_id IS NOT NULL AND user_id<>? AND created_at > ?", [$id, (int) $imp['user_id'], $imp['created_at']]);
        if ($worked) { $kept++; continue; }
        if ((int) $it['new_contact']) {
            db_run("DELETE FROM contacts WHERE id=? AND client_id=?", [$id, $cid]);
        } else {
            db_run("UPDATE contacts SET stage_id=NULL, owner_user_id=NULL WHERE id=?", [$id]);
            crm_log($cid, $id, 'removed', $c['stage_id'] !== null ? (string) $c['stage_id'] : null, null, $by);
        }
        $removed++;
    }
    $note = $removed . ' removed' . ($kept ? ', ' . $kept . ' kept because someone already worked on them' : '');
    db_run("UPDATE crm_imports SET undone_at=NOW(), undone_by=?, undo_note=? WHERE id=?", [$by, $note, $importId]);
    crm_audit($cid, $by, 'undo_import', null, (string) ($imp['filename'] ?? '') . ' — ' . $note, $removed);
    return ['ok' => true, 'removed' => $removed, 'kept' => $kept];
}
