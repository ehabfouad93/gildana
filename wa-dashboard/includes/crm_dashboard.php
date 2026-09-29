<?php
declare(strict_types=1);

/**
 * The numbers behind the manager dashboard.
 *
 * Every figure is counted from the CRM's own record — the history of stage moves and
 * assignments, the activity log, messages, visits — never from a stored total, so a number here
 * can always be traced back to the leads that make it up.
 *
 * A salesperson sees only their own leads (crm_scope), exactly as everywhere else.
 */

require_once __DIR__ . '/crm_reports.php';

/** The people the dashboard is about: Sales first, then Admins who hold leads. A salesperson: only themselves. */
function crm_dash_people(int $clientId, bool $wholeTeam = false): array
{
    $rows = db_all("SELECT id, COALESCE(NULLIF(name,''), email) AS name, client_role FROM users
                     WHERE client_id=? AND role='client' AND status='active'
                       AND (client_role='sales' OR id IN (SELECT DISTINCT owner_user_id FROM contacts WHERE client_id=? AND owner_user_id IS NOT NULL))
                     ORDER BY client_role='sales' DESC, name", [$clientId, $clientId]);
    if (!$wholeTeam && function_exists('is_sales') && is_sales()) {
        $me = (int) crm_actor_id();
        $rows = array_values(array_filter($rows, fn($r) => (int) $r['id'] === $me));
    }
    return $rows;
}

/** COUNT(*) grouped by one user column, as [user_id => n]. */
function crm_dash_count(string $sql, array $p): array
{
    $out = [];
    foreach (db_all($sql, $p) as $r) if ($r['uid'] !== null) $out[(int) $r['uid']] = (int) $r['n'];
    return $out;
}

/**
 * Today, per person: new leads given to them, calls and WhatsApps, leads contacted for the first
 * time, visits booked, visits today, deals won — and how fast they answer new leads (median, last
 * 7 days).
 */
function crm_dash_today(int $clientId): array
{
    $t0 = date('Y-m-d 00:00:00'); $t1 = date('Y-m-d 23:59:59');
    $won = crm_stage_kinds($clientId)['won'] ?: [0];
    $ph = implode(',', array_fill(0, count($won), '?'));
    $m = [
        'new'      => crm_dash_count("SELECT e.to_val uid, COUNT(DISTINCT e.contact_id) n FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                                        WHERE c.client_id=? AND e.kind='assigned' AND e.created_at BETWEEN ? AND ? GROUP BY e.to_val", [$clientId, $t0, $t1]),
        'calls'    => crm_dash_count("SELECT n.user_id uid, COUNT(*) n FROM crm_notes n WHERE n.client_id=? AND n.kind='call' AND n.created_at BETWEEN ? AND ? GROUP BY n.user_id", [$clientId, $t0, $t1]),
        'whatsapp' => db_has_column('messages', 'sent_by_user_id')
            ? crm_dash_count("SELECT m.sent_by_user_id uid, COUNT(*) n FROM messages m WHERE m.client_id=? AND m.direction='out' AND m.sent_by_user_id IS NOT NULL
                                AND m.created_at BETWEEN ? AND ? GROUP BY m.sent_by_user_id", [$clientId, $t0, $t1]) : [],
        'contacted'=> crm_dash_count("SELECT owner_user_id uid, COUNT(*) n FROM contacts WHERE client_id=? AND first_response_at BETWEEN ? AND ? GROUP BY owner_user_id", [$clientId, $t0, $t1]),
        'booked'   => crm_dash_count("SELECT user_id uid, COUNT(*) n FROM crm_visits WHERE client_id=? AND created_at BETWEEN ? AND ? GROUP BY user_id", [$clientId, $t0, $t1]),
        'visits'   => crm_dash_count("SELECT user_id uid, COUNT(*) n FROM crm_visits WHERE client_id=? AND status<>'cancelled' AND starts_at BETWEEN ? AND ? GROUP BY user_id", [$clientId, $t0, $t1]),
        'won'      => crm_dash_count("SELECT c.owner_user_id uid, COUNT(DISTINCT c.id) n FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                                        WHERE c.client_id=? AND e.kind='stage' AND e.to_val IN ($ph) AND c.stage_id IN ($ph) AND e.created_at BETWEEN ? AND ? GROUP BY c.owner_user_id",
                                     array_merge([$clientId], array_map('strval', $won), $won, [$t0, $t1])),
    ];
    // Response time: from being given the lead to first contacting it, leads given in the last 7 days.
    $resp = [];
    foreach (db_all("SELECT e.to_val uid, TIMESTAMPDIFF(SECOND, e.created_at, c.first_response_at) s
                       FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                      WHERE c.client_id=? AND e.kind='assigned' AND e.to_val=CAST(c.owner_user_id AS CHAR)
                        AND e.created_at > NOW() - INTERVAL 7 DAY AND c.first_response_at >= e.created_at", [$clientId]) as $r)
        $resp[(int) $r['uid']][] = (int) $r['s'];

    $rows = [];
    foreach (crm_dash_people($clientId) as $u) {
        $id = (int) $u['id'];
        $row = ['id' => $id, 'name' => $u['name']];
        foreach ($m as $k => $vals) $row[$k] = $vals[$id] ?? 0;
        $row['response'] = crm_median($resp[$id] ?? []);
        $rows[] = $row;
    }
    return $rows;
}

/* ───────────────────────── targets ───────────────────────── */

function crm_targets(int $clientId, string $month): array
{
    $out = [];
    try { foreach (db_all("SELECT * FROM crm_targets WHERE client_id=? AND month=?", [$clientId, $month]) as $t) $out[(int) $t['user_id']] = $t; }
    catch (Throwable $e) {}
    return $out;
}

function crm_target_save(int $clientId, int $userId, string $month, array $v): void
{
    $n = fn($k) => ($x = trim((string) ($v[$k] ?? ''))) === '' ? null : max(0, (float) preg_replace('/[^\d.]/', '', $x));
    db_run("INSERT INTO crm_targets (client_id,user_id,month,contacted,visits,sales,sales_value,updated_at) VALUES (?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE contacted=VALUES(contacted), visits=VALUES(visits), sales=VALUES(sales), sales_value=VALUES(sales_value), updated_at=NOW()",
           [$clientId, $userId, $month, $n('contacted') !== null ? (int) $n('contacted') : null, $n('visits') !== null ? (int) $n('visits') : null,
            $n('sales') !== null ? (int) $n('sales') : null, $n('sales_value')]);
}

/** What each person actually did in a month, in the same four measures as the targets. */
function crm_actuals(int $clientId, string $month, bool $wholeTeam = false): array
{
    $t0 = $month . '-01 00:00:00'; $t1 = date('Y-m-t 23:59:59', strtotime($t0));
    $won = crm_stage_kinds($clientId)['won'] ?: [0];
    $ph = implode(',', array_fill(0, count($won), '?'));
    $contacted = crm_dash_count("SELECT owner_user_id uid, COUNT(*) n FROM contacts WHERE client_id=? AND first_response_at BETWEEN ? AND ? GROUP BY owner_user_id", [$clientId, $t0, $t1]);
    $visits = crm_dash_count("SELECT user_id uid, COUNT(*) n FROM crm_visits WHERE client_id=? AND status='done' AND starts_at BETWEEN ? AND ? GROUP BY user_id", [$clientId, $t0, $t1]);
    $sales = []; $value = [];
    foreach (db_all("SELECT DISTINCT c.id, c.owner_user_id uid, c.deal_value FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                      WHERE c.client_id=? AND e.kind='stage' AND e.to_val IN ($ph) AND c.stage_id IN ($ph) AND e.created_at BETWEEN ? AND ?",
                    array_merge([$clientId], array_map('strval', $won), $won, [$t0, $t1])) as $r) {
        if ($r['uid'] === null) continue;
        $sales[(int) $r['uid']] = ($sales[(int) $r['uid']] ?? 0) + 1;
        $value[(int) $r['uid']] = ($value[(int) $r['uid']] ?? 0) + (float) $r['deal_value'];
    }
    $out = [];
    foreach (crm_dash_people($clientId, $wholeTeam) as $u) {
        $id = (int) $u['id'];
        $out[$id] = ['id' => $id, 'name' => $u['name'], 'contacted' => $contacted[$id] ?? 0, 'visits' => $visits[$id] ?? 0,
                     'sales' => $sales[$id] ?? 0, 'sales_value' => $value[$id] ?? 0.0];
    }
    return $out;
}

/**
 * The leaderboard: ranked by deals won, then their value, then visits. Progress is each measure
 * against its target, where one is set.
 */
function crm_leaderboard(int $clientId, string $month): array
{
    // The whole team, for everyone: a salesperson sees where they stand among colleagues —
    // their counts only; the leads themselves stay private to their owners.
    $act = crm_actuals($clientId, $month, true);
    $tg  = crm_targets($clientId, $month);
    foreach ($act as $id => &$a) {
        $a['target'] = $tg[$id] ?? null;
        foreach (['contacted', 'visits', 'sales', 'sales_value'] as $k) {
            $goal = $a['target'][$k] ?? null;
            $a['pct'][$k] = $goal !== null && (float) $goal > 0 ? (int) round(100 * $a[$k] / (float) $goal) : null;
        }
    }
    unset($a);
    $rows = array_values($act);
    usort($rows, fn($x, $y) => [$y['sales'], $y['sales_value'], $y['visits'], $y['contacted']] <=> [$x['sales'], $x['sales_value'], $x['visits'], $x['contacted']]);
    return $rows;
}

/* ───────────────────────── reports ───────────────────────── */

/**
 * Leads that arrived, and deals won and lost, per week or month. Won and lost are counted when
 * they happened, so a lead from March won in May counts in May.
 */
function crm_series(int $clientId, array $f, string $bucket = 'week'): array
{
    [$w, $p] = crm_report_where($clientId, $f);
    $kinds = crm_stage_kinds($clientId);
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    $fmt = $bucket === 'month' ? '%Y-%m' : '%x-%v';
    $key = fn(string $d) => $bucket === 'month' ? date('Y-m', strtotime($d)) : date('o-W', strtotime($d));
    $out = [];
    // Every bucket in range, even empty ones, so the gaps show.
    for ($t = strtotime($f['from']); $t <= strtotime($f['to']); $t = strtotime($bucket === 'month' ? '+1 month' : '+1 week', $t)) {
        $k = $key(date('Y-m-d', $t));
        $out[$k] = ['key' => $k, 'label' => $bucket === 'month' ? date('M Y', $t) : date('j M', strtotime('monday this week', $t)), 'leads' => 0, 'won' => 0, 'lost' => 0];
    }
    $k = $key($f['to']); $out[$k] ??= ['key' => $k, 'label' => $bucket === 'month' ? date('M Y', strtotime($f['to'])) : date('j M', strtotime('monday this week', strtotime($f['to']))), 'leads' => 0, 'won' => 0, 'lost' => 0];
    foreach (db_all("SELECT DATE_FORMAT(c.crm_added_at, '$fmt') b, COUNT(*) n FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND {$w} GROUP BY b",
                    array_merge([$from, $to], $p)) as $r) {
        $bk = $bucket === 'month' ? $r['b'] : preg_replace('/^(\d{4})-(\d{1,2})$/', '$1-$2', (string) $r['b']);
        $bk = $bucket === 'month' ? $bk : sprintf('%s-%02d', substr($bk, 0, 4), (int) substr($bk, 5));
        if (isset($out[$bk])) $out[$bk]['leads'] = (int) $r['n'];
    }
    foreach (['won', 'lost'] as $kind) {
        $ids = $kinds[$kind] ?: [0];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        foreach (db_all("SELECT DATE_FORMAT(e.created_at, '$fmt') b, COUNT(DISTINCT c.id) n FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                          WHERE e.kind='stage' AND e.to_val IN ($ph) AND c.stage_id IN ($ph) AND e.created_at BETWEEN ? AND ? AND {$w} GROUP BY b",
                        array_merge(array_map('strval', $ids), $ids, [$from, $to], $p)) as $r) {
            $bk = $bucket === 'month' ? (string) $r['b'] : sprintf('%s-%02d', substr((string) $r['b'], 0, 4), (int) substr((string) $r['b'], 5));
            if (isset($out[$bk])) $out[$bk][$kind] = (int) $r['n'];
        }
    }
    return array_values($out);
}

/** Where the leads that arrived in the period stand now, stage by stage. */
function crm_status_split(int $clientId, array $f): array
{
    [$w, $p] = crm_report_where($clientId, $f);
    $counts = [];
    foreach (db_all("SELECT c.stage_id, COUNT(*) n FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND c.stage_id IS NOT NULL AND {$w} GROUP BY c.stage_id",
                    array_merge([$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'], $p)) as $r) $counts[(int) $r['stage_id']] = (int) $r['n'];
    $rows = [];
    foreach (crm_stages($clientId) as $s) $rows[] = ['label' => $s['name'], 'kind' => $s['kind'], 'n' => $counts[(int) $s['id']] ?? 0];
    return $rows;
}

/**
 * Leads against deals, split by source, project, or salesperson — the "which of these actually
 * sells" question.
 */
function crm_split(int $clientId, array $f, string $dim): array
{
    [$w, $p] = crm_report_where($clientId, $f);
    $kinds = crm_stage_kinds($clientId);
    $won = $kinds['won'] ?: [0]; $lost = $kinds['lost'] ?: [0];
    $phw = implode(',', array_fill(0, count($won), '?')); $phl = implode(',', array_fill(0, count($lost), '?'));
    $col = ['source' => 'c.source', 'project' => 'c.project_id', 'owner' => 'c.owner_user_id'][$dim] ?? 'c.source';
    if ($dim === 'project' && !db_has_column('contacts', 'project_id')) return [];
    $rows = db_all("SELECT $col AS k, COUNT(*) leads, SUM(c.stage_id IN ($phw)) won, SUM(c.stage_id IN ($phl)) lost,
                           SUM(CASE WHEN c.stage_id IN ($phw) THEN COALESCE(c.deal_value,0) ELSE 0 END) won_value
                      FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND {$w} GROUP BY $col ORDER BY leads DESC LIMIT 20",
                   array_merge($won, $lost, $won, [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'], $p));
    $pn = $dim === 'project' ? crm_project_names($clientId) : [];
    foreach ($rows as &$r) {
        $r['label'] = match ($dim) {
            'source'  => crm_source_label($r['k']),
            'project' => $r['k'] !== null ? ($pn[(int) $r['k']] ?? 'A removed project') : 'No project',
            default   => crm_user_name($r['k'] !== null ? (int) $r['k'] : null),
        };
        $r['rate'] = (int) $r['leads'] ? round(100 * $r['won'] / $r['leads'], 1) : null;
    }
    unset($r);
    return $rows;
}

/** What each person logged in the period, by kind: calls, WhatsApps, meetings, visits, emails, comments. */
function crm_activity_matrix(int $clientId, array $f): array
{
    $out = [];
    if (!db_has_column('crm_notes', 'kind')) return $out;
    [$w, $p] = crm_report_where($clientId, $f);
    foreach (db_all("SELECT n.user_id, n.kind, COUNT(*) cnt FROM crm_notes n JOIN contacts c ON c.id=n.contact_id
                      WHERE n.user_id IS NOT NULL AND n.created_at BETWEEN ? AND ? AND {$w} GROUP BY n.user_id, n.kind",
                    array_merge([$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'], $p)) as $r) {
        $out[(int) $r['user_id']][$r['kind']] = (int) $r['cnt'];
    }
    return $out;
}
