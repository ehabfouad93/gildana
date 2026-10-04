<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm.php';

/**
 * The pipeline: every lead as a board of stages or as a table, the same leads either way.
 *
 * The table is where a sales team lives all day, so it carries most of the weight: saved views,
 * a filter under each column and an advanced panel (one set of filters — includes/crm_list.php),
 * the columns each person chooses, effort counters on every row, bulk actions, export, and a
 * card layout on a phone.
 *
 * Sales see only their own leads (a team leader: their team's too). Admins see everyone's and can
 * reassign. Viewers can look; the gate in _init.php refuses their writes, and the page hides the
 * controls so it does not offer them buttons that will say no.
 */
$cid     = (int) $CLIENT['id'];
$me      = (int) ($PERM_USER['id'] ?? 0);
$canEdit = can_write();
$isAdmin = is_client_admin();
$isLeader = !$isAdmin && crm_is_team_leader();
$canMove = $isAdmin || $isLeader;                        // pass leads to someone else
$canExport = function_exists('can_crm_export') ? can_crm_export() : $isAdmin;
// Upcoming events leads can be added to, from the bulk bar.
$evChoices = [];
if (can_write() && can_crm('visits')) {
    try { $evChoices = db_all("SELECT id, name, starts_at FROM sales_events WHERE client_id=? AND status='active' AND starts_at > NOW() ORDER BY starts_at LIMIT 30", [(int) $CLIENT['id']]); }
    catch (Throwable $e) { $evChoices = []; }
}
$hidePhones = crm_phone_hidden();
$canDelete = $isAdmin || can_crm_action('delete');
$stages  = crm_stages($cid);
$stageMap = crm_stage_map($cid);
$people  = crm_assignable_users($cid);
$visible = crm_visible_owner_ids();
if ($isLeader) $people = array_values(array_filter($people, fn($u) => in_array((int) $u['id'], $visible, true)));

/* ── AJAX ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $a = (string) ($_POST['action'] ?? '');

    /** The lead ids in this request that the user may touch. Anything else is dropped silently. */
    $mine = function (array $ids) use ($cid): array {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        [$scope, $sp] = crm_scope('');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        return array_map('intval', array_column(db_all(
            "SELECT id FROM contacts WHERE client_id=? AND stage_id IS NOT NULL AND id IN ($ph){$scope}",
            array_merge([$cid], $ids, $sp)), 'id'));
    };
    /** The leads a bulk action is for: the ticked ones, or every lead matching the filters (capped). */
    $targets = function () use ($cid, $mine): array {
        if (!empty($_POST['all'])) {
            parse_str((string) ($_POST['qs'] ?? ''), $get);
            [$w, $p] = crm_list_where($cid, crm_list_filters($get));
            return array_map('intval', array_column(db_all("SELECT c.id FROM contacts c JOIN crm_stages s ON s.id=c.stage_id WHERE $w LIMIT 5000", $p), 'id'));
        }
        return $mine((array) ($_POST['ids'] ?? [$_POST['id'] ?? 0]));
    };

    if ($a === 'stage') {
        $ids = $targets();
        $sid = (int) ($_POST['stage_id'] ?? 0);
        if (!$ids || !isset($stageMap[$sid])) json_out(['ok' => false, 'error' => 'Nothing to move.']);
        $reason = trim((string) ($_POST['lost_reason'] ?? ''));
        if (crm_needs_lost_reason($cid, $sid) && $reason === '') json_out(['ok' => false, 'need_reason' => true]);
        $sub = trim((string) ($_POST['substatus'] ?? ''));
        foreach ($ids as $id) {
            crm_set_stage($CLIENT, $id, $sid, $me, $reason !== '' ? $reason : null, (string) ($_POST['lost_note'] ?? ''));
            if ($sub !== '') crm_set_substatus($CLIENT, $id, $sub, $me);
        }
        json_out(['ok' => true, 'n' => count($ids)]);
    }

    if ($a === 'assign') {
        // Reassigning is a manager's call — an Admin, or a team leader inside their own team. A
        // salesperson handing leads to a colleague would bypass the fairness the rules exist for.
        if (!$canMove) json_out(['ok' => false, 'error' => 'Only an Admin or a team leader can reassign leads.']);
        $ids = $targets();
        $to  = (string) ($_POST['user_id'] ?? '');
        if ($isLeader && !in_array($to === '' || $to === 'none' || $to === 'auto' ? -1 : (int) $to, $visible, true)) {
            json_out(['ok' => false, 'error' => 'A team leader can pass leads only to people on their team.']);
        }
        foreach ($ids as $id) {
            // "Share out" goes through the assignment rules, as a new lead would.
            if ($to === 'auto') crm_assign($CLIENT, $id, crm_assign_next($CLIENT, db_row("SELECT * FROM contacts WHERE id=?", [$id])), $me);
            else crm_assign($CLIENT, $id, $to === '' || $to === 'none' ? null : (int) $to, $me);
        }
        json_out(['ok' => true, 'n' => count($ids)]);
    }

    if ($a === 'transfer') {
        // The full transfer: several people with numbers, or teams; smart rotation; history options.
        require_once __DIR__ . '/../includes/crm_transfer.php';
        $o = json_decode((string) ($_POST['opts'] ?? '{}'), true) ?: [];
        $r = crm_bulk_transfer($CLIENT, $targets(), $o, $me);
        if (!$r['ok']) json_out($r);
        $names = [];
        foreach ($r['by_user'] as $u => $n) $names[] = crm_user_name((int) $u) . ' ' . $n;
        json_out(['ok' => true, 'n' => $r['moved'], 'skipped' => $r['skipped'], 'text' => implode(', ', $names)]);
    }

    if ($a === 'set_field') {
        // One field on many leads: project, unit type, qualification, fresh/cold, campaign, or one of the account's own.
        $ids = $targets();
        $field = (string) ($_POST['field'] ?? '');
        $val = trim((string) ($_POST['value'] ?? ''));
        if (!$ids) json_out(['ok' => false, 'error' => 'Choose some leads first.']);
        $n = 0;
        foreach ($ids as $id) {
            $before = crm_field_snapshot($cid, db_row("SELECT * FROM contacts WHERE id=?", [$id]) ?: []);
            switch (true) {
                case $field === 'project':
                    $pid = (int) $val;
                    db_run("UPDATE contacts SET project_id=? WHERE id=?", [isset(crm_project_names($cid)[$pid]) ? $pid : null, $id]); break;
                case $field === 'unit':
                    db_run("UPDATE contacts SET unit_type=? WHERE id=?", [in_array($val, crm_options($cid, 'unit_type'), true) ? $val : null, $id]); break;
                case $field === 'qual':
                    db_run("UPDATE contacts SET qualification=? WHERE id=?", [isset(crm_qualifications()[$val]) ? $val : null, $id]);
                    crm_log($cid, $id, 'qualified', null, isset(crm_qualifications()[$val]) ? $val : null, $me); break;
                case $field === 'dtype':
                    if (!$isAdmin || !isset(crm_data_types()[$val])) json_out(['ok' => false, 'error' => 'Only an Admin can change fresh / cold.']);
                    db_run("UPDATE contacts SET data_type=? WHERE id=?", [$val, $id]); break;
                case $field === 'campaign':
                    db_run("UPDATE contacts SET campaign=? WHERE id=?", [$val !== '' ? mb_substr($val, 0, 160) : null, $id]); break;
                case $field === 'followup':
                    crm_set_followup($CLIENT, $id, $val !== '' ? $val : null, '', $me); break;
                case str_starts_with($field, 'cf_'):
                    if (($e = crm_custom_save($cid, $id, [substr($field, 3) => $val])) !== '') json_out(['ok' => false, 'error' => $e]);
                    break;
                default:
                    json_out(['ok' => false, 'error' => 'Choose which field to change.']);
            }
            if ($field !== 'followup') { crm_rescore($id); crm_log_changes($cid, $id, $before, $me); }
            $n++;
        }
        json_out(['ok' => true, 'n' => $n]);
    }

    if ($a === 'template') {
        // A WhatsApp template to many leads: a manager's action, always from the Business API
        // number, queued so it goes out within working hours and respects opt-outs.
        if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only an Admin can send a template to many leads.']);
        if (!crm_auto_api_ready($CLIENT)) json_out(['ok' => false, 'error' => 'Connect the WhatsApp Business API number in Settings first.']);
        $tpls = crm_tpl_choices($cid);
        $tid = (int) ($_POST['template_id'] ?? 0);
        if (!isset($tpls[$tid]) || $tpls[$tid]['media'] !== '') json_out(['ok' => false, 'error' => 'Choose an approved template without a picture.']);
        $tokens = crm_tokens_from_post('vars', $_POST);
        $ids = $targets();
        foreach ($ids as $id) crm_queue($CLIENT, $id, $tid, $tokens, '', 'bulk', null, ['by' => $me]);
        json_out(['ok' => true, 'n' => count($ids)]);
    }

    if ($a === 'sequence') {
        if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only an Admin can add leads to a sequence.']);
        $seq = db_row("SELECT * FROM crm_sequences WHERE id=? AND client_id=? AND active=1", [(int) ($_POST['sequence_id'] ?? 0), $cid]);
        if (!$seq) json_out(['ok' => false, 'error' => 'Choose a sequence.']);
        $n = 0;
        foreach ($targets() as $id) if (crm_seq_enroll($CLIENT, $seq, $id)) $n++;
        json_out(['ok' => true, 'n' => $n]);
    }

    if ($a === 'event') {
        // Put the chosen leads on an event's guest list; the invitation goes from the event's page.
        require_once __DIR__ . '/../includes/crm_sales_events.php';
        if (!can_crm('visits')) json_out(['ok' => false, 'error' => 'Events are not open to you.']);
        $evId = (int) ($_POST['event_id'] ?? 0);
        $ev = sev_ready() ? sev_get($cid, $evId) : null;
        if (!$ev || $ev['status'] !== 'active') json_out(['ok' => false, 'error' => 'Choose an event.']);
        json_out(['ok' => true, 'n' => sev_add_guests($CLIENT, $evId, $targets(), $me), 'url' => 'crm_events.php?id=' . $evId . '#guests']);
    }

    if ($a === 'quick_log') {
        // Log a call from the list without opening the lead: what happened, the detail, what next.
        $ids = $mine([(int) ($_POST['id'] ?? 0)]);
        if (!$ids) json_out(['ok' => false, 'error' => 'Lead not found.']);
        $id = $ids[0];
        $kind = (string) ($_POST['kind'] ?? 'call');
        $outcome = (string) ($_POST['outcome'] ?? '') ?: null;
        $body = (string) ($_POST['body'] ?? '');
        if ($kind === 'note' && trim($body) === '') json_out(['ok' => false, 'error' => 'Write the comment first.']);
        crm_log_activity($CLIENT, $id, $kind, $kind === 'note' ? null : $outcome, $body, $me);
        if (isset($_POST['substatus']) && $_POST['substatus'] !== '') crm_set_substatus($CLIENT, $id, (string) $_POST['substatus'], $me);
        $next = trim((string) ($_POST['next_at'] ?? ''));
        if ($next !== '' && strtotime($next)) crm_set_followup($CLIENT, $id, $next, (string) ($_POST['next_note'] ?? ''), $me);
        json_out(['ok' => true]);
    }

    if ($a === 'columns') {
        $all = crm_list_columns($cid);
        $cols = array_values(array_intersect((array) ($_POST['cols'] ?? []), array_keys($all)));
        if (db_has_column('users', 'crm_columns')) db_run("UPDATE users SET crm_columns=? WHERE id=?", [$cols ? implode(',', $cols) : null, $me]);
        json_out(['ok' => true]);
    }

    if ($a === 'view_save') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
        if ($name === '') json_out(['ok' => false, 'error' => 'Give the view a name.']);
        parse_str((string) ($_POST['qs'] ?? ''), $get);
        $params = http_build_query(crm_list_active(crm_list_filters($get), []));
        $shared = $isAdmin && !empty($_POST['shared']);
        $id = db_insert("INSERT INTO crm_views (client_id,user_id,name,params,sort,created_at) VALUES (?,?,?,?,0,NOW())",
                        [$cid, $shared ? null : $me, $name, $params]);
        json_out(['ok' => true, 'id' => $id]);
    }
    if ($a === 'view_delete') {
        $v = db_row("SELECT * FROM crm_views WHERE id=? AND client_id=?", [(int) ($_POST['id'] ?? 0), $cid]);
        if ($v && ((int) $v['user_id'] === $me || ($v['user_id'] === null && $isAdmin))) db_run("DELETE FROM crm_views WHERE id=?", [(int) $v['id']]);
        json_out(['ok' => true]);
    }

    if ($a === 'add') {
        $owner = is_sales() ? $me : (string) ($_POST['owner'] ?? 'auto');
        // When the account wants new leads from Sales approved, this one waits for a manager —
        // unless the number is already a lead, which is said straight away.
        if (is_sales() && (int) (crm_settings($cid)['approve_new_leads'] ?? 0)) {
            $phone = normalize_phone((string) ($_POST['phone'] ?? ''), (string) ($CLIENT['default_country'] ?? ''));
            if ($phone === '') json_out(['ok' => false, 'error' => 'Enter a valid phone number, with the country code or a local number.']);
            $ex = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=? AND stage_id IS NOT NULL", [$cid, $phone]);
            if ($ex) json_out(['ok' => false, 'error' => 'That number is already a lead' . (crm_can_see($ex) ? '' : ' (someone else\'s)') . '.', 'id' => crm_can_see($ex) ? (int) $ex['id'] : 0]);
            crm_request_create($CLIENT, $me, array_intersect_key($_POST, array_flip(['name', 'phone', 'email', 'deal_value', 'stage_id', 'followup', 'project_id', 'unit_type', 'budget', 'campaign', 'note'])));
            json_out(['ok' => true, 'pending' => true]);
        }
        $r = crm_create_lead($CLIENT, $_POST, $owner === '' ? 'auto' : $owner, $me);
        json_out($r);
    }

    if ($a === 'delete') {
        // To the recycle bin, for 30 days. Admins, and people allowed to; others ask on the lead page.
        if (!$canDelete) json_out(['ok' => false, 'error' => 'Ask a manager to delete leads — open the lead and choose "Ask to delete".']);
        $n = 0;
        foreach ($targets() as $id) if (crm_delete_lead($CLIENT, $id, $me)) $n++;
        json_out(['ok' => true, 'n' => $n]);
    }

    if ($a === 'stages') {
        if (!$isAdmin) json_out(['ok' => false, 'error' => 'Only an Admin can change the stages.']);
        $rows = json_decode((string) ($_POST['stages'] ?? '[]'), true) ?: [];
        $keep = [];
        foreach ($rows as $i => $r) {
            $name = trim((string) ($r['name'] ?? ''));
            $kind = in_array($r['kind'] ?? '', ['open', 'won', 'lost'], true) ? $r['kind'] : 'open';
            if ($name === '') continue;
            $id = (int) ($r['id'] ?? 0);
            if ($id && isset($stageMap[$id])) {
                db_run("UPDATE crm_stages SET name=?, kind=?, sort=? WHERE id=? AND client_id=?", [mb_substr($name, 0, 80), $kind, ($i + 1) * 10, $id, $cid]);
            } else {
                $id = db_insert("INSERT INTO crm_stages (client_id,name,sort,kind,created_at) VALUES (?,?,?,?,NOW())", [$cid, mb_substr($name, 0, 80), ($i + 1) * 10, $kind]);
            }
            $keep[] = $id;
            // The stage's sub-statuses, edited in the same place. A Lost stage's are the lost reasons.
            if (isset($r['subs']) && is_array($r['subs'])) {
                if ($kind === 'lost') crm_options_save($cid, 'lost_reason', $r['subs']);
                else crm_substatuses_save($cid, $id, $r['subs']);
            }
        }
        if (!array_filter($rows, fn($r) => ($r['kind'] ?? 'open') === 'open' && trim((string) ($r['name'] ?? '')) !== '')) {
            json_out(['ok' => false, 'error' => 'Keep at least one open stage — new leads need somewhere to land.']);
        }
        // A removed stage's leads move to the first open one rather than vanishing from the board.
        $gone = array_diff(array_keys($stageMap), $keep);
        if ($gone) {
            $first = crm_first_stage($cid);
            foreach ($gone as $g) {
                if ((int) $g === $first) continue;
                foreach (db_all("SELECT id FROM contacts WHERE client_id=? AND stage_id=?", [$cid, (int) $g]) as $l) {
                    crm_set_stage($CLIENT, (int) $l['id'], $first, $me);
                }
                db_run("DELETE FROM crm_stages WHERE id=? AND client_id=?", [(int) $g, $cid]);
            }
        }
        json_out(['ok' => true]);
    }
    json_out(['ok' => false]);
}

