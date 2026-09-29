<?php
declare(strict_types=1);

/**
 * The CRM for managers: who gets which lead, what happens when nobody answers it, how hot a lead
 * is, why deals are lost, and the notices that tell people's phones about all of it.
 *
 * Loaded by crm.php, so every page that has the CRM has this too. Every function tolerates the
 * migration not having run yet (a pull before migrate must not take pages down), falling back to
 * how things worked before.
 */

/* ───────────────────────── settings, projects, pick-lists ───────────────────────── */

function crm_settings(int $clientId): array
{
    $d = ['first_contact_minutes' => null, 'reclaim_minutes' => null, 'reclaim_max' => 2, 'stale_days' => null,
          'stale_reassign' => 0, 'work_start' => null, 'work_end' => null, 'digest_hour' => 9,
          'followup_reminders' => 1, 'require_lost_reason' => 1,
          'staff_wa_on' => 0, 'staff_wa_template' => null, 'staff_wa_kinds' => 'assigned,sla,followup,reclaimed,visit'];
    try { $row = db_row("SELECT * FROM crm_settings WHERE client_id=?", [$clientId]); }
    catch (Throwable $e) { return $d; }
    return $row ? array_merge($d, $row) : $d;
}

function crm_settings_save(int $clientId, array $s): void
{
    $cols = ['first_contact_minutes', 'reclaim_minutes', 'reclaim_max', 'stale_days', 'stale_reassign', 'work_start',
             'work_end', 'digest_hour', 'followup_reminders', 'require_lost_reason'];
    $vals = array_map(fn($c) => $s[$c] ?? null, $cols);
    db_run("INSERT INTO crm_settings (client_id," . implode(',', $cols) . ",updated_at) VALUES (?," . rtrim(str_repeat('?,', count($cols)), ',') . ",NOW())
            ON DUPLICATE KEY UPDATE " . implode(',', array_map(fn($c) => "$c=VALUES($c)", $cols)) . ", updated_at=NOW()",
           array_merge([$clientId], $vals));
}

/**
 * Change some settings, leaving the rest as they are. Only real columns are written, so a later
 * feature's settings live in the same row without every save having to know about them.
 */
function crm_settings_set(int $clientId, array $kv): void
{
    $have = array_flip(array_column(db_all("SHOW COLUMNS FROM crm_settings"), 'Field'));
    $kv = array_intersect_key($kv, $have);
    unset($kv['client_id'], $kv['updated_at']);
    if (!$kv) return;
    db_run("INSERT IGNORE INTO crm_settings (client_id) VALUES (?)", [$clientId]);
    $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($kv)));
    db_run("UPDATE crm_settings SET $set, updated_at=NOW() WHERE client_id=?", array_merge(array_values($kv), [$clientId]));
}

function crm_projects(int $clientId, bool $activeOnly = false): array
{
    try {
        return db_all("SELECT * FROM crm_projects WHERE client_id=?" . ($activeOnly ? " AND active=1" : "") . " ORDER BY sort, name", [$clientId]);
    } catch (Throwable $e) { return []; }
}

function crm_project_names(int $clientId): array
{
    return array_column(crm_projects($clientId), 'name', 'id');
}

function crm_option_defaults(string $kind): array
{
    return [
        'unit_type'   => ['Apartment', 'Villa', 'Townhouse', 'Twin house', 'Chalet', 'Duplex', 'Penthouse', 'Studio', 'Commercial'],
        'lost_reason' => ['Price too high', 'Location', 'Bought elsewhere', 'Payment plan did not fit', 'Just browsing / not serious',
                          'Could not reach them', 'Wrong number', 'Other'],
    ][$kind] ?? [];
}

/** A client's pick-list (unit types, lost reasons), seeded with sensible defaults on first use. */
function crm_options(int $clientId, string $kind): array
{
    try {
        $rows = db_all("SELECT label FROM crm_options WHERE client_id=? AND kind=? ORDER BY sort, id", [$clientId, $kind]);
        if (!$rows && !db_val("SELECT COUNT(*) FROM crm_options WHERE client_id=? AND kind=CONCAT('_', ?)", [$clientId, $kind])) {
            foreach (crm_option_defaults($kind) as $i => $l)
                db_insert("INSERT INTO crm_options (client_id,kind,label,sort) VALUES (?,?,?,?)", [$clientId, $kind, $l, $i]);
            // Marks the list as seeded, so a client who deletes every entry is not given them back.
            db_insert("INSERT INTO crm_options (client_id,kind,label,sort) VALUES (?,CONCAT('_', ?),'seeded',0)", [$clientId, $kind]);
            $rows = db_all("SELECT label FROM crm_options WHERE client_id=? AND kind=? ORDER BY sort, id", [$clientId, $kind]);
        }
        return array_column($rows, 'label');
    } catch (Throwable $e) { return crm_option_defaults($kind); }
}

