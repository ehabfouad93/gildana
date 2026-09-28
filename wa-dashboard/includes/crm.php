<?php
declare(strict_types=1);

/**
 * The CRM: stages, ownership, round-robin assignment, and the history reports are built from.
 *
 * A lead is a contact with a stage (see migrations/033_crm.sql for why there is no leads table).
 * Everything that changes a lead's stage or owner goes through this file, so crm_events is a
 * complete history — a report is only as good as the record it counts, and a single path that
 * updated contacts directly would leave holes nobody could see.
 */

require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/crm_manager.php';     // rules, scoring, notices, merge — the manager's side

/** The pipeline a client starts with. Real-estate shaped, because that is who uses this. */
function crm_default_stages(): array
{
    return [
        ['New',         'open'],
        ['Contacted',   'open'],
        ['Viewing',     'open'],
        ['Negotiating', 'open'],
        ['Won',         'won'],
        ['Lost',        'lost'],
    ];
}

function crm_enabled(array $client): bool
{
    return in_array('crm', client_modules($client), true);
}

/**
 * The client's stages in order, created on first use.
 *
 * Seeded here rather than in the migration so a client created next year gets them too.
 */
function crm_stages(int $clientId): array
{
    $rows = db_all("SELECT * FROM crm_stages WHERE client_id=? ORDER BY sort, id", [$clientId]);
    if ($rows) return $rows;
    foreach (crm_default_stages() as $i => [$name, $kind]) {
        db_insert("INSERT INTO crm_stages (client_id,name,sort,kind,created_at) VALUES (?,?,?,?,NOW())",
                  [$clientId, $name, ($i + 1) * 10, $kind]);
    }
    return db_all("SELECT * FROM crm_stages WHERE client_id=? ORDER BY sort, id", [$clientId]);
}

/** id => stage row. */
function crm_stage_map(int $clientId): array
{
    $m = [];
    foreach (crm_stages($clientId) as $s) $m[(int) $s['id']] = $s;
    return $m;
}

/** Where a new lead lands: the first open stage. */
function crm_first_stage(int $clientId): int
{
    foreach (crm_stages($clientId) as $s) {
        if ($s['kind'] === 'open') return (int) $s['id'];
    }
    return (int) (crm_stages($clientId)[0]['id'] ?? 0);
}

/** The signed-in person, when there is one — NULL when the system is acting (a webhook). */
function crm_actor_id(): ?int
{
    [$u] = function_exists('perm_context') ? perm_context() : [[]];
    $id = (int) ($u['id'] ?? 0);
    return $id > 0 ? $id : null;
}