$f = crm_list_filters($_GET);
if (is_sales() && !$isLeader) $f['owner'] = '';         // scoped already; the filter would only confuse

/* ── Export: the same filters as the screen, never more ── */
if (($x = (string) ($_GET['export'] ?? '')) !== '' || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export')) {
    if (!$canExport) { http_response_code(403); exit('Exporting leads is not allowed for your role.'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $x = (string) ($_POST['format'] ?? 'xlsx');
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        [$w, $p] = crm_list_where($cid, []);
        $rows = $ids ? crm_list_counters(db_all("SELECT c.*, s.name AS stage_name, s.kind AS stage_kind, COALESCE(NULLIF(u.name,''), u.email) AS owner_name,
                   COALESCE(c.crm_added_at, c.created_at) AS added_at,
                   GREATEST(COALESCE(c.last_touch_at, '2000-01-01'), COALESCE(c.last_inbound_at, '2000-01-01')) AS last_activity
              FROM contacts c JOIN crm_stages s ON s.id=c.stage_id LEFT JOIN users u ON u.id=c.owner_user_id
             WHERE $w AND c.id IN (" . implode(',', $ids) . ") ORDER BY c.id DESC", $p)) : [];
    } else {
        $rows = crm_list_all($cid, $f);
    }
    [$head, $out] = crm_list_export_table($cid, $rows, !function_exists('crm_phone_hidden') || !crm_phone_hidden());
    crm_log_export($cid, $me, count($out), $f);
    $fname = 'leads-' . date('Y-m-d');
    if ($x === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF");                 // so Excel reads Arabic names correctly
        fputcsv($o, $head);
        foreach ($out as $r) fputcsv($o, $r);
        exit;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fname . '.xlsx"');
    echo crm_xlsx($head, $out);
    exit;
}

$projects = crm_projects($cid);
$pnames   = array_column($projects, 'name', 'id');
$alerts   = crm_alert_counts($CLIENT, is_sales() ? $me : null);
$lostIds  = array_map('intval', array_keys(array_filter($stageMap, fn($st) => $st['kind'] === 'lost')));
$askLost  = (int) crm_settings($cid)['require_lost_reason'] === 1;
$view = ($_GET['view'] ?? '') === 'table' ? 'table' : 'board';
$sources = array_column(db_all("SELECT DISTINCT source FROM contacts WHERE client_id=? AND stage_id IS NOT NULL AND source IS NOT NULL", [$cid]), 'source');
$teams = crm_teams($cid);
$teamNames = array_column($teams, 'name', 'id');
$userTeam = [];
if (db_has_column('users', 'team_id')) foreach (db_all("SELECT id, team_id FROM users WHERE client_id=?", [$cid]) as $u_) $userTeam[(int) $u_['id']] = (int) $u_['team_id'];
$subsAll = crm_substatuses($cid);
$subsByStage = [];
foreach ($stages as $s_) $subsByStage[(int) $s_['id']] = $s_['kind'] === 'lost' ? [] : ($subsAll[(int) $s_['id']] ?? []);
$cfields = crm_fields($cid);
$units = crm_options($cid, 'unit_type');
$views = crm_list_views($cid, $me);
$builtin = crm_list_builtin_views();
$active = crm_list_active($f);
$qsNow = http_build_query($active + array_filter(['sort' => $f['sort'], 'dir' => $f['dir']]));

if ($view === 'board') {
    $leads = crm_leads($cid, $f);
    $byStage = [];
    foreach ($leads as $l) $byStage[(int) $l['stage_id']][] = $l;
    $total = count($leads);
} else {
    $per = (int) ($_GET['per'] ?? 50);
    $per = in_array($per, [25, 50, 100, 200], true) ? $per : 50;
    $pg = crm_list_page($cid, $f, (int) ($_GET['page'] ?? 1), $per);
    $leads = $pg['rows'];
    $total = $pg['total'];
    $allCols = crm_list_columns($cid);
    $cols = crm_list_user_columns($cid, $PERM_USER);
    if (is_sales() && !$isLeader) $cols = array_values(array_diff($cols, ['owner', 'team']));
    if (!$projects) $cols = array_values(array_diff($cols, ['project']));
    // A column empty on every row of this page says nothing; fold it away and say which.
    $emptyCols = [];
    if ($leads) {
        $filled = fn(string $k, array $l): bool => match ($k) {
            'lead', 'stage', 'owner', 'effort', 'last', 'added' => true,
            'phone' => true, 'heat' => $l['score'] !== null && $l['stage_kind'] === 'open',
            'team' => !empty($teamNames[$userTeam[(int) ($l['owner_user_id'] ?? 0)] ?? 0]),
            'project' => !empty($l['project_id']), 'unit' => !empty($l['unit_type']), 'budget' => !empty($l['budget']),
            'value' => $l['deal_value'] !== null, 'followup' => !empty($l['next_followup_at']), 'rotations' => $l['n_moved'] > 0,
            'source' => !empty($l['source']), 'platform' => !empty($l['platform']), 'campaign' => !empty($l['campaign']),
            'dtype' => !empty($l['data_type']), 'qual' => !empty($l['qualification']),
            default => str_starts_with($k, 'cf_') ? (crm_custom_get($l)[substr($k, 3)] ?? '') !== '' : true,
        };
        foreach ($cols as $k) {
            $any = false;
            foreach ($leads as $l) if ($filled($k, $l)) { $any = true; break; }
            if (!$any && !isset($active[$k === 'dtype' ? 'dtype' : $k])) $emptyCols[] = $k;
        }
        $cols = array_values(array_diff($cols, $emptyCols));
    }
}
$tiles = crm_list_tiles($cid);

$actions = '';
if ($canEdit) $actions .= '<button class="btn btn-primary btn-sm" onclick="crmAdd()">+ Add lead</button>';
if ($canEdit && can_crm('import')) $actions .= '<a class="btn btn-ghost btn-sm" href="crm_import.php">Import</a>';
if ($canExport) $actions .= '<a class="btn btn-ghost btn-sm" id="crm-export" href="crm.php?' . e(http_build_query($active + ['export' => 'xlsx'])) . '">Export Excel</a>';
if ($isAdmin) $actions .= '<button class="btn btn-ghost btn-sm" onclick="crmStages()">Stages</button>';

client_header('CRM', 'crm', $CLIENT);
page_head('Leads', $actions);

function crm_initials(string $n): string {
    $p = preg_split('/\s+/u', trim($n)) ?: [];
    return mb_strtoupper(mb_substr($p[0] ?? '?', 0, 1) . mb_substr($p[1] ?? '', 0, 1));
}
function crm_money($v): string { return $v !== null && $v !== '' ? number_format((float) $v) : ''; }
function crm_when(?string $d): string {
    if (!$d) return '';
    $t = strtotime($d); $days = (int) floor((strtotime(date('Y-m-d', $t)) - strtotime(date('Y-m-d'))) / 86400);
    return $days === 0 ? 'Today' : ($days === 1 ? 'Tomorrow' : ($days === -1 ? 'Yesterday' : date('j M', $t)));
}
function crm_ago(?string $d): string {
    if (!$d || str_starts_with($d, '2000')) return '—';
    $s = time() - strtotime($d);
    if ($s < 3600) return max(1, (int) floor($s / 60)) . ' min ago';
    if ($s < 86400) return (int) floor($s / 3600) . ' h ago';
    $days = (int) floor($s / 86400);
    return $days === 1 ? 'Yesterday' : ($days < 30 ? $days . ' days ago' : date('j M', strtotime($d)));
}
/** This page's URL with some filters changed. */
$link = function (array $set, array $drop = []) use ($active, $view, $f): string {
    $q = array_merge($active, array_filter(['sort' => $f['sort'], 'dir' => $f['dir']]), ['view' => $view], $set);
    foreach ($drop as $d) unset($q[$d]);
    unset($q['page']);
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== null));
};
/** Words for an active filter, for the chips. */
$chip = function (string $k, string $v) use ($stageMap, $people, $pnames, $teamNames, $cfields, $builtin, $cid): string {
    $names = array_column($people, 'name', 'id');
    return match (true) {
        $k === 'q'        => '“' . $v . '”',
        $k === 'stage'    => 'Stage: ' . ($stageMap[(int) $v]['name'] ?? '?'),
        $k === 'sub'      => $v === '__none' ? 'No sub-status' : 'Sub-status: ' . $v,
        $k === 'state'    => ['open' => 'Open leads', 'won' => 'Won', 'lost' => 'Lost'][$v] ?? $v,
        $k === 'owner'    => $v === 'none' ? 'Unassigned' : 'Owner: ' . ($names[(int) $v] ?? '?'),
        $k === 'team'     => 'Team: ' . ($teamNames[(int) $v] ?? '?'),
        $k === 'source'   => 'Source: ' . crm_source_label($v),
        $k === 'platform' => 'Platform: ' . crm_platform_label($v),
        $k === 'campaign' => $v === '__none' ? 'No campaign' : 'Campaign: ' . $v,
        $k === 'project'  => $v === 'none' ? 'No project' : 'Project: ' . ($pnames[(int) $v] ?? '?'),
        $k === 'unit'     => 'Unit: ' . $v,
        $k === 'dtype'    => crm_data_types()[$v] ?? $v,
        $k === 'qual'     => $v === 'none' ? 'Qualification not set' : (crm_qualifications()[$v] ?? $v),
        $k === 'heat'     => ucfirst($v) . ' leads',
        $k === 'status'   => ['late' => 'Not contacted in time', 'not_contacted' => 'Not contacted yet', 'no_followup' => 'No follow-up planned', 'again' => 'Came in more than once'][$v] ?? $v,
        $k === 'due'      => ['today' => 'Follow-up due today', 'overdue' => 'Overdue follow-ups', 'week' => 'Follow-up this week', 'none' => 'No follow-up'][$v] ?? $v,
        $k === 'added'    => ['today' => 'Added today', 'week' => 'Added this week', 'month' => 'Added in the last 30 days'][$v] ?? $v,
        $k === 'from'     => 'Added from ' . date('j M Y', strtotime($v)),
        $k === 'to'       => 'Added until ' . date('j M Y', strtotime($v)),
        $k === 'idle'     => 'Quiet for ' . (int) $v . '+ days',
        $k === 'ceq'      => $v === '__none' ? 'No campaign' : 'Campaign: ' . $v,
        $k === 'mcamp'    => 'Meta campaign ' . (db_val("SELECT campaign FROM contacts WHERE client_id=? AND meta_campaign_id=? AND campaign IS NOT NULL LIMIT 1", [$cid, $v]) ?: $v),
        $k === 'mad'      => 'Meta ad ' . (db_val("SELECT ad_name FROM contacts WHERE client_id=? AND meta_ad_id=? AND ad_name IS NOT NULL LIMIT 1", [$cid, $v]) ?: $v),
        $k === 'mfrom'    => 'Reached this stage from ' . date('j M Y', strtotime($v)),
        $k === 'mto'      => 'Reached this stage until ' . date('j M Y', strtotime($v)),
        $k === 'noans'    => 'No answer ' . (int) $v . '+ times',
        str_starts_with($k, 'cf_') => (array_column($cfields, 'label', 'fkey')[substr($k, 3)] ?? $k) . ': ' . $v,
        default           => $k . ': ' . $v,
    };
};
$curView = '';
foreach ($builtin as $bk => [$bn, $bq]) if ($active == $bq || ($active + array_filter(['sort' => $f['sort']])) == $bq) $curView = $bk;
foreach ($views as $v_) { parse_str((string) $v_['params'], $vq); if ($vq == $active) $curView = 'v' . $v_['id']; }
?>
<?php /* ── The numbers, each one a filter ── */ ?>
<div class="crm-tiles" role="list">
  <?php foreach ($tiles as [$label, $n, $tq]):
    $on = $tq && array_intersect_assoc($tq, $active) == $tq; ?>
    <a role="listitem" class="crm-tile <?= $on ? 'on' : '' ?> <?= $label === 'Overdue' && $n ? 'warn' : '' ?>" href="<?= e($on ? $link([], array_keys($tq)) : $link($tq)) ?>">
      <span class="lbl"><?= e($label) ?></span><span class="val"><?= number_format($n) ?></span></a>
  <?php endforeach; ?>
</div>

<form class="crm-bar" method="get" id="crm-filter">
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <?php if ($f['sort'] !== ''): ?><input type="hidden" name="sort" value="<?= e($f['sort']) ?>"><?php endif; ?>
  <?php if ($f['dir'] !== ''): ?><input type="hidden" name="dir" value="<?= e($f['dir']) ?>"><?php endif; ?>
  <select id="crm-views" aria-label="Saved views" onchange="if(this.value) location.href=this.value">
    <option value="<?= e('?view=' . $view) ?>"><?= $active ? 'All leads' : 'View: All leads' ?></option>
    <optgroup label="Views">
      <?php foreach ($builtin as $bk => [$bn, $bq]): ?><option value="<?= e('?' . http_build_query(['view' => $view] + $bq)) ?>" <?= $curView === $bk ? 'selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?>
    </optgroup>
    <?php if ($views): ?><optgroup label="Saved">
      <?php foreach ($views as $v_): ?><option value="<?= e('?view=' . $view . '&' . $v_['params']) ?>" <?= $curView === 'v' . $v_['id'] ? 'selected' : '' ?>><?= e((string) $v_['name']) ?><?= $v_['user_id'] === null ? ' (shared)' : '' ?></option><?php endforeach; ?>
    </optgroup><?php endif; ?>
  </select>
  <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search name, phone, code (#4DE2F2) or email" aria-label="Search leads">
  <button type="button" class="btn btn-ghost btn-sm" id="crm-adv-btn" aria-expanded="false" aria-controls="crm-adv">Filters<?= count(array_diff_key($active, ['q' => 1])) ? ' (' . count(array_diff_key($active, ['q' => 1])) . ')' : '' ?></button>
  <?php if ($view === 'table'): ?><button type="button" class="btn btn-ghost btn-sm" id="crm-cols-btn" aria-expanded="false" aria-controls="crm-cols">Columns</button><?php endif; ?>
  <span class="crm-view">
    <a href="<?= e($link(['view' => 'board'])) ?>" class="<?= $view === 'board' ? 'on' : '' ?>">Board</a>
    <a href="<?= e($link(['view' => 'table'])) ?>" class="<?= $view === 'table' ? 'on' : '' ?>">Table</a>
  </span>

  <?php /* ── Advanced search: every filter, in one panel ── */ ?>
  <div class="crm-adv" id="crm-adv" hidden>
    <div class="crm-adv-grid">
      <label>Open / closed<select name="state"><option value="">All</option>
        <?php foreach (['open' => 'Open', 'won' => 'Won', 'lost' => 'Lost'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['state'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label>Stage<select name="stage" id="adv-stage"><option value="">All stages</option>
        <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $f['stage'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
      <label>Sub-status<select name="sub" id="adv-sub"><option value="">Any</option><option value="__none" <?= $f['sub'] === '__none' ? 'selected' : '' ?>>None set</option>
        <?php foreach ($stages as $s): $list = $s['kind'] === 'lost' ? crm_options($cid, 'lost_reason') : ($subsAll[(int) $s['id']] ?? []); if (!$list) continue; ?>
          <optgroup label="<?= e($s['name']) ?>" data-stage="<?= (int) $s['id'] ?>"><?php foreach ($list as $sl): ?><option <?= $f['sub'] === $sl ? 'selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></optgroup>
        <?php endforeach; ?></select></label>
      <?php if (!is_sales() || $isLeader): ?>
      <label>Owner<select name="owner"><option value="">Everyone</option><?php if (!is_sales()): ?><option value="none" <?= $f['owner'] === 'none' ? 'selected' : '' ?>>Unassigned</option><?php endif; ?>
        <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $f['owner'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <?php if ($teams && !is_sales()): ?>
      <label>Team<select name="team"><option value="">All teams</option>
        <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $f['team'] === (string) $t['id'] ? 'selected' : '' ?>><?= e((string) $t['name']) ?></option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <label>Source<select name="source"><option value="">Any source</option>
        <?php foreach ($sources as $src): ?><option value="<?= e($src) ?>" <?= $f['source'] === $src ? 'selected' : '' ?>><?= e(crm_source_label($src)) ?></option><?php endforeach; ?></select></label>
      <label>Platform<select name="platform"><option value="">Any platform</option>
        <?php foreach (crm_platforms() as $k => $l): ?><option value="<?= $k ?>" <?= $f['platform'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label>Campaign<input name="campaign" value="<?= e($f['campaign']) ?>" placeholder="Part of the name" list="crm-campaigns"></label>
      <?php if ($projects): ?>
      <label>Project<select name="project"><option value="">Any project</option><option value="none" <?= $f['project'] === 'none' ? 'selected' : '' ?>>No project</option>
        <?php foreach ($projects as $pj): ?><option value="<?= (int) $pj['id'] ?>" <?= $f['project'] === (string) $pj['id'] ? 'selected' : '' ?>><?= e($pj['name']) ?></option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <label>Unit type<select name="unit"><option value="">Any</option>
        <?php foreach ($units as $u): ?><option <?= $f['unit'] === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select></label>
      <label>Fresh / cold<select name="dtype"><option value="">Both</option>
        <?php foreach (crm_data_types() as $k => $l): ?><option value="<?= $k ?>" <?= $f['dtype'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label>Qualified<select name="qual"><option value="">Any</option><option value="none" <?= $f['qual'] === 'none' ? 'selected' : '' ?>>Not decided</option>
        <?php foreach (crm_qualifications() as $k => $l): ?><option value="<?= $k ?>" <?= $f['qual'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label>Heat<select name="heat"><option value="">Hot, warm and cold</option>
        <?php foreach (['hot' => 'Hot only', 'warm' => 'Warm only', 'cold' => 'Cold only'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['heat'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label>Status<select name="status"><option value="">Any</option>
        <?php foreach (['not_contacted' => 'Not contacted yet', 'no_followup' => 'No follow-up planned', 'again' => 'Came in more than once'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label>Follow-up<select name="due"><option value="">Any</option>
        <?php foreach (['today' => 'Due today', 'overdue' => 'Overdue', 'week' => 'In the next 7 days', 'none' => 'None planned'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['due'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label>Added<select name="added"><option value="">Any time</option>
        <?php foreach (['today' => 'Today', 'week' => 'Last 7 days', 'month' => 'Last 30 days'] as $k => $l): ?><option value="<?= $k ?>" <?= $f['added'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
      <label>Added from<input type="date" name="from" value="<?= e($f['from']) ?>"></label>
      <label>Added until<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
      <label>No activity for (days)<input type="number" name="idle" min="1" value="<?= e($f['idle']) ?>" placeholder="7"></label>
      <label>No answer at least (times)<input type="number" name="noans" min="1" value="<?= e($f['noans']) ?>" placeholder="3"></label>
      <?php foreach ($cfields as $cf): $k = 'cf_' . $cf['fkey']; $v = (string) ($f[$k] ?? ''); ?>
        <label><?= e((string) $cf['label']) ?>
          <?php if ($cf['type'] === 'list'): ?><select name="<?= e($k) ?>"><option value="">Any</option><?php foreach ($cf['choices'] as $ch): ?><option <?= $v === $ch ? 'selected' : '' ?>><?= e($ch) ?></option><?php endforeach; ?></select>
          <?php elseif ($cf['type'] === 'date'): ?><input type="date" name="<?= e($k) ?>" value="<?= e($v) ?>">
          <?php else: ?><input name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endif; ?></label>
      <?php endforeach; ?>
    </div>
    <div class="crm-adv-foot">
      <button class="btn btn-primary btn-sm">Show leads</button>
      <a class="btn btn-ghost btn-sm" href="?view=<?= e($view) ?>">Clear all</a>
    </div>
  </div>
</form>
<datalist id="crm-campaigns"><?php foreach (db_has_column('contacts', 'campaign') ? array_column(db_all("SELECT DISTINCT campaign FROM contacts WHERE client_id=? AND campaign IS NOT NULL AND campaign<>'' AND stage_id IS NOT NULL ORDER BY campaign LIMIT 200", [$cid]), 'campaign') : [] as $cn): ?><option value="<?= e($cn) ?>"><?php endforeach; ?></datalist>

<?php if ($active): ?>
<div class="crm-chips" aria-label="Filters in use">
  <?php foreach ($active as $k => $v): ?><a class="crm-chip" href="<?= e($link([], [$k])) ?>" title="Remove this filter"><?= e($chip((string) $k, (string) $v)) ?> <span aria-hidden="true">×</span></a><?php endforeach; ?>
  <a class="crm-chip-clear" href="?view=<?= e($view) ?>">Clear all</a>
  <button type="button" class="btn-link" id="crm-save-view">Save as a view</button>
  <?php if (preg_match('/^v(\d+)$/', $curView, $mm)): $cv = array_values(array_filter($views, fn($x) => (int) $x['id'] === (int) $mm[1]))[0] ?? null;
        if ($cv && ((int) $cv['user_id'] === $me || ($cv['user_id'] === null && $isAdmin))): ?>
    <button type="button" class="btn-link" style="color:var(--danger)" data-del-view="<?= (int) $cv['id'] ?>">Delete this view</button>
  <?php endif; endif; ?>
</div>
<?php endif; ?>

<?php if ($alerts['late'] && $f['status'] !== 'not_contacted'): ?>
  <div class="alert error" style="font-size:12.5px" id="crm-late"><strong><?= $alerts['late'] ?></strong> lead<?= $alerts['late'] === 1 ? ' has' : 's have' ?> not been contacted in time.
    <a href="?<?= e(http_build_query(['view' => 'table', 'status' => 'not_contacted', 'sort' => 'newest'])) ?>">Show them</a></div>
<?php endif; ?>
<?php if (!is_sales() && $alerts['unassigned'] && $f['owner'] !== 'none'): ?>
  <div class="alert info" style="font-size:12.5px"><?= $alerts['unassigned'] ?> open lead<?= $alerts['unassigned'] === 1 ? ' has' : 's have' ?> no owner.
    <a href="?<?= e(http_build_query(['view' => 'table', 'owner' => 'none'])) ?>">Show them</a><?= $isAdmin ? ' · <a href="crm_team.php#transfer">Share them out</a>' : '' ?></div>
<?php endif; ?>

<?php if (!$leads): ?>
  <div class="card empty-state">
    <h2><?= $active ? 'No leads match those filters' : (is_sales() ? 'No leads assigned to you yet' : 'No leads yet') ?></h2>
    <p class="text-muted"><?= $active ? 'Remove a filter above, or <a href="?view=' . e($view) . '">clear them all</a>.' : (is_sales()
        ? 'New conversations are shared out between the sales team automatically. Yours will appear here.'
        : 'Leads arrive here by themselves when someone messages you. You can also add them by hand or import a spreadsheet.') ?></p>
  </div>
<?php elseif ($view === 'board'): ?>
  <div class="crm-board" id="crm-board">
    <?php foreach ($stages as $s): $col = $byStage[(int) $s['id']] ?? [];
      $sum = array_sum(array_map(fn($l) => (float) $l['deal_value'], $col)); ?>
      <section class="crm-col crm-<?= e($s['kind']) ?>" data-stage="<?= (int) $s['id'] ?>">
        <header><strong><?= e($s['name']) ?></strong> <span class="crm-n"><?= count($col) ?></span>
          <?php if ($sum > 0): ?><span class="crm-sum"><?= crm_money($sum) ?></span><?php endif; ?></header>
        <div class="crm-cards">
          <?php foreach ($col as $l):
            $due  = $l['next_followup_at'] && $l['stage_kind'] === 'open' ? strtotime((string) $l['next_followup_at']) : 0;
            $late = $due && $due < time(); ?>
            <article class="crm-card" draggable="<?= $canEdit ? 'true' : 'false' ?>" data-id="<?= (int) $l['id'] ?>">
              <a class="crm-card-name" href="crm_lead.php?id=<?= (int) $l['id'] ?>"><?= e((string) ($l['name'] ?: crm_phone_show((string) $l['phone_e164']))) ?></a>
              <?php if ($l['name']): ?><div class="crm-card-phone"><?= e(crm_phone_show((string) $l['phone_e164'])) ?></div><?php endif; ?>
              <?php [$hc, $hw] = crm_heat(isset($l['score']) && $l['score'] !== null ? (int) $l['score'] : null);
                    $notYet = $l['stage_kind'] === 'open' && $l['owner_user_id'] !== null && empty($l['first_response_at']);
                    $proj = !empty($l['project_id']) ? ($pnames[(int) $l['project_id']] ?? '') : ''; ?>
              <?php if ($hc || $proj || $notYet || (int) ($l['submissions'] ?? 1) > 1 || !empty($l['substatus'])): ?>
              <div class="crm-card-tags">
                <?php if ($hc && $l['stage_kind'] === 'open'): ?><span class="heat <?= $hc ?>"><?= $hw ?></span><?php endif; ?>
                <?php if (!empty($l['substatus'])): ?><span class="crm-flag sub"><?= e((string) $l['substatus']) ?></span><?php endif; ?>
                <?php if ($proj): ?><span class="crm-flag"><?= e($proj) ?></span><?php endif; ?>
                <?php if ((int) ($l['submissions'] ?? 1) > 1): ?><span class="crm-flag">×<?= (int) $l['submissions'] ?></span><?php endif; ?>
                <?php if ($notYet): ?><span class="crm-flag late">Not contacted</span><?php endif; ?>
              </div>
              <?php endif; ?>
              <?php if ($l['last_body']): ?><div class="crm-card-last"><?= e(mb_substr((string) $l['last_body'], 0, 70)) ?></div><?php endif; ?>
              <div class="crm-card-foot">
                <?php if ($l['deal_value'] !== null): ?><span class="crm-val"><?= crm_money($l['deal_value']) ?></span><?php endif; ?>
                <?php if ($due): ?><span class="crm-due <?= $late ? 'late' : '' ?>" title="Next follow-up"><?= e(crm_when((string) $l['next_followup_at'])) ?></span><?php endif; ?>
                <span class="crm-owner" title="<?= e((string) ($l['owner_name'] ?? 'Unassigned')) ?>"><?= $l['owner_name'] ? e(crm_initials((string) $l['owner_name'])) : '—' ?></span>
              </div>
              <?php if ($canEdit): ?>
                <select class="crm-move" aria-label="Move to stage" onchange="crmMove([<?= (int) $l['id'] ?>], this.value)">
                  <?php foreach ($stages as $s2): ?><option value="<?= (int) $s2['id'] ?>" <?= (int) $s2['id'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s2['name']) ?></option><?php endforeach; ?>
                </select>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
  <?php if ($total >= 2000): ?><p class="text-muted" style="font-size:12.5px">The board shows the first 2,000 leads. Filter, or use the table, to see the rest.</p><?php endif; ?>
<?php else: ?>
  <?php if ($canEdit): ?>
  <div class="crm-bulk" id="crm-bulk" hidden>
    <span id="crm-bulk-n"></span>
    <button type="button" class="btn-link" id="crm-bulk-all" hidden>Select all <?= number_format($total) ?> matching</button>
    <select id="crm-bulk-stage" aria-label="Move to stage"><option value="">Move to stage…</option>
      <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
    <?php if ($canMove): ?>
    <button type="button" class="btn btn-ghost btn-sm" id="crm-bulk-transfer" data-open="m-transfer">⇄ Bulk transfer</button>
    <?php if ($isAdmin): ?><select id="crm-bulk-owner" aria-label="Quick assign"><option value="">Quick assign…</option><option value="auto">Share out by the assignment rules</option><option value="none">Unassign</option></select><?php endif; ?>
    <?php endif; ?>
    <button type="button" class="btn btn-ghost btn-sm" data-open="m-edit">Edit field</button>
    <?php if ($isAdmin): ?>
      <button type="button" class="btn btn-ghost btn-sm" data-open="m-tpl">WhatsApp template</button>
      <button type="button" class="btn btn-ghost btn-sm" data-open="m-seq">Add to sequence</button>
    <?php endif; ?>
    <?php if ($evChoices): ?><button type="button" class="btn btn-ghost btn-sm" data-open="m-event">Add to event</button><?php endif; ?>
    <?php if ($canExport): ?><button type="button" class="btn btn-ghost btn-sm" id="crm-bulk-export">Export selected</button><?php endif; ?>
    <?php if ($canDelete): ?><button type="button" class="btn btn-ghost btn-sm" id="crm-bulk-delete" style="color:var(--danger)">Delete</button><?php endif; ?>
    <button type="button" class="btn-link" id="crm-bulk-clear">Clear</button>
  </div>
  <?php endif; ?>

  <?php if ($emptyCols): ?><p class="crm-empty-note text-muted">Hidden because nothing on this page has them: <?= e(implode(', ', array_map(fn($k) => $allCols[$k][0] ?? $k, $emptyCols))) ?>.</p><?php endif; ?>

  <div class="card card-flush"><div class="table-wrap crm-table-wrap">
    <table class="data crm-table">
      <thead><tr>
        <?php if ($canEdit): ?><th class="crm-cb"><input type="checkbox" id="crm-all" aria-label="Select all on this page"></th><?php endif; ?>
        <?php foreach ($cols as $k): [$lbl, $sortSql] = $allCols[$k];
          $isSort = $f['sort'] === $k; $nextDir = $isSort && $f['dir'] !== 'asc' ? 'asc' : 'desc'; ?>
          <th class="<?= in_array($k, ['value', 'effort', 'rotations'], true) ? 'num' : '' ?>" data-col="<?= e($k) ?>">
            <?php if ($sortSql !== ''): ?><a class="crm-sort <?= $isSort ? 'on ' . ($f['dir'] === 'asc' ? 'asc' : 'desc') : '' ?>" href="<?= e($link(['sort' => $k, 'dir' => $nextDir])) ?>"><?= e($lbl) ?></a>
            <?php else: ?><?= e($lbl) ?><?php endif; ?></th>
        <?php endforeach; ?>
        <th class="crm-acts-h"><span class="sr-only">Actions</span></th>
      </tr>
      <tr class="crm-colfilter">
        <?php if ($canEdit): ?><th></th><?php endif; ?>
        <?php foreach ($cols as $k): ?><th><?php
          $sel = function (string $name, array $opts, string $cur) { $h = '<select data-f="' . e($name) . '" aria-label="Filter"><option value="">All</option>';
              foreach ($opts as $v => $l) $h .= '<option value="' . e((string) $v) . '"' . ((string) $v === $cur ? ' selected' : '') . '>' . e((string) $l) . '</option>';
              return $h . '</select>'; };
          echo match ($k) {
              'stage'    => $sel('stage', array_column($stages, 'name', 'id'), (string) $f['stage']),
              'owner'    => $sel('owner', ['none' => 'Unassigned'] + array_column($people, 'name', 'id'), $f['owner']),
              'team'     => $sel('team', $teamNames, $f['team']),
              'project'  => $sel('project', ['none' => 'None'] + $pnames, $f['project']),
              'unit'     => $sel('unit', array_combine($units, $units) ?: [], $f['unit']),
              'heat'     => $sel('heat', ['hot' => 'Hot', 'warm' => 'Warm', 'cold' => 'Cold'], $f['heat']),
              'followup' => $sel('due', ['today' => 'Today', 'overdue' => 'Overdue', 'week' => 'Next 7 days', 'none' => 'None'], $f['due']),
              'source'   => $sel('source', array_combine($sources, array_map('crm_source_label', $sources)) ?: [], $f['source']),
              'platform' => $sel('platform', crm_platforms(), $f['platform']),
              'dtype'    => $sel('dtype', crm_data_types(), $f['dtype']),
              'qual'     => $sel('qual', ['none' => 'Not decided'] + crm_qualifications(), $f['qual']),
              'campaign' => '<input data-f="campaign" value="' . e($f['campaign']) . '" placeholder="Filter…" list="crm-campaigns" aria-label="Filter campaign">',
              'lead'     => '',
              default    => str_starts_with($k, 'cf_') ? (function () use ($k, $cfields, $f, $sel) {
                              $cf = array_values(array_filter($cfields, fn($c) => 'cf_' . $c['fkey'] === $k))[0] ?? null;
                              if (!$cf) return '';
                              if ($cf['type'] === 'list') return $sel($k, array_combine($cf['choices'], $cf['choices']) ?: [], (string) ($f[$k] ?? ''));
                              return '<input data-f="' . e($k) . '" value="' . e((string) ($f[$k] ?? '')) . '" placeholder="Filter…" aria-label="Filter">';
                          })() : '',
          }; ?></th><?php endforeach; ?>
        <th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($leads as $l):
        $late = $l['next_followup_at'] && strtotime((string) $l['next_followup_at']) < time() && $l['stage_kind'] === 'open';
        $custom = crm_custom_get($l); ?>
        <tr data-id="<?= (int) $l['id'] ?>" data-stage="<?= (int) $l['stage_id'] ?>">
          <?php if ($canEdit): ?><td class="crm-cb"><input type="checkbox" class="crm-pick" value="<?= (int) $l['id'] ?>" data-name="<?= e((string) ($l['name'] ?: '#' . $l['code'])) ?>" data-phone="<?= e(crm_phone_show((string) $l['phone_e164'])) ?>" aria-label="Select <?= e((string) ($l['name'] ?: $l['code'])) ?>"></td><?php endif; ?>
          <?php foreach ($cols as $k): $lbl = $allCols[$k][0]; ?>
            <td data-label="<?= e($lbl) ?>" class="<?= 'c-' . e($k) ?><?= in_array($k, ['value', 'effort', 'rotations'], true) ? ' num' : '' ?><?= $k === 'followup' && $late ? ' crm-late' : '' ?>">
            <?php switch ($k):
              case 'lead': ?>
                <a href="crm_lead.php?id=<?= (int) $l['id'] ?>" class="crm-name"><strong><?= e((string) ($l['name'] ?: crm_phone_show((string) $l['phone_e164']))) ?></strong></a>
                <span class="crm-sub-line"><?php if (!empty($l['code'])): ?><span class="crm-code">#<?= e((string) $l['code']) ?></span><?php endif; ?>
                  <?php if (!in_array('phone', $cols, true)): ?><?= e(crm_phone_show((string) $l['phone_e164'])) ?><?php endif; ?></span>
              <?php break; case 'phone': ?><?php if ($hidePhones): ?><button type="button" class="btn-link" data-reveal="<?= (int) $l['id'] ?>" data-how="call" title="Call — the number is recorded as opened"><?= e(crm_phone_mask((string) $l['phone_e164'])) ?></button>
                <?php else: ?><a href="tel:+<?= e((string) $l['phone_e164']) ?>">+<?= e((string) $l['phone_e164']) ?></a><?php endif; ?>
              <?php break; case 'stage': ?>
                <span class="pill <?= $l['stage_kind'] === 'won' ? 'green' : ($l['stage_kind'] === 'lost' ? 'red' : 'gray') ?>"><?= e((string) $l['stage_name']) ?></span>
                <?php $sub = (string) ($l['substatus'] ?? '') ?: ($l['stage_kind'] === 'lost' ? (string) ($l['lost_reason'] ?? '') : ''); if ($sub !== ''): ?><span class="crm-sub-line"><?= e($sub) ?></span><?php endif; ?>
              <?php break; case 'heat': [$hc, $hw] = crm_heat($l['score'] !== null ? (int) $l['score'] : null);
                if ($hc && $l['stage_kind'] === 'open'): ?><span class="heat <?= $hc ?>"><?= $hw ?> · <?= (int) $l['score'] ?></span><?php endif; ?>
              <?php break; case 'owner': ?><?= $l['owner_name'] ? e((string) $l['owner_name']) : '<span class="text-muted">Unassigned</span>' ?>
              <?php break; case 'team': ?><?= e($teamNames[$userTeam[(int) ($l['owner_user_id'] ?? 0)] ?? 0] ?? '') ?>
              <?php break; case 'project': ?><?= e((string) ($pnames[(int) ($l['project_id'] ?? 0)] ?? '')) ?><?= !empty($l['unit_type']) && !in_array('unit', $cols, true) ? '<span class="crm-sub-line">' . e((string) $l['unit_type']) . '</span>' : '' ?>
              <?php break; case 'unit': ?><?= e((string) ($l['unit_type'] ?? '')) ?>
              <?php break; case 'budget': ?><?= e((string) ($l['budget'] ?? '')) ?>
              <?php break; case 'value': ?><?= crm_money($l['deal_value']) ?>
              <?php break; case 'followup': ?><?= e(crm_when($l['next_followup_at'])) ?><?= $l['next_followup_at'] ? '<span class="crm-sub-line">' . e(date('H:i', strtotime((string) $l['next_followup_at']))) . '</span>' : '' ?>
              <?php break; case 'effort': ?>
                <span class="crm-effort" title="Calls · answered · no answer · visits"><span title="Calls">📞 <?= $l['n_calls'] ?></span><span class="ok" title="Answered">✓ <?= $l['n_answered'] ?></span><span class="bad" title="No answer">✕ <?= $l['n_noanswer'] ?></span><span title="Site visits">⌂ <?= $l['n_visits'] ?></span></span>
              <?php break; case 'rotations': ?><?= $l['n_moved'] ?: '' ?>
              <?php break; case 'source': ?><span class="text-muted"><?= e(crm_source_label($l['source'])) ?></span>
              <?php break; case 'platform': ?><?= !empty($l['platform']) ? e(crm_platform_label($l['platform'])) : '' ?>
              <?php break; case 'campaign': ?><?= e((string) ($l['campaign'] ?? '')) ?>
              <?php break; case 'dtype': ?><?= !empty($l['data_type']) ? '<span class="pill ' . ($l['data_type'] === 'fresh' ? 'green' : 'gray') . '">' . e(crm_data_types()[$l['data_type']] ?? '') . '</span>' : '' ?>
              <?php break; case 'qual': ?><?= !empty($l['qualification']) ? e(crm_qualifications()[$l['qualification']] ?? '') : '' ?>
              <?php break; case 'last': ?><span class="text-muted"><?= e(crm_ago((string) $l['last_activity'])) ?></span>
              <?php break; case 'added': ?><span class="text-muted"><?= e(date('j M Y', strtotime((string) $l['added_at']))) ?></span>
              <?php break; default:
                if (str_starts_with($k, 'cf_')) { $cf = array_values(array_filter($cfields, fn($c) => 'cf_' . $c['fkey'] === $k))[0] ?? null; echo $cf ? e(crm_custom_show($cf, $custom[$cf['fkey']] ?? '')) : ''; }
            endswitch; ?></td>
          <?php endforeach; ?>
          <td class="crm-acts">
            <?php if ($canEdit): ?><button type="button" class="btn btn-ghost btn-sm" data-log="<?= (int) $l['id'] ?>">Log call</button><?php endif; ?>
            <a class="btn btn-ghost btn-sm" href="crm_lead.php?id=<?= (int) $l['id'] ?>">Open</a>
            <button type="button" class="crm-more-btn" aria-label="More details" aria-expanded="false">More</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
  <nav class="crm-pager" aria-label="Pages">
    <span class="text-muted">Showing <?= number_format(($pg['page'] - 1) * $pg['per'] + 1) ?>–<?= number_format(min($total, $pg['page'] * $pg['per'])) ?> of <?= number_format($total) ?></span>
    <span class="crm-pager-links">
      <?php if ($pg['page'] > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['page' => $pg['page'] - 1, 'per' => $per === 50 ? '' : $per])) ?>">&larr; Previous</a><?php endif; ?>
      <span>Page <?= $pg['page'] ?> of <?= $pg['pages'] ?></span>
      <?php if ($pg['page'] < $pg['pages']): ?><a class="btn btn-ghost btn-sm" href="<?= e($link(['page' => $pg['page'] + 1, 'per' => $per === 50 ? '' : $per])) ?>">Next &rarr;</a><?php endif; ?>
    </span>
    <select aria-label="Leads per page" onchange="location.href=this.value">
      <?php foreach ([25, 50, 100, 200] as $n_): ?><option value="<?= e($link(['per' => $n_])) ?>" <?= $per === $n_ ? 'selected' : '' ?>><?= $n_ ?> per page</option><?php endforeach; ?>
    </select>
  </nav>

  <?php /* ── Columns chooser ── */ ?>
  <div class="crm-pop" id="crm-cols" hidden>
    <strong>Columns</strong>
    <div class="crm-cols-list">
      <?php $mineCols = crm_list_user_columns($cid, $PERM_USER);
        foreach ($allCols as $k => [$lbl]): if ($k === 'lead' || (is_sales() && !$isLeader && in_array($k, ['owner', 'team'], true))) continue; ?>
        <label class="mod-all"><input type="checkbox" value="<?= e($k) ?>" <?= in_array($k, $mineCols, true) ? 'checked' : '' ?>> <?= e($lbl) ?></label>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px"><button type="button" class="btn btn-primary btn-sm" id="crm-cols-save">Save columns</button>
      <button type="button" class="btn-link" id="crm-cols-reset">Back to the defaults</button></div>
  </div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal-back" id="m-add">
  <form class="modal" id="add-form" onsubmit="return crmAddSave(event)">
    <h2>Add a lead</h2>
    <div class="grid2">
      <div class="field"><span class="lbl">Name</span><input name="name" placeholder="Ahmed Mahmoud"></div>
      <div class="field"><span class="lbl">Phone *</span><input name="phone" type="tel" autocomplete="tel" required placeholder="01001234567 or +201001234567"></div>
      <div class="field"><span class="lbl">Email</span><input name="email" type="email"></div>
      <div class="field"><span class="lbl">Deal value</span><input name="deal_value" inputmode="decimal" autocomplete="off" placeholder="5,000,000"></div>
      <div class="field"><span class="lbl">Stage</span><select name="stage_id">
        <?php foreach ($stages as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <?php if (!is_sales()): ?>
      <div class="field"><span class="lbl">Owner</span><select name="owner">
        <option value="auto">Share out (next in rotation)</option><option value="none">Leave unassigned</option>
        <?php foreach ($people as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field"><span class="lbl">Next follow-up</span><input name="followup" type="datetime-local"></div>
      <?php if ($projects): ?>
      <div class="field"><span class="lbl">Project</span><select name="project_id"><option value="0">—</option>
        <?php foreach ($projects as $pj): if (!(int) $pj['active']) continue; ?><option value="<?= (int) $pj['id'] ?>"><?= e($pj['name']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field"><span class="lbl">Unit type</span><select name="unit_type"><option value="">—</option>
        <?php foreach ($units as $u): ?><option><?= e($u) ?></option><?php endforeach; ?></select></div>
      <div class="field"><span class="lbl">Budget</span><input name="budget" maxlength="80" placeholder="5–7M"></div>
      <div class="field"><span class="lbl">Campaign</span><input name="campaign" maxlength="160" list="crm-campaigns" placeholder="Where they came from"></div>
    </div>
    <div class="field"><span class="lbl">Note</span><textarea name="note" rows="2" placeholder="What they asked about, budget, timing…"></textarea></div>
    <div class="alert error" id="add-err" hidden></div>
    <div class="row-between mt10"><span></span><span style="display:flex;gap:8px">
      <button type="button" class="btn btn-ghost" onclick="$m('m-add').classList.remove('open')">Cancel</button>
      <button class="btn btn-primary">Add lead</button></span></div>
  </form>
</div>

<?php /* ── Log a call from the list ── */ ?>
<dialog id="m-log" class="lost-dlg" aria-labelledby="log-title">
  <form id="log-form" onsubmit="return logSave(event)">
    <h2 id="log-title" style="margin-top:0">Log a call — <span id="log-name"></span></h2>
    <input type="hidden" name="id">
    <div class="act-kinds" role="radiogroup" aria-label="What did you do">
      <?php foreach (['call' => 'Call', 'whatsapp' => 'WhatsApp', 'meeting' => 'Meeting', 'note' => 'Comment'] as $k => $l): ?>
        <label class="act-kind"><input type="radio" name="kind" value="<?= $k ?>" <?= $k === 'call' ? 'checked' : '' ?>><span><?= $l ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="grid2">
      <div class="field"><span class="lbl">How did it go</span><select name="outcome"><option value="">—</option>
        <?php foreach (crm_activity_outcomes() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field" id="log-sub-wrap"><span class="lbl">Sub-status</span><select name="substatus"></select></div>
    </div>
    <div class="field"><span class="lbl">Comment</span><textarea name="body" rows="2" placeholder="What was said"></textarea></div>
    <div class="lead-quick" data-for="log-next"></div>
    <div class="grid2">
      <div class="field"><span class="lbl">Next follow-up</span><input type="datetime-local" name="next_at" id="log-next"></div>
      <div class="field"><span class="lbl">What for</span><input name="next_note" maxlength="255" placeholder="Call back with prices"></div>
    </div>
    <div class="alert error" id="log-err" hidden></div>
    <div style="display:flex;gap:8px"><button class="btn btn-primary">Save</button>
      <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button></div>
  </form>
</dialog>

<?php /* ── Bulk: edit a field ── */ ?>
<dialog id="m-edit" class="lost-dlg" aria-labelledby="edit-title">
  <form onsubmit="return bulkField(event)">
    <h2 id="edit-title" style="margin-top:0">Change a field on <span class="bulk-count"></span></h2>
    <div class="field"><span class="lbl">Field</span><select name="field" id="edit-field" required>
      <option value="">Choose…</option>
      <?php if ($projects): ?><option value="project">Project</option><?php endif; ?>
      <option value="unit">Unit type</option><option value="qual">Qualified</option>
      <?php if ($isAdmin): ?><option value="dtype">Fresh / cold</option><?php endif; ?>
      <option value="campaign">Campaign</option><option value="followup">Next follow-up</option>
      <?php foreach ($cfields as $cf): ?><option value="cf_<?= e($cf['fkey']) ?>"><?= e((string) $cf['label']) ?></option><?php endforeach; ?>
    </select></div>
    <div class="field"><span class="lbl">New value</span>
      <select data-for="project"><option value="0">No project</option><?php foreach ($projects as $pj): ?><option value="<?= (int) $pj['id'] ?>"><?= e($pj['name']) ?></option><?php endforeach; ?></select>
      <select data-for="unit"><option value="">—</option><?php foreach ($units as $u): ?><option><?= e($u) ?></option><?php endforeach; ?></select>
      <select data-for="qual"><option value="">Not decided</option><?php foreach (crm_qualifications() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
      <select data-for="dtype"><?php foreach (crm_data_types() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
      <input data-for="campaign" maxlength="160" list="crm-campaigns" placeholder="Leave empty to clear">
      <input data-for="followup" type="datetime-local">
      <?php foreach ($cfields as $cf): ?>
        <?php if ($cf['type'] === 'list'): ?><select data-for="cf_<?= e($cf['fkey']) ?>"><option value="">— clear —</option><?php foreach ($cf['choices'] as $ch): ?><option><?= e($ch) ?></option><?php endforeach; ?></select>
        <?php else: ?><input data-for="cf_<?= e($cf['fkey']) ?>" <?= $cf['type'] === 'date' ? 'type="date"' : '' ?> placeholder="Leave empty to clear"><?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div class="alert error" id="edit-err" hidden></div>
    <div style="display:flex;gap:8px"><button class="btn btn-primary">Change</button>
      <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button></div>
  </form>
</dialog>

<?php if ($isAdmin): $tpls = array_filter(crm_tpl_choices($cid), fn($t) => $t['media'] === '');
  $seqs = []; try { $seqs = db_all("SELECT id, name FROM crm_sequences WHERE client_id=? AND active=1 ORDER BY name", [$cid]); } catch (Throwable $e) {} ?>
<dialog id="m-tpl" class="lost-dlg" aria-labelledby="tpl-title">
  <form onsubmit="return bulkTpl(event)">
    <h2 id="tpl-title" style="margin-top:0">Send a WhatsApp template to <span class="bulk-count"></span></h2>
    <?php if (!crm_auto_api_ready($CLIENT)): ?>
      <p class="note warn">Connect the WhatsApp Business API number in <a href="settings.php">Settings</a> first — templates to many leads go from it.</p>
    <?php elseif (!$tpls): ?>
      <p class="note warn">No approved templates without a picture yet. Create one in <a href="templates.php">Templates</a>.</p>
    <?php else: ?>
      <p class="text-muted" style="font-size:12.5px;margin-top:-4px">Goes from the Business API number, inside your working hours. Leads who opted out are skipped.</p>
      <div class="field"><span class="lbl">Template</span><select name="template_id" id="tpl-pick" required><option value="">Choose…</option>
        <?php foreach ($tpls as $t): ?><option value="<?= (int) $t['id'] ?>" data-vars="<?= (int) $t['body'] ?>" data-text="<?= e($t['text']) ?>"><?= e($t['name']) ?> (<?= e($t['lang']) ?>)</option><?php endforeach; ?></select></div>
      <p class="tpl-preview text-muted" id="tpl-text"></p>
      <div id="tpl-vars"></div>
      <div class="alert error" id="tpl-err" hidden></div>
      <div style="display:flex;gap:8px"><button class="btn btn-primary">Queue messages</button>
        <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button></div>
    <?php endif; ?>
  </form>
</dialog>
<dialog id="m-seq" class="lost-dlg" aria-labelledby="seq-title">
  <form onsubmit="return bulkSeq(event)">
    <h2 id="seq-title" style="margin-top:0">Add <span class="bulk-count"></span> to a follow-up sequence</h2>
    <?php if (!$seqs): ?><p class="note warn">No sequences yet. Build one in <a href="crm_messages.php">Automatic messages</a>.</p>
    <?php else: ?>
      <div class="field"><span class="lbl">Sequence</span><select name="sequence_id" required><?php foreach ($seqs as $sq): ?><option value="<?= (int) $sq['id'] ?>"><?= e((string) $sq['name']) ?></option><?php endforeach; ?></select></div>
      <p class="text-muted" style="font-size:12.5px">Won, lost and opted-out leads, and leads already in it, are skipped.</p>
      <div class="alert error" id="seq-err" hidden></div>
      <div style="display:flex;gap:8px"><button class="btn btn-primary">Add them</button>
        <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button></div>
    <?php endif; ?>
  </form>
</dialog>
<?php endif; ?>
<?php if ($evChoices): ?>
<dialog id="m-event" class="lost-dlg" aria-labelledby="ev-title">
  <form onsubmit="return bulkEvent(event)">
    <h2 id="ev-title" style="margin-top:0">Add <span class="bulk-count"></span> to an event</h2>
    <div class="field"><span class="lbl">Event</span><select name="event_id" required>
      <?php foreach ($evChoices as $ec): ?><option value="<?= (int) $ec['id'] ?>"><?= e($ec['name'] . ' — ' . date('j M', strtotime((string) $ec['starts_at']))) ?></option><?php endforeach; ?></select></div>
    <p class="text-muted" style="font-size:12.5px">They go on the guest list. The invitation is sent from the event's page.</p>
    <div class="alert error" id="ev-err" hidden></div>
    <div style="display:flex;gap:8px"><button class="btn btn-primary">Add them</button>
      <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php if ($canMove):
  require_once __DIR__ . '/../includes/crm_transfer.php';
  $trPeople = crm_transfer_targets($cid);
  $trTeams = $isAdmin ? $teams : array_values(array_filter($teams, fn($t) => in_array((int) $t['id'], crm_led_team_ids((int) $me), true))); ?>
<dialog id="m-transfer" class="lost-dlg tr-dlg" aria-labelledby="tr-title">
  <form onsubmit="return bulkTransfer(event)">
    <h2 id="tr-title" style="margin:0">Bulk transfer</h2>
    <p class="text-muted" id="tr-snap" style="margin:4px 0 12px;font-size:13px"></p>
    <div class="lbl-sm">Selected leads (<span class="bulk-n"></span>)</div>
    <ul class="tr-list" id="tr-list"></ul>
    <div class="tr-seg" role="tablist">
      <button type="button" class="on" data-mode="users" role="tab">Transfer to sales</button>
      <button type="button" data-mode="teams" role="tab" <?= $trTeams ? '' : 'disabled title="No teams yet — add them on Team & transfer"' ?>>Transfer to team</button>
    </div>
    <div id="tr-users">
      <div class="lbl-sm">Transfer to</div>
      <div class="tr-quota"><span id="tr-quota"></span><button type="button" class="btn btn-ghost btn-sm" id="tr-add">+ Add person</button></div>
      <div id="tr-rows"></div>
      <p class="text-muted" style="font-size:12px;margin:4px 0 0">Leave the number empty to share the rest equally.</p>
    </div>
    <div id="tr-teams" hidden>
      <div class="lbl-sm">Teams — their members share the leads equally</div>
      <?php foreach ($trTeams as $t): ?><label class="mod-all"><input type="checkbox" class="tr-team" value="<?= (int) $t['id'] ?>"> <?= e((string) $t['name']) ?> <span class="text-muted">(<?= (int) $t['members'] ?>)</span></label><?php endforeach; ?>
    </div>
    <label class="tr-box"><span><strong>Smart rotation — avoid previous owners</strong>
      <small class="text-muted">The current owner is always left out. When on, people who have never had the lead are chosen first.</small></span>
      <span class="switch"><input type="checkbox" id="tr-smart"><span class="slider"></span></span></label>
    <div class="field"><span class="lbl">Lead type after transfer</span><select id="tr-dtype"><option value="">Keep the current type</option>
      <?php foreach (crm_data_types() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><span class="lbl">Reason <span class="text-muted">(optional)</span></span><input id="tr-reason" maxlength="255" placeholder="Not contacted for a week"></div>
    <div class="field"><span class="lbl">Notes <span class="text-muted">(optional)</span></span><textarea id="tr-notes" rows="2" maxlength="1000"></textarea></div>
    <label class="mod-all"><input type="checkbox" id="tr-keepfu"> Keep the follow-up date</label>
    <div class="field mt10"><span class="lbl">History visibility</span><select id="tr-history">
      <?php foreach (crm_transfer_policies() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select>
      <small class="text-muted" id="tr-history-note">Everyone sees the full history.</small></div>
    <label class="tr-box" id="tr-fresh-box"><span><strong>Start again as a new lead</strong>
      <small class="text-muted">Moves it back to the first stage and clears the sub-status. Needs the earlier history hidden.</small></span>
      <span class="switch"><input type="checkbox" id="tr-fresh" disabled><span class="slider"></span></span></label>
    <label class="tr-box"><span><strong>Show the last status</strong>
      <small class="text-muted">The stage and sub-status before the transfer stay visible on the lead even when its history is hidden.</small></span>
      <input type="checkbox" id="tr-status"></label>
    <div class="alert error" id="tr-err" hidden></div>
    <div class="dlg-btns" style="justify-content:flex-end"><button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close()">Cancel</button>
      <button class="btn btn-primary" id="tr-go">Confirm transfer</button></div>
  </form>
</dialog>
<script>const TR_PEOPLE = <?= json_encode(array_map(fn($u) => ['id' => (int) $u['id'], 'name' => $u['name']], $trPeople), JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>
<?php if ($canExport): ?><form method="post" id="export-sel" hidden><?= csrf_field() ?><input type="hidden" name="action" value="export"><input type="hidden" name="format" value="xlsx"></form><?php endif; ?>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="modal-back" id="m-stages">
  <div class="modal">
    <h2>Stages</h2>
    <p class="text-muted" style="font-size:12.5px;margin-top:-6px">Drag to reorder. <strong>Won</strong> and
      <strong>Lost</strong> close a lead and drive the conversion figures in Reports, whatever you name them.
      Removing a stage moves its leads to the first open stage. Under each stage, its sub-statuses:
      type one and press Enter to add it, × to remove it. For a Lost stage they are the reasons a lead is lost.</p>
    <div id="st-list"></div>
    <button type="button" class="btn btn-ghost btn-sm" onclick="stAdd()">+ Add stage</button>
    <div class="alert error" id="st-err" hidden></div>
    <div class="row-between mt10"><span></span><span style="display:flex;gap:8px">
      <button type="button" class="btn btn-ghost" onclick="$m('m-stages').classList.remove('open')">Cancel</button>
      <button type="button" class="btn btn-primary" onclick="stSave()">Save stages</button></span></div>
  </div>
</div>
<?php endif; ?>

<?php if ($canEdit && $askLost && $lostIds): ?>
<dialog id="lost-dlg" class="lost-dlg" aria-labelledby="lost-title">
  <form onsubmit="return lostSave(event)">
    <h2 id="lost-title" style="margin-top:0">Why <span id="lost-what">was this lead</span> lost?</h2>
    <div class="lost-reasons">
      <?php foreach (crm_options($cid, 'lost_reason') as $r): ?><label class="act-kind"><input type="radio" name="lost_reason" value="<?= e($r) ?>" required><span><?= e($r) ?></span></label><?php endforeach; ?>
    </div>
    <div class="field"><span class="lbl">Anything to add (optional)</span><input name="lost_note" maxlength="255"></div>
    <div style="display:flex;gap:8px"><button class="btn btn-primary">Mark as lost</button>
      <button type="button" class="btn btn-ghost" onclick="this.closest('dialog').close(); location.reload()">Cancel</button></div>
  </form>
</dialog>
<?php endif; ?>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const STAGES = <?= json_encode(array_map(fn($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'kind' => $s['kind'],
    'subs' => $s['kind'] === 'lost' ? crm_options($cid, 'lost_reason') : ($subsAll[(int) $s['id']] ?? [])], $stages), JSON_UNESCAPED_UNICODE) ?>;
const SUBS = <?= json_encode($subsByStage, JSON_UNESCAPED_UNICODE) ?>;
const QS = <?= json_encode($qsNow) ?>;
const TOTAL = <?= (int) $total ?>;
const $m = id => document.getElementById(id);
const escH = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

async function crmPost(data){
  const fd = new FormData(); fd.append('ajax','1'); fd.append('csrf_token', CSRF);
  for (const [k,v] of Object.entries(data)) (Array.isArray(v) ? v : [v]).forEach(x => fd.append(Array.isArray(v) ? k+'[]' : k, x));
  const r = await fetch('crm.php', {method:'POST', body:fd});
  return r.json().catch(() => ({ok:false, error:'Something went wrong.'}));
}

/* Which leads a bulk action is for: the ticked ones, or all that match the filters. */
let ALL = false;
const picks = () => [...document.querySelectorAll('.crm-pick:checked')].map(c => c.value);
const target = () => ALL ? {all: 1, qs: QS} : {ids: picks()};
const nTarget = () => ALL ? TOTAL : picks().length;

async function crmMove(ids, stageId, extra){
  const d = await crmPost(Object.assign({action:'stage', stage_id:stageId}, ids ? {ids} : target(), extra || {}));
  if (d.need_reason && $m('lost-dlg')) {
    // Moving to Lost asks why first; the move happens when the reason is given.
    LOST_PENDING = {ids, stageId};
    const n = ids ? ids.length : nTarget();
    $m('lost-what').textContent = n === 1 ? 'was this lead' : 'were these ' + n + ' leads';
    $m('lost-dlg').querySelector('form').reset(); $m('lost-dlg').showModal(); return;
  }
  if (d.ok) location.reload(); else alert(d.error || 'Could not move it.');
}
let LOST_PENDING = null;
function lostSave(e){
  e.preventDefault();
  const fd = new FormData(e.target);
  crmMove(LOST_PENDING.ids, LOST_PENDING.stageId, {lost_reason: fd.get('lost_reason'), lost_note: fd.get('lost_note') || ''});
  return false;
}

/* Board drag and drop. Cards also carry a stage <select>, because touch screens do not drag. */
document.querySelectorAll('.crm-card[draggable="true"]').forEach(c => {
  c.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', c.dataset.id); c.classList.add('dragging'); });
  c.addEventListener('dragend', () => c.classList.remove('dragging'));
});
document.querySelectorAll('.crm-col').forEach(col => {
  col.addEventListener('dragover', e => { e.preventDefault(); col.classList.add('over'); });
  col.addEventListener('dragleave', () => col.classList.remove('over'));
  col.addEventListener('drop', e => {
    e.preventDefault(); col.classList.remove('over');
    const id = e.dataTransfer.getData('text/plain');
    if (id) crmMove([id], col.dataset.stage);
  });
});

/* Panels: advanced search and columns. */
function toggle(btn, panel){ const p = $m(panel), b = $m(btn); if (!p || !b) return;
  b.addEventListener('click', () => { p.hidden = !p.hidden; b.setAttribute('aria-expanded', String(!p.hidden)); }); }
toggle('crm-adv-btn', 'crm-adv'); toggle('crm-cols-btn', 'crm-cols');
/* Sub-status list follows the chosen stage. */
const advStage = $m('adv-stage'), advSub = $m('adv-sub');
if (advStage && advSub) { const sync = () => advSub.querySelectorAll('optgroup').forEach(g => g.hidden = !!advStage.value && g.dataset.stage !== advStage.value);
  advStage.addEventListener('change', sync); sync(); }
/* A filter under a column changes that one filter in the address, leaving the rest as they are. */
const colFilter = el => { const u = new URL(location.href); el.value ? u.searchParams.set(el.dataset.f, el.value) : u.searchParams.delete(el.dataset.f);
  u.searchParams.delete('page'); location.href = u.toString(); };
document.querySelectorAll('.crm-colfilter select[data-f]').forEach(x => x.addEventListener('change', () => colFilter(x)));
document.querySelectorAll('.crm-colfilter input[data-f]').forEach(x => {
  x.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); colFilter(x); } });
  x.addEventListener('change', () => colFilter(x)); });

$m('crm-cols-save')?.addEventListener('click', async () => {
  const cols = [...document.querySelectorAll('#crm-cols input:checked')].map(i => i.value);
  const d = await crmPost({action:'columns', cols}); if (d.ok) location.reload();
});
$m('crm-cols-reset')?.addEventListener('click', async () => { const d = await crmPost({action:'columns', cols: []}); if (d.ok) location.reload(); });

/* Saved views. */
$m('crm-save-view')?.addEventListener('click', async () => {
  const name = prompt('Name this view — e.g. "My hot Badya leads"'); if (!name) return;
  const shared = <?= $isAdmin ? "confirm('Share it with everyone on the team? (Cancel keeps it just for you.)')" : 'false' ?>;
  const d = await crmPost({action:'view_save', name, qs: QS, shared: shared ? 1 : ''});
  if (d.ok) location.reload(); else alert(d.error || 'Could not save.');
});
document.querySelectorAll('[data-del-view]').forEach(b => b.addEventListener('click', async () => {
  if (!confirm('Delete this view? The leads are not affected.')) return;
  await crmPost({action:'view_delete', id: b.dataset.delView}); location.href = '?view=<?= e($view) ?>';
}));

/* Table: select, and the bulk bar. */
function bulkShow(){ const n = picks().length; const b = $m('crm-bulk'); if (!b) return;
  if (!n) ALL = false;
  b.hidden = !n; $m('crm-bulk-n').textContent = (ALL ? TOTAL.toLocaleString() : n) + ' selected';
  const allBtn = $m('crm-bulk-all'); if (allBtn) allBtn.hidden = ALL || n < document.querySelectorAll('.crm-pick').length || TOTAL <= n;
  document.querySelectorAll('.bulk-count').forEach(s => s.textContent = nTarget().toLocaleString() + (nTarget() === 1 ? ' lead' : ' leads')); }
document.querySelectorAll('.crm-pick').forEach(c => c.addEventListener('change', bulkShow));
$m('crm-all')?.addEventListener('change', e => { document.querySelectorAll('.crm-pick').forEach(c => c.checked = e.target.checked); bulkShow(); });
$m('crm-bulk-all')?.addEventListener('click', () => { ALL = true; bulkShow(); });
$m('crm-bulk-clear')?.addEventListener('click', () => { document.querySelectorAll('.crm-pick, #crm-all').forEach(c => c.checked = false); ALL = false; bulkShow(); });
$m('crm-bulk-stage')?.addEventListener('change', e => { if (e.target.value) crmMove(null, e.target.value); });
$m('crm-bulk-owner')?.addEventListener('change', async e => {
  if (!e.target.value) return;
  if (nTarget() > 20 && !confirm('Transfer ' + nTarget().toLocaleString() + ' leads?')) { e.target.value = ''; return; }
  const d = await crmPost(Object.assign({action:'assign', user_id:e.target.value}, target()));
  if (d.ok) location.reload(); else alert(d.error || 'Could not reassign.');
});
document.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => { bulkShow(); $m(b.dataset.open).showModal(); }));
$m('crm-bulk-export')?.addEventListener('click', () => {
  const f = $m('export-sel'); f.querySelectorAll('input[name="ids[]"]').forEach(i => i.remove());
  if (ALL) { location.href = $m('crm-export').href; return; }
  picks().forEach(id => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; f.appendChild(i); });
  f.submit();
});

/* Bulk: change one field. */
const editField = $m('edit-field');
if (editField) { const sync = () => document.querySelectorAll('#m-edit [data-for]').forEach(x => x.hidden = x.dataset.for !== editField.value);
  editField.addEventListener('change', sync); sync(); }
async function bulkField(e){
  e.preventDefault();
  const inp = document.querySelector(`#m-edit [data-for="${editField.value}"]`);
  const d = await crmPost(Object.assign({action:'set_field', field: editField.value, value: inp ? inp.value : ''}, target()));
  if (d.ok) location.reload(); else { $m('edit-err').hidden = false; $m('edit-err').textContent = d.error || 'Could not change it.'; }
  return false;
}
/* Bulk: a template, with a value for each {{n}}. */
const TOKENS = <?= json_encode(crm_tpl_tokens(), JSON_UNESCAPED_UNICODE) ?>;
function tokGroups(f){const g={};Object.entries(TOKENS).forEach(([k,l])=>{const i=l.indexOf(': '),p=i>0?l.slice(0,i):'';(g[p]=g[p]||[]).push(f([k,l]))});return Object.entries(g).map(([p,o])=>p?`<optgroup label="${p}">${o.join('')}</optgroup>`:o.join('')).join('')}
$m('tpl-pick')?.addEventListener('change', e => {
  const o = e.target.selectedOptions[0]; const n = parseInt(o.dataset.vars || '0', 10);
  $m('tpl-text').textContent = o.dataset.text || '';
  $m('tpl-vars').innerHTML = [...Array(n)].map((_, i) => `<div class="grid2"><div class="field"><span class="lbl">{{${i+1}}}</span>
    <select name="vars[${i+1}]" onchange="this.closest('.grid2').querySelector('input').hidden = this.value !== 'text'">${tokGroups(([k,l]) => `<option value="${k}" ${i===0 && k==='first_name' ? 'selected' : ''}>${escH(l)}</option>`)}</select></div>
    <div class="field"><span class="lbl">&nbsp;</span><input name="vars_text[${i+1}]" hidden placeholder="Fixed text"></div></div>`).join('');
});
async function bulkTpl(e){
  e.preventDefault();
  if (!confirm('Send this template to ' + nTarget().toLocaleString() + ' leads?')) return false;
  const data = Object.assign({action:'template'}, target(), Object.fromEntries(new FormData(e.target)));
  const d = await crmPost(data);
  if (d.ok) { alert(d.n + ' message' + (d.n === 1 ? '' : 's') + ' queued. They go out within your working hours.'); location.reload(); }
  else { $m('tpl-err').hidden = false; $m('tpl-err').textContent = d.error || 'Could not queue them.'; }
  return false;
}
async function bulkEvent(e){
  e.preventDefault();
  const d = await crmPost(Object.assign({action:'event'}, target(), Object.fromEntries(new FormData(e.target))));
  if (d.ok) { alert(d.n + ' lead' + (d.n === 1 ? '' : 's') + ' added to the event.'); location.href = d.url; }
  else { $m('ev-err').hidden = false; $m('ev-err').textContent = d.error || 'Could not add them.'; }
  return false;
}
async function bulkSeq(e){
  e.preventDefault();
  const d = await crmPost(Object.assign({action:'sequence'}, target(), Object.fromEntries(new FormData(e.target))));
  if (d.ok) { alert(d.n + ' lead' + (d.n === 1 ? '' : 's') + ' added to the sequence.'); location.reload(); }
  else { $m('seq-err').hidden = false; $m('seq-err').textContent = d.error || 'Could not add them.'; }
  return false;
}

/* Log a call from the list. */
document.querySelectorAll('[data-log]').forEach(b => b.addEventListener('click', () => {
  const tr = b.closest('tr'), dlg = $m('m-log'), f = $m('log-form');
  f.reset(); $m('log-err').hidden = true;
  f.id.value = b.dataset.log;
  $m('log-name').textContent = tr.querySelector('.crm-name')?.textContent.trim() || '';
  const list = SUBS[tr.dataset.stage] || [];
  $m('log-sub-wrap').hidden = !list.length;
  f.substatus.innerHTML = '<option value="">—</option>' + list.map(l => `<option>${escH(l)}</option>`).join('');
  dlg.showModal();
}));
async function logSave(e){
  e.preventDefault();
  const d = await crmPost(Object.assign({action:'quick_log'}, Object.fromEntries(new FormData(e.target))));
  if (d.ok) location.reload(); else { $m('log-err').hidden = false; $m('log-err').textContent = d.error || 'Could not save.'; }
  return false;
}
/* Quick picks for a follow-up. */
(() => {
  const pad = n => String(n).padStart(2, '0');
  const local = d => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  const at = (days, h) => { const d = new Date(); d.setDate(d.getDate() + days); d.setHours(h, 0, 0, 0); return d; };
  const opts = [['In 1 hour', () => new Date(Date.now() + 3600e3)], ['Tomorrow 10:00', () => at(1, 10)], ['Tomorrow 17:00', () => at(1, 17)], ['In 3 days', () => at(3, 11)], ['Next week', () => at(7, 11)]];
  document.querySelectorAll('.lead-quick').forEach(box => { const input = $m(box.dataset.for);
    opts.forEach(([l, fn]) => { const b = document.createElement('button'); b.type = 'button'; b.textContent = l;
      b.onclick = () => { input.value = local(fn()); box.querySelectorAll('button').forEach(x => x.classList.remove('on')); b.classList.add('on'); }; box.appendChild(b); }); });
})();

/* A hidden number: pressing it opens the call (or WhatsApp) — and the opening is recorded. */
document.querySelectorAll('[data-reveal]').forEach(b => b.addEventListener('click', async () => {
  const fd = new FormData(); fd.append('csrf_token', CSRF); fd.append('action', 'reveal'); fd.append('how', b.dataset.how || 'call'); fd.append('id', b.dataset.reveal);
  const d = await fetch('crm_lead.php?id=' + b.dataset.reveal, {method:'POST', body:fd}).then(r => r.json()).catch(() => ({}));
  if (!d.phone) { alert(d.error || 'Could not open the number.'); return; }
  location.href = (b.dataset.how === 'whatsapp' ? 'https://wa.me/' : 'tel:+') + d.phone;
}));
$m('crm-bulk-delete')?.addEventListener('click', async () => {
  if (!confirm('Delete ' + nTarget().toLocaleString() + ' lead' + (nTarget() === 1 ? '' : 's') + '? They go to the recycle bin and can be brought back for 30 days.')) return;
  const d = await crmPost(Object.assign({action:'delete'}, target()));
  if (d.ok) location.reload(); else alert(d.error || 'Could not delete.');
});

/* Phone: each row is a card; "More" opens the rest of its details. */
document.querySelectorAll('.crm-more-btn').forEach(b => b.addEventListener('click', () => {
  const tr = b.closest('tr'); tr.classList.toggle('open'); b.setAttribute('aria-expanded', String(tr.classList.contains('open')));
  b.textContent = tr.classList.contains('open') ? 'Less' : 'More';
}));

function crmAdd(){ $m('add-form').reset(); $m('add-err').hidden = true; $m('m-add').classList.add('open'); }
async function crmAddSave(e){
  e.preventDefault();
  const data = Object.fromEntries(new FormData(e.target)); data.action = 'add';
  const d = await crmPost(data);
  if (d.ok && d.pending) { $m('m-add').classList.remove('open'); alert('Sent to a manager for approval. You will be told when it is added.'); return false; }
  if (d.ok) { location.href = 'crm_lead.php?id=' + d.id; return false; }
  $m('add-err').hidden = false;
  $m('add-err').innerHTML = (d.error || 'Could not add the lead.') + (d.id ? ` <a href="crm_lead.php?id=${d.id}">Open it</a>` : '');
  return false;
}


/* Bulk transfer: the selection as it is now, several people with numbers or whole teams, and the options. */
(function(){
  const d = $m('m-transfer'); if (!d) return;
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  let mode = 'users';
  const rows = $m('tr-rows');
  const quota = () => {
    const sum = [...rows.querySelectorAll('.tr-n')].reduce((a, i) => a + (parseInt(i.value, 10) || 0), 0);
    $m('tr-quota').textContent = 'Numbers requested: ' + sum + ' · Selected: ' + nTarget();
  };
  const addRow = () => {
    const r = document.createElement('div'); r.className = 'tr-row';
    r.innerHTML = `<select class="tr-u" aria-label="Salesperson"><option value="">Choose a person…</option>${TR_PEOPLE.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('')}</select>
      <input class="tr-n" type="number" min="1" placeholder="#" aria-label="How many leads">
      <button type="button" class="icon-btn" title="Remove" aria-label="Remove">✕</button>`;
    r.querySelector('button').onclick = () => { r.remove(); quota(); };
    r.querySelector('.tr-n').addEventListener('input', quota);
    rows.appendChild(r);
  };
  $m('tr-add').onclick = addRow;
  d.querySelectorAll('.tr-seg button').forEach(b => b.onclick = () => {
    mode = b.dataset.mode; d.querySelectorAll('.tr-seg button').forEach(x => x.classList.toggle('on', x === b));
    $m('tr-users').hidden = mode !== 'users'; $m('tr-teams').hidden = mode !== 'teams';
  });
  const hist = $m('tr-history'), fresh = $m('tr-fresh');
  const notes = {'': 'Everyone sees the full history.', sales: 'The new salesperson sees only what happens from now on. Managers see everything.',
                 leaders: 'The new salesperson and their team leader see only what happens from now on. Admins see everything.'};
  hist.onchange = () => { fresh.disabled = hist.value === ''; if (fresh.disabled) fresh.checked = false;
    $m('tr-fresh-box').classList.toggle('off', fresh.disabled); $m('tr-history-note').textContent = notes[hist.value]; };
  hist.onchange();
  // Opening it: take a snapshot of what is selected.
  $m('crm-bulk-transfer')?.addEventListener('click', () => {
    const list = $m('tr-list'); list.innerHTML = '';
    const picked = [...document.querySelectorAll('.crm-pick:checked')];
    picked.slice(0, 200).forEach(c => { const li = document.createElement('li'); li.innerHTML = `<strong>${esc(c.dataset.name)}</strong> <span class="ltr">${esc(c.dataset.phone)}</span>`; list.appendChild(li); });
    $m('tr-snap').textContent = ALL ? 'All ' + TOTAL.toLocaleString() + ' leads matching the filter.'
                                    : 'Selection snapshot: ' + picked.length + ' leads. It will not grow to other results.';
    d.querySelectorAll('.bulk-n').forEach(s => s.textContent = nTarget().toLocaleString());
    if (!rows.children.length) addRow();
    $m('tr-err').hidden = true; quota();
  });
  window.bulkTransfer = async e => {
    e.preventDefault();
    const opts = {mode, smart: $m('tr-smart').checked, data_type: $m('tr-dtype').value, reason: $m('tr-reason').value, notes: $m('tr-notes').value,
      keep_followup: $m('tr-keepfu').checked, history: hist.value, fresh_start: fresh.checked, show_status: $m('tr-status').checked,
      users: [...rows.querySelectorAll('.tr-row')].map(r => ({id: r.querySelector('.tr-u').value, count: r.querySelector('.tr-n').value})).filter(u => u.id),
      teams: [...d.querySelectorAll('.tr-team:checked')].map(x => x.value)};
    $m('tr-go').disabled = true;
    const res = await crmPost(Object.assign({action: 'transfer', opts: JSON.stringify(opts)}, target()));
    $m('tr-go').disabled = false;
    if (res.ok) { alert(res.n + ' lead' + (res.n === 1 ? '' : 's') + ' transferred' + (res.text ? ': ' + res.text : '') + '.' + (res.skipped ? ' ' + res.skipped + ' left as they were (no one else to give them to).' : '')); location.reload(); }
    else { $m('tr-err').hidden = false; $m('tr-err').textContent = res.error || 'Could not transfer them.'; }
    return false;
  };
})();

/* Stage editor. */
function stRow(s){
  const div = document.createElement('div'); div.className = 'st-row'; div.draggable = true; div.dataset.id = s.id || '';
  div.innerHTML = `<span class="st-grip" aria-hidden="true">≡</span>
    <input class="st-name" value="${(s.name||'').replace(/"/g,'&quot;')}" placeholder="Stage name">
    <select class="st-kind"><option value="open">Open</option><option value="won">Won</option><option value="lost">Lost</option></select>
    <button type="button" class="icon-btn" title="Remove" onclick="this.closest('.st-row').remove()">✕</button>
    <div class="st-subs"><span class="st-subs-l">Sub-statuses</span><span class="st-chips"></span>
      <input class="st-sub-new" placeholder="+ Add a sub-status" maxlength="80" aria-label="Add a sub-status"></div>`;
  div.querySelector('.st-kind').value = s.kind || 'open';
  const chips = div.querySelector('.st-chips');
  const addChip = t => { t = t.trim(); if (!t || [...chips.children].some(x => x.dataset.v.toLowerCase() === t.toLowerCase())) return;
    const ch = document.createElement('span'); ch.className = 'st-chip'; ch.dataset.v = t; ch.textContent = t;
    const x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.title = 'Remove'; x.setAttribute('aria-label', 'Remove ' + t);
    x.onclick = () => ch.remove(); ch.appendChild(x); chips.appendChild(ch); };
  (s.subs || []).forEach(addChip);
  const inp = div.querySelector('.st-sub-new');
  inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addChip(inp.value); inp.value = ''; } });
  inp.addEventListener('blur', () => { if (inp.value.trim()) { addChip(inp.value); inp.value = ''; } });
  const lbl = () => div.querySelector('.st-subs-l').textContent = div.querySelector('.st-kind').value === 'lost' ? 'Lost reasons' : 'Sub-statuses';
  div.querySelector('.st-kind').addEventListener('change', lbl); lbl();
  div.querySelectorAll('input, select').forEach(el => { el.addEventListener('mousedown', () => div.draggable = false); el.addEventListener('blur', () => div.draggable = true); });
  div.addEventListener('dragstart', () => div.classList.add('dragging'));
  div.addEventListener('dragend', () => div.classList.remove('dragging'));
  return div;
}
function crmStages(){
  const list = $m('st-list'); list.innerHTML = '';
  STAGES.forEach(s => list.appendChild(stRow(s)));
  list.ondragover = e => { e.preventDefault(); const drag = list.querySelector('.dragging');
    const after = [...list.querySelectorAll('.st-row:not(.dragging)')].find(r => e.clientY < r.getBoundingClientRect().top + r.offsetHeight/2);
    after ? list.insertBefore(drag, after) : list.appendChild(drag); };
  $m('st-err').hidden = true; $m('m-stages').classList.add('open');
}
function stAdd(){ $m('st-list').appendChild(stRow({name:'', kind:'open', subs: []})); }
async function stSave(){
  const stages = [...document.querySelectorAll('#st-list .st-row')].map(r => ({
    id: r.dataset.id, name: r.querySelector('.st-name').value, kind: r.querySelector('.st-kind').value,
    subs: [...r.querySelectorAll('.st-chip')].map(x => x.dataset.v) }));
  const d = await crmPost({action:'stages', stages: JSON.stringify(stages)});
  if (d.ok) location.href = 'crm.php'; else { $m('st-err').hidden = false; $m('st-err').textContent = d.error || 'Could not save.'; }
}
<?php if ($isAdmin && !empty($_GET['stages'])): ?>crmStages();   // opened from the CRM menu → Stages<?php endif; ?>
</script>
<?php layout_footer();