function crm_options_save(int $clientId, string $kind, array $labels): void
{
    crm_options($clientId, $kind);                                  // make sure it counts as seeded
    db_run("DELETE FROM crm_options WHERE client_id=? AND kind=?", [$clientId, $kind]);
    $seen = [];
    foreach (array_values($labels) as $i => $l) {
        $l = mb_substr(trim((string) $l), 0, 80);
        if ($l === '' || isset($seen[mb_strtolower($l)])) continue;
        $seen[mb_strtolower($l)] = true;
        db_insert("INSERT INTO crm_options (client_id,kind,label,sort) VALUES (?,?,?,?)", [$clientId, $kind, $l, $i]);
    }
}

function crm_stage_kind(int $clientId, ?int $stageId): string
{
    return $stageId ? (string) (crm_stage_map($clientId)[$stageId]['kind'] ?? '') : '';
}

/* ───────────────────────── who may get leads ───────────────────────── */

/** Open leads each person holds right now — for capacity and "fewest leads first". */
function crm_open_counts(int $clientId): array
{
    $out = [];
    foreach (db_all("SELECT c.owner_user_id uid, COUNT(*) n FROM contacts c JOIN crm_stages s ON s.id=c.stage_id
                      WHERE c.client_id=? AND s.kind='open' AND c.owner_user_id IS NOT NULL GROUP BY c.owner_user_id", [$clientId]) as $r)
        $out[(int) $r['uid']] = (int) $r['n'];
    return $out;
}

/**
 * Of these people, the ones who can take a lead right now: active, not on leave, under capacity,
 * and not someone this lead has already been taken from.
 */
function crm_eligible(int $clientId, array $userIds, array $exclude = []): array
{
    $userIds = array_values(array_diff(array_map('intval', $userIds), array_map('intval', $exclude)));
    if (!$userIds) return [];
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $hasAvail = db_has_column('users', 'crm_available');
    $rows = db_all("SELECT id" . ($hasAvail ? ", crm_available, crm_capacity" : "") . " FROM users
                     WHERE client_id=? AND status='active' AND id IN ($ph) ORDER BY id", array_merge([$clientId], $userIds));
    $open = null;
    $ok = [];
    foreach ($rows as $u) {
        if ($hasAvail && !(int) $u['crm_available']) continue;
        if ($hasAvail && $u['crm_capacity'] !== null && (int) $u['crm_capacity'] > 0) {
            $open ??= crm_open_counts($clientId);
            if (($open[(int) $u['id']] ?? 0) >= (int) $u['crm_capacity']) continue;
        }
        $ok[] = (int) $u['id'];
    }
    return $ok;
}

function crm_rules(int $clientId, bool $activeOnly = false): array
{
    try {
        return db_all("SELECT * FROM crm_rules WHERE client_id=?" . ($activeOnly ? " AND active=1" : "") . " ORDER BY sort, id", [$clientId]);
    } catch (Throwable $e) { return []; }
}

/** Does this rule apply to this lead? Every condition the rule sets must hold; unset ones don't matter. */
function crm_rule_matches(array $rule, array $lead): bool
{
    if ($rule['match_project'] !== null && (int) $rule['match_project'] !== (int) ($lead['project_id'] ?? 0)) return false;
    if (($rule['match_source'] ?? '') !== '' && $rule['match_source'] !== null && $rule['match_source'] !== (string) ($lead['source'] ?? '')) return false;
    if (($rule['match_unit'] ?? '') !== '' && $rule['match_unit'] !== null
        && mb_strtolower((string) $rule['match_unit']) !== mb_strtolower((string) ($lead['unit_type'] ?? ''))) return false;
    if (($rule['match_form'] ?? '') !== '' && $rule['match_form'] !== null && $rule['match_form'] !== (string) ($lead['__form_id'] ?? '')) return false;
    return true;
}

/**
 * Choose who gets a lead.
 *
 * The first active rule that matches the lead decides the team; with no rule, it is the whole
 * sales team (crm_rotation). From that team, only people who can take it now (crm_eligible). If a
 * rule's team is all away or full, the lead falls back to the whole team rather than going to
 * nobody. "rotate" deals in turn — atomically, so two leads at once don't land on one person —
 * and "least" gives it to whoever holds the fewest open leads.
 *
 * @param array|null $lead    the contact row (for rule matching); NULL = no rules, plain rotation
 * @param int[]      $exclude people it must not go to (the ones it is being taken from)
 * @return array{0:?int, 1:?array} [user id or NULL, the rule that chose it or NULL]
 */
function crm_pick_owner(array $client, ?array $lead = null, array $exclude = []): array
{
    $cid = (int) $client['id'];
    $team = null; $rule = null;
    if ($lead) {
        foreach (crm_rules($cid, true) as $r) {
            if (!crm_rule_matches($r, $lead)) continue;
            $ids = array_filter(array_map('intval', explode(',', (string) $r['users'])));
            $ok  = crm_eligible($cid, $ids, $exclude);
            if ($ok) { $team = $ok; $rule = $r; }
            break;                                   // the first matching rule decides, even if it has to fall back
        }
    }
    if ($team === null) $team = crm_eligible($cid, crm_rotation($client), $exclude);
    if (!$team) return [null, null];

    if ($rule && $rule['method'] === 'least') {
        $open = crm_open_counts($cid);
        usort($team, fn($a, $b) => ($open[$a] ?? 0) <=> ($open[$b] ?? 0) ?: $a <=> $b);
        return [$team[0], $rule];
    }

    // Round-robin, under a row lock so concurrent arrivals are dealt one each.
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $ptr = $rule ? (int) db_val("SELECT rr_pointer FROM crm_rules WHERE id=? FOR UPDATE", [(int) $rule['id']])
                     : (int) db_val("SELECT COALESCE(rr_pointer,0) FROM clients WHERE id=? FOR UPDATE", [$cid]);
        sort($team);
        $next = $team[0];
        foreach ($team as $id) { if ($id > $ptr) { $next = $id; break; } }
        if ($rule) db_run("UPDATE crm_rules SET rr_pointer=? WHERE id=?", [$next, (int) $rule['id']]);
        else       db_run("UPDATE clients SET rr_pointer=? WHERE id=?", [$next, $cid]);
        if ($own) $pdo->commit();
        return [$next, $rule];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Everyone who ever held this lead — so a lead taken back never boomerangs to them. */
function crm_past_owners(int $contactId): array
{
    $ids = array_map('intval', array_column(db_all("SELECT DISTINCT to_val FROM crm_events WHERE contact_id=? AND kind='assigned' AND to_val IS NOT NULL", [$contactId]), 'to_val'));
    return array_values(array_unique($ids));
}

/**
 * Move leads from one person (or from nobody) to another, or share them out by the rules.
 *
 * @param array{scope?:string, stage?:int} $f scope: open | not_contacted | all
 * @return int how many moved
 */
function crm_transfer(array $client, ?int $fromUser, string $to, array $f, ?int $by): int
{
    $cid = (int) $client['id'];
    $sql = "SELECT c.* FROM contacts c JOIN crm_stages s ON s.id=c.stage_id WHERE c.client_id=? AND "
         . ($fromUser === null ? "c.owner_user_id IS NULL" : "c.owner_user_id = ?");
    $p = $fromUser === null ? [$cid] : [$cid, $fromUser];
    $scope = (string) ($f['scope'] ?? 'open');
    if ($scope !== 'all') $sql .= " AND s.kind='open'";
    if ($scope === 'not_contacted') $sql .= " AND c.first_response_at IS NULL";
    if (!empty($f['stage'])) { $sql .= " AND c.stage_id=?"; $p[] = (int) $f['stage']; }
    $n = 0;
    foreach (db_all($sql . " ORDER BY c.id LIMIT 5000", $p) as $lead) {
        if ($to === 'rules') {
            [$uid] = crm_pick_owner($client, $lead, $fromUser !== null ? [$fromUser] : []);
            if ($uid === null) continue;
        } elseif ($to === 'none') {
            $uid = null;
        } else {
            $uid = (int) $to;
        }
        if ($uid === ($fromUser)) continue;
        crm_assign($client, (int) $lead['id'], $uid, $by);
        $n++;
    }
    return $n;
}

/* ───────────────────────── notices ───────────────────────── */

/** Tell one person's phone something. The push carries nothing; the phone asks what it was. */
function crm_notice(int $clientId, int $userId, string $kind, ?int $contactId = null, ?array $data = null): void
{
    try {
        db_insert("INSERT INTO crm_notices (client_id,user_id,kind,contact_id,data,created_at) VALUES (?,?,?,?,?,NOW())",
                  [$clientId, $userId, $kind, $contactId, $data ? json_encode($data) : null]);
        crm_notify_user($userId, $clientId);
    } catch (Throwable $e) { error_log('crm_notice skipped: ' . $e->getMessage()); }
}

/** The account's managers: client admins who are active. */
function crm_admin_ids(int $clientId): array
{
    return array_map('intval', array_column(db_all("SELECT id FROM users WHERE client_id=? AND role='client'
        AND COALESCE(client_role,'admin')='admin' AND status='active'", [$clientId]), 'id'));
}

/**
 * What a person should know about right now, for the CRM page and the menu badge.
 * Sales see their own; managers (NULL user) see the whole account.
 */
function crm_alert_counts(array $client, ?int $ownerId): array
{
    $cid = (int) $client['id'];
    $s = crm_settings($cid);
    $w = $ownerId !== null ? " AND c.owner_user_id=" . (int) $ownerId : '';
    $base = "FROM contacts c JOIN crm_stages s ON s.id=c.stage_id WHERE c.client_id=? AND s.kind='open'$w";
    $out = [
        'due_today' => (int) db_val("SELECT COUNT(*) $base AND c.next_followup_at >= CURDATE() AND c.next_followup_at < CURDATE() + INTERVAL 1 DAY", [$cid]),
        'overdue'   => (int) db_val("SELECT COUNT(*) $base AND c.next_followup_at < NOW()", [$cid]),
        // Everything to do by the end of today, counted once (an overdue one from this morning is both).
        'due'       => (int) db_val("SELECT COUNT(*) $base AND c.next_followup_at < CURDATE() + INTERVAL 1 DAY", [$cid]),
        'late'      => 0,
        'unassigned'=> $ownerId === null ? (int) db_val("SELECT COUNT(*) $base AND c.owner_user_id IS NULL", [$cid]) : 0,
    ];
    if ($s['first_contact_minutes']) {
        $out['late'] = (int) db_val("SELECT COUNT(*) $base AND c.owner_user_id IS NOT NULL AND c.first_response_at IS NULL
                                     AND c.assigned_at < NOW() - INTERVAL ? MINUTE", [$cid, (int) $s['first_contact_minutes']]);
    }
    return $out;
}

/* ───────────────────────── scoring ───────────────────────── */

/**
 * How likely this lead is to buy, 0–100, with the reasons — so a salesperson can see WHY a lead
 * is hot, and a manager can trust the number. Simple, explainable rules rather than a black box.
 *
 * @return array{0:int, 1:array<int,array{0:string,1:int}>}
 */
function crm_score_compute(array $c): array
{
    $cid = (int) $c['client_id'];
    $kind = crm_stage_kind($cid, $c['stage_id'] !== null ? (int) $c['stage_id'] : null);
    if ($kind === 'won')  return [100, [['Won', 100]]];
    if ($kind === 'lost') return [0, [['Lost', 0]]];

    $r = [];
    $r[] = ['Starting point', 35];   // a fresh lead starts warm; it cools only on real signs
    $src = ['ctwa' => 15, 'inbound' => 15, 'meta_form' => 10, 'qualifier' => 10, 'manual' => 5][(string) $c['source']] ?? 0;
    if ($src) $r[] = ['Came from ' . crm_source_label($c['source']), $src];
    if (!empty($c['email'])) $r[] = ['Left an email', 5];
    if (!empty($c['project_id'])) $r[] = ['Knows which project', 8];
    if (!empty($c['unit_type'])) $r[] = ['Knows the unit type', 5];
    if (!empty($c['budget'])) $r[] = ['Gave a budget', 8];
    $answers = count(json_decode((string) ($c['attributes'] ?? ''), true) ?: []);
    if ($answers) $r[] = ['Answered ' . $answers . ' question' . ($answers === 1 ? '' : 's'), min(8, $answers * 2)];
    $subs = (int) ($c['submissions'] ?? 1);
    if ($subs > 1) $r[] = ['Came in ' . $subs . ' times', min(20, ($subs - 1) * 10)];

    $in = db_row("SELECT COUNT(*) n, MAX(created_at) last FROM messages WHERE contact_id=? AND direction='in'", [(int) $c['id']]);
    if ((int) $in['n'] > 0) {
        $r[] = ['Wrote to you', 10];
        if ($in['last'] && strtotime((string) $in['last']) > time() - 7 * 86400) $r[] = ['Wrote in the last week', 10];
    }

    if (db_has_column('crm_notes', 'outcome')) {
        $o = [];
        foreach (db_all("SELECT outcome, COUNT(*) n FROM crm_notes WHERE contact_id=? AND outcome IS NOT NULL GROUP BY outcome", [(int) $c['id']]) as $x)
            $o[$x['outcome']] = (int) $x['n'];
        if (!empty($o['booked']))         $r[] = ['Booked a visit or meeting', 40];
        if (!empty($o['interested']))     $r[] = ['Said they are interested', 20];
        if (!empty($o['answered']))       $r[] = ['Answered a call', 5];
        if (!empty($o['not_interested'])) $r[] = ['Said not interested', -30];
        if (!empty($o['wrong_number']))   $r[] = ['Wrong number', -50];
        if (($o['no_answer'] ?? 0) > 2)   $r[] = ['No answer ' . $o['no_answer'] . ' times', -min(20, ($o['no_answer'] - 2) * 5)];
    }

    // Further along the pipeline = warmer.
    $pos = 0;
    foreach (crm_stages($cid) as $s) { if ($s['kind'] !== 'open') continue; if ((int) $s['id'] === (int) $c['stage_id']) break; $pos++; }
    if ($pos) $r[] = ['Reached stage ' . ($pos + 1), min(20, $pos * 7)];

    // Gone quiet.
    $touch = max(strtotime((string) ($c['last_touch_at'] ?? '')) ?: 0, strtotime((string) ($in['last'] ?? '')) ?: 0,
                 strtotime((string) ($c['last_submitted_at'] ?? '')) ?: 0, strtotime((string) ($c['created_at'] ?? '')) ?: 0);
    $days = $touch ? (int) floor((time() - $touch) / 86400) : 0;
    if ($days > 30)      $r[] = ['Nothing for ' . $days . ' days', -20];
    elseif ($days > 14)  $r[] = ['Nothing for ' . $days . ' days', -10];

    $score = max(0, min(100, array_sum(array_column($r, 1))));
    return [$score, $r];
}

function crm_rescore(int $contactId): void
{
    if (!db_has_column('contacts', 'score')) return;
    try {
        $c = db_row("SELECT * FROM contacts WHERE id=?", [$contactId]);
        if (!$c || $c['stage_id'] === null) return;
        [$s] = crm_score_compute($c);
        db_run("UPDATE contacts SET score=?, score_at=NOW() WHERE id=?", [$s, $contactId]);
    } catch (Throwable $e) { error_log('crm_rescore: ' . $e->getMessage()); }
}

function crm_heat(?int $score): array
{
    if ($score === null) return ['', ''];
    return $score >= 70 ? ['hot', 'Hot'] : ($score >= 40 ? ['warm', 'Warm'] : ['cold', 'Cold']);
}

/** Someone did something with the lead. Feeds "gone quiet" and the stale timer. */
function crm_touch(int $contactId): void
{
    if (!db_has_column('contacts', 'last_touch_at')) return;
    db_run("UPDATE contacts SET last_touch_at=NOW(), stale_alerted_at=NULL WHERE id=?", [$contactId]);
}

/* ───────────────────────── duplicates ───────────────────────── */

/** The same person came in again (another form, another ad). Counted, logged, owner told. */
function crm_resubmitted(array $client, int $contactId, string $source): void
{
    if (!db_has_column('contacts', 'submissions')) return;
    $c = db_row("SELECT owner_user_id, stage_id FROM contacts WHERE id=?", [$contactId]);
    if (!$c || $c['stage_id'] === null) return;
    db_run("UPDATE contacts SET submissions=submissions+1, last_submitted_at=NOW() WHERE id=?", [$contactId]);
    crm_log((int) $client['id'], $contactId, 'resubmitted', null, $source, null);
    if ($c['owner_user_id'] !== null) crm_notice((int) $client['id'], (int) $c['owner_user_id'], 'resubmit', $contactId);
    crm_rescore($contactId);
}

/**
 * Other contacts that are probably the same person: same email, same name, or the same number
 * written differently (the last nine digits match — a local 010… saved beside +2010…).
 */
function crm_duplicates(array $lead): array
{
    $cid = (int) $lead['client_id']; $id = (int) $lead['id'];
    $conds = []; $p = [$cid, $id];
    if (!empty($lead['email'])) { $conds[] = "LOWER(c.email) = ?"; $p[] = mb_strtolower((string) $lead['email']); }
    $name = trim((string) ($lead['name'] ?? ''));
    if (mb_strlen($name) >= 5) { $conds[] = "LOWER(TRIM(c.name)) = ?"; $p[] = mb_strtolower($name); }
    $tail = substr(preg_replace('/\D+/', '', (string) $lead['phone_e164']), -9);
    if (strlen($tail) === 9) { $conds[] = "RIGHT(c.phone_e164, 9) = ?"; $p[] = $tail; }
    if (!$conds) return [];
    [$scope, $sp] = crm_scope('c');
    return db_all("SELECT c.id, c.name, c.phone_e164, c.email, c.stage_id, c.owner_user_id, c.created_at, s.name AS stage_name
                     FROM contacts c LEFT JOIN crm_stages s ON s.id=c.stage_id
                    WHERE c.client_id=? AND c.id<>? AND (" . implode(' OR ', $conds) . ")$scope ORDER BY c.id LIMIT 10",
                  array_merge($p, $sp));
}

/**
 * Fold one contact into another: messages, notes, history, list memberships and everything else
 * that points at the duplicate move to the one being kept, then the duplicate is deleted.
 *
 * Found by column name rather than by a list kept here, so a table added next year is not
 * silently left pointing at a contact that no longer exists.
 */
function crm_merge(array $client, int $keepId, int $dropId, ?int $by): bool
{
    $cid = (int) $client['id'];
    $keep = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$keepId, $cid]);
    $drop = db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$dropId, $cid]);
    if (!$keep || !$drop || $keepId === $dropId) return false;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tables = db_all("SELECT TABLE_NAME t FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'contact_id' AND TABLE_NAME <> 'contacts'");
        foreach ($tables as $t) {
            $tn = preg_replace('/[^A-Za-z0-9_]/', '', (string) $t['t']);
            // IGNORE: where a unique key would clash (both were in the same list), the kept row stands.
            db_run("UPDATE IGNORE `{$tn}` SET contact_id=? WHERE contact_id=?", [$keepId, $dropId]);
            db_run("DELETE FROM `{$tn}` WHERE contact_id=?", [$dropId]);
        }
        $attrs = array_merge(json_decode((string) ($drop['attributes'] ?? ''), true) ?: [], json_decode((string) ($keep['attributes'] ?? ''), true) ?: []);
        $fill = fn($k) => ($keep[$k] ?? null) !== null && $keep[$k] !== '' ? $keep[$k] : ($drop[$k] ?? null);
        db_run("UPDATE contacts SET name=?, email=?, attributes=?, deal_value=?, submissions=submissions+?,
                       project_id=?, unit_type=?, budget=?, owner_user_id=COALESCE(owner_user_id, ?), stage_id=COALESCE(stage_id, ?)
                 WHERE id=?",
               [$fill('name'), $fill('email'), json_encode($attrs, JSON_UNESCAPED_UNICODE), $fill('deal_value'),
                (int) ($drop['submissions'] ?? 1), $fill('project_id'), $fill('unit_type'), $fill('budget'),
                $drop['owner_user_id'], $drop['stage_id'], $keepId]);
        db_run("DELETE FROM contacts WHERE id=?", [$dropId]);
        crm_log($cid, $keepId, 'merged', (string) $dropId, '+' . $drop['phone_e164'], $by);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('crm_merge failed: ' . $e->getMessage());
        return false;
    }
    crm_rescore($keepId);
    return true;
}