function crm_log(int $clientId, int $contactId, string $kind, ?string $from, ?string $to, ?int $by = null): void
{
    db_run("INSERT INTO crm_events (client_id,contact_id,user_id,kind,from_val,to_val,created_at)
            VALUES (?,?,?,?,?,?,NOW())", [$clientId, $contactId, $by, $kind, $from, $to]);
}

/**
 * People a lead can be given to: active users on the account, Sales first.
 *
 * @return array<int, array{id:int, name:string, client_role:string}>
 */
function crm_assignable_users(int $clientId): array
{
    return db_all(
        "SELECT id, COALESCE(NULLIF(name,''), email) AS name, email, client_role
           FROM users
          WHERE client_id=? AND role='client' AND status='active'
          ORDER BY (client_role='sales') DESC, name", [$clientId]);
}

/**
 * Who round-robin deals to: active Sales users who can actually see a lead.
 *
 * A salesperson whose access has been narrowed to exclude both the CRM and the Inbox would be
 * handed leads they could never open, so they are left out of the rotation.
 */
function crm_rotation(array $client): array
{
    $ids = [];
    $rows = db_all("SELECT * FROM users WHERE client_id=? AND role='client' AND client_role='sales'
                     AND status='active' ORDER BY id", [(int) $client['id']]);
    foreach ($rows as $u) {
        $mods = user_modules($u, $client);
        if (in_array('crm', $mods, true) || in_array('inbox', $mods, true)) $ids[] = (int) $u['id'];
    }
    return $ids;
}

/**
 * The next salesperson in rotation, or NULL when there is nobody to give it to.
 *
 * The pointer is read and moved inside one transaction with the client row locked. Without the
 * lock, two leads arriving in the same second both read the same pointer and both land on the
 * same person — which is exactly when fairness matters, because a burst is a campaign landing.
 */
function crm_assign_next(array $client, ?array $lead = null, array $exclude = []): ?int
{
    // The assignment rules decide (crm_manager.php); with none, the whole sales team in turn.
    [$uid] = crm_pick_owner($client, $lead, $exclude);
    return $uid;
}

/** Ask the worker to ping this one person's devices. Collapses like the client outbox does. */
function crm_notify_user(int $userId, int $clientId): void
{
    try {
        db_run("INSERT INTO push_outbox_user (user_id, client_id, queued_at) VALUES (?,?,NOW())
                ON DUPLICATE KEY UPDATE queued_at = queued_at", [$userId, $clientId]);
    } catch (Throwable $e) {
        error_log('crm_notify_user skipped: ' . $e->getMessage());
    }
}

function crm_user_name(?int $userId): string
{
    if (!$userId) return 'Unassigned';
    $u = db_row("SELECT name, email FROM users WHERE id=?", [$userId]);
    return $u ? ((string) ($u['name'] ?: $u['email'])) : 'Unknown';
}

/**
 * Give a lead to someone (or to nobody). Logged, and the new owner is notified.
 */
function crm_assign(array $client, int $contactId, ?int $userId, ?int $by = null): void
{
    $cid = (int) $client['id'];
    $cur = db_row("SELECT owner_user_id FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid]);
    if (!$cur) return;
    $from = $cur['owner_user_id'] !== null ? (int) $cur['owner_user_id'] : null;
    if ($from === $userId) return;

    // Only someone on this account. A forged user id from another client is dropped, not trusted.
    if ($userId !== null && !db_val("SELECT COUNT(*) FROM users WHERE id=? AND client_id=? AND status='active'", [$userId, $cid])) {
        return;
    }
    db_run("UPDATE contacts SET owner_user_id=?, assigned_at=IF(? IS NULL, NULL, NOW()) WHERE id=? AND client_id=?",
           [$userId, $userId, $contactId, $cid]);
    // A new owner gets a fresh clock: the response alert and the stale alert start over for them.
    if (db_has_column('contacts', 'sla_alerted_at')) {
        db_run("UPDATE contacts SET sla_alerted_at=NULL, stale_alerted_at=NULL" . ($by !== null ? ", reclaims=0" : "") . " WHERE id=?", [$contactId]);
    }
    crm_log($cid, $contactId, 'assigned', $from !== null ? (string) $from : null,
            $userId !== null ? (string) $userId : null, $by);
    if ($userId !== null && $userId !== $by) crm_notify_user($userId, $cid);
}

function crm_set_stage(array $client, int $contactId, int $stageId, ?int $by = null,
                       ?string $lostReason = null, ?string $lostNote = null): bool
{
    $cid = (int) $client['id'];
    $map = crm_stage_map($cid);
    if (!isset($map[$stageId])) return false;
    $cur = db_row("SELECT stage_id FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid]);
    if (!$cur) return false;
    if ((int) $cur['stage_id'] === $stageId) return true;
    db_run("UPDATE contacts SET stage_id=? WHERE id=? AND client_id=?", [$stageId, $contactId, $cid]);
    crm_log($cid, $contactId, 'stage', $cur['stage_id'] !== null ? (string) $cur['stage_id'] : null, (string) $stageId, $by);
    if ($map[$stageId]['kind'] === 'lost' && db_has_column('contacts', 'lost_reason')) {
        $lostReason = $lostReason !== null ? mb_substr(trim($lostReason), 0, 80) : null;
        db_run("UPDATE contacts SET lost_reason=?, lost_note=? WHERE id=?",
               [$lostReason ?: null, $lostNote !== null && trim($lostNote) !== '' ? mb_substr(trim($lostNote), 0, 255) : null, $contactId]);
        if ($lostReason) crm_log($cid, $contactId, 'lost', null, $lostReason, $by);
    }
    if ($by !== null) crm_touch($contactId);
    crm_rescore($contactId);
    return true;
}

/** Moving to this stage needs a reason first (a lost stage, when the account asks for one). */
function crm_needs_lost_reason(int $clientId, int $stageId): bool
{
    return crm_stage_kind($clientId, $stageId) === 'lost' && (int) crm_settings($clientId)['require_lost_reason'] === 1;
}

function crm_add_note(array $client, int $contactId, string $body, ?int $by = null): void
{
    $body = trim($body);
    if ($body === '') return;
    db_insert("INSERT INTO crm_notes (client_id,contact_id,user_id,body,created_at) VALUES (?,?,?,?,NOW())",
              [(int) $client['id'], $contactId, $by, mb_substr($body, 0, 5000)]);
}

/** The kinds of thing a salesperson logs against a lead. */
function crm_activity_kinds(): array
{
    return ['call' => 'Call', 'whatsapp' => 'WhatsApp', 'meeting' => 'Meeting', 'visit' => 'Site visit',
            'email' => 'Email', 'note' => 'Comment'];
}

/** How it went. Kept short and shared by every kind, so reports can count them. */
function crm_activity_outcomes(): array
{
    return ['answered' => 'Answered', 'no_answer' => 'No answer', 'busy' => 'Busy / call later',
            'interested' => 'Interested', 'not_interested' => 'Not interested', 'booked' => 'Booked a visit / meeting',
            'sent_info' => 'Sent details', 'wrong_number' => 'Wrong number'];
}

/**
 * Log a call, meeting, visit, WhatsApp, email or comment against a lead.
 *
 * Anything but a plain comment is contact with the lead, so it also stamps the first response —
 * a salesperson who phones a lead has responded, even though no WhatsApp went out.
 */
function crm_log_activity(array $client, int $contactId, string $kind, ?string $outcome, string $body, ?int $by): bool
{
    $kinds = crm_activity_kinds();
    if (!isset($kinds[$kind])) $kind = 'note';
    if ($outcome !== null && !isset(crm_activity_outcomes()[$outcome])) $outcome = null;
    $body = trim($body);
    if ($kind === 'note' && $body === '') return false;
    if (!db_has_column('crm_notes', 'kind')) {                     // migration 038 not applied yet
        crm_add_note($client, $contactId, ($kind !== 'note' ? $kinds[$kind] . ': ' : '') . $body, $by);
        return true;
    }
    db_insert("INSERT INTO crm_notes (client_id,contact_id,user_id,kind,outcome,body,created_at) VALUES (?,?,?,?,?,?,NOW())",
              [(int) $client['id'], $contactId, $by, $kind, $outcome, mb_substr($body, 0, 5000)]);
    if ($kind !== 'note') crm_mark_response($contactId, 'crm');
    if ($by !== null) crm_touch($contactId);
    crm_rescore($contactId);
    return true;
}

/** Set (or clear, with NULL) the next follow-up, and keep it in the lead's history. */
function crm_set_followup(array $client, int $contactId, ?string $when, string $note, ?int $by): void
{
    $at = $when !== null && strtotime($when) ? date('Y-m-d H:i:s', strtotime($when)) : null;
    $note = mb_substr(trim($note), 0, 255);
    if (db_has_column('contacts', 'followup_note')) {
        db_run("UPDATE contacts SET next_followup_at=?, followup_note=? WHERE id=? AND client_id=?",
               [$at, $at && $note !== '' ? $note : null, $contactId, (int) $client['id']]);
    } else {
        db_run("UPDATE contacts SET next_followup_at=? WHERE id=? AND client_id=?", [$at, $contactId, (int) $client['id']]);
    }
    crm_log((int) $client['id'], $contactId, 'followup', null, $at, $by);
}

/**
 * Put a contact into the pipeline.
 *
 * $owner: 'auto' = round-robin, 'none' = leave unassigned, or a user id. A contact already in the
 * pipeline keeps its stage and owner — re-importing a sheet must not reshuffle a team's leads.
 *
 * @return bool true when the contact was newly added
 */
function crm_add_lead(array $client, int $contactId, string $source = '', $owner = 'auto',
                      ?int $stageId = null, ?int $by = null): bool
{
    $cid = (int) $client['id'];
    $c = db_row("SELECT id, stage_id FROM contacts WHERE id=? AND client_id=?", [$contactId, $cid]);
    if (!$c) return false;
    if ($c['stage_id'] !== null) return false;                        // already a lead

    $stage = $stageId && isset(crm_stage_map($cid)[$stageId]) ? $stageId : crm_first_stage($cid);
    db_run("UPDATE contacts SET stage_id=?, crm_added_at=NOW(),
                   source=COALESCE(NULLIF(?,''), source) WHERE id=? AND client_id=?",
           [$stage, $source, $contactId, $cid]);
    crm_log($cid, $contactId, 'added', null, (string) $stage, $by);

    $userId = $owner === 'auto' ? crm_assign_next($client, db_row("SELECT * FROM contacts WHERE id=?", [$contactId]) ?: null)
            : ($owner === 'none' || $owner === null ? null : (int) $owner);
    if ($userId !== null) crm_assign($client, $contactId, $userId, $by);
    crm_rescore($contactId);
    return true;
}

/**
 * A brand-new contact arrived by message. Called from both webhooks right after the INSERT.
 *
 * Only when the account has the CRM — otherwise a client who never bought it would find stages
 * and owners silently accumulating on every contact.
 */
function crm_on_new_inbound(array $client, int $contactId, string $source = 'inbound'): void
{
    if (!crm_enabled($client)) return;
    try {
        crm_add_lead($client, $contactId, $source, 'auto');
    } catch (Throwable $e) {
        // Assignment is worth having, not worth losing the inbound message for.
        error_log('crm_on_new_inbound failed: ' . $e->getMessage());
    }
}

/**
 * A person replied to a lead. Stamps first_response_at once — the sales report's response time.
 *
 * Only a human send counts ($source 'manual' from the Inbox, 'crm' from a lead page). A bot's
 * instant auto-reply would otherwise make every salesperson's response time look like zero.
 */
function crm_mark_response(int $contactId, string $source): void
{
    if (!in_array($source, ['manual', 'crm'], true)) return;
    try {
        db_run("UPDATE contacts SET first_response_at=NOW()
                 WHERE id=? AND first_response_at IS NULL AND stage_id IS NOT NULL", [$contactId]);
    } catch (Throwable $e) { /* migration 033 not applied yet */ }
}

/**
 * The SQL that limits a query to what this user may see. Sales see only their own leads.
 *
 * @param string $alias table alias of contacts in the calling query ('' for none)
 * @return array{0:string, 1:array}  [" AND c.owner_user_id = ?", [id]] or ['', []]
 */
function crm_scope(string $alias = 'c'): array
{
    if (!function_exists('is_sales') || !is_sales()) return ['', []];
    $col = ($alias !== '' ? $alias . '.' : '') . 'owner_user_id';
    return [" AND {$col} = ?", [(int) crm_actor_id()]];
}

/** May the current user see this contact? Only their own, if they are Sales. */
function crm_can_see(array $contact): bool
{
    if (!function_exists('is_sales') || !is_sales()) return true;
    return (int) ($contact['owner_user_id'] ?? 0) === (int) crm_actor_id();
}

/** Every lead for a client, scoped to the viewer, with what the board and table need. */
function crm_leads(int $clientId, array $f = []): array
{
    $sql = "SELECT c.*, s.name AS stage_name, s.kind AS stage_kind,
                   COALESCE(NULLIF(u.name,''), u.email) AS owner_name,
                   (SELECT m.body FROM messages m WHERE m.contact_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                   (SELECT m.created_at FROM messages m WHERE m.contact_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_at
              FROM contacts c
              JOIN crm_stages s ON s.id = c.stage_id
              LEFT JOIN users u ON u.id = c.owner_user_id
             WHERE c.client_id = ? AND c.stage_id IS NOT NULL";
    $p = [$clientId];
    [$scope, $sp] = crm_scope('c');
    $sql .= $scope; $p = array_merge($p, $sp);

    if (!empty($f['stage']))  { $sql .= " AND c.stage_id = ?";  $p[] = (int) $f['stage']; }
    if (!empty($f['source'])) { $sql .= " AND c.source = ?";    $p[] = (string) $f['source']; }
    if (($f['owner'] ?? '') === 'none')      { $sql .= " AND c.owner_user_id IS NULL"; }
    elseif (!empty($f['owner']))             { $sql .= " AND c.owner_user_id = ?"; $p[] = (int) $f['owner']; }
    if (($f['due'] ?? '') === 'today')       { $sql .= " AND DATE(c.next_followup_at) = CURDATE()"; }
    elseif (($f['due'] ?? '') === 'overdue') { $sql .= " AND c.next_followup_at < NOW() AND s.kind = 'open'"; }
    if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
        $sql .= " AND (c.name LIKE ? OR c.phone_e164 LIKE ? OR c.email LIKE ?)";
        array_push($p, "%$q%", "%$q%", "%$q%");
    }
    $m039 = db_has_column('contacts', 'score');
    if ($m039) {
        if (!empty($f['project'])) { $sql .= " AND c.project_id = ?"; $p[] = (int) $f['project']; }
        $heat = (string) ($f['heat'] ?? '');
        if ($heat === 'hot')  $sql .= " AND c.score >= 70";
        if ($heat === 'warm') $sql .= " AND c.score >= 40 AND c.score < 70";
        if ($heat === 'cold') $sql .= " AND c.score < 40";
        $st = (string) ($f['status'] ?? '');
        if ($st === 'not_contacted') $sql .= " AND c.first_response_at IS NULL AND s.kind = 'open'";
        if ($st === 'again')         $sql .= " AND c.submissions > 1";
        if ($st === 'no_followup')   $sql .= " AND c.next_followup_at IS NULL AND s.kind = 'open'";
    }
    $order = [
        'hot'    => $m039 ? "c.score IS NULL, c.score DESC, c.id DESC" : "c.id DESC",
        'newest' => "c.id DESC",
        'value'  => "c.deal_value IS NULL, c.deal_value DESC, c.id DESC",
    ][(string) ($f['sort'] ?? '')] ?? "c.next_followup_at IS NULL, c.next_followup_at, c.id DESC";
    $sql .= " ORDER BY $order LIMIT 2000";
    return db_all($sql, $p);
}

/** Where leads come from, in words a client recognises. */
function crm_source_label(?string $source): string
{
    return [
        'inbound'   => 'WhatsApp message', 'manual' => 'Added by hand', 'import' => 'Imported',
        'meta_form' => 'Meta lead form',   'ctwa'   => 'Click-to-WhatsApp ad',
        'sheet'     => 'Google Sheet',     'qualifier' => 'Lead Qualifier',
    ][(string) $source] ?? ($source ? ucfirst((string) $source) : '—');
}
