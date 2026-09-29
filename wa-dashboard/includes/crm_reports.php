<?php
declare(strict_types=1);

/**
 * CRM reports: how each salesperson is doing, and how the leads themselves are doing.
 *
 * Built from crm_events rather than from each lead's current state. "Who converts best" and "how
 * long do leads sit in Viewing" are questions about what HAPPENED — a lead's current stage says
 * where it is, not how long it took or who moved it there.
 *
 * Every function takes the same filter: from/to dates, and optionally an owner and a source. The
 * sales scope (Sales see only their own leads) is applied here, not by the page, so an export can
 * never leak what the screen would hide.
 */

require_once __DIR__ . '/crm.php';

function crm_median(array $xs): ?float
{
    $xs = array_values(array_filter($xs, fn($x) => $x !== null));
    if (!$xs) return null;
    sort($xs);
    $n = count($xs); $m = intdiv($n, 2);
    return $n % 2 ? (float) $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2;
}

/** "2h 15m", "3d" — a duration a sales manager reads at a glance. */
function crm_duration(?float $seconds): string
{
    if ($seconds === null) return '—';
    $s = (int) round($seconds);
    if ($s < 60)    return '<1m';                        // not "1m": that would claim a minute that never passed
    if ($s < 3600)  return (int) round($s / 60) . 'm';
    if ($s < 86400) return intdiv($s, 3600) . 'h ' . (int) round(($s % 3600) / 60) . 'm';
    return round($s / 86400, 1) . 'd';
}

/** WHERE fragment for leads (alias c) matching the filter and the viewer's scope. */
function crm_report_where(int $clientId, array $f): array
{
    $sql = "c.client_id = ? AND c.crm_added_at IS NOT NULL";
    $p = [$clientId];
    [$scope, $sp] = crm_scope('c');
    $sql .= $scope; $p = array_merge($p, $sp);
    if (!empty($f['source'])) { $sql .= " AND c.source = ?"; $p[] = (string) $f['source']; }
    if (!empty($f['project']) && db_has_column('contacts', 'project_id')) { $sql .= " AND c.project_id = ?"; $p[] = (int) $f['project']; }
    if (($f['owner'] ?? '') === 'none') $sql .= " AND c.owner_user_id IS NULL";
    elseif (!empty($f['owner'])) { $sql .= " AND c.owner_user_id = ?"; $p[] = (int) $f['owner']; }
    // The rest of the shared report filters (crm_library.php): team, fresh/cold, platform, campaign, qualified.
    if (!empty($f['team']) && function_exists('crm_team_member_ids')) {
        $ids = crm_team_member_ids((int) $f['team']);
        $sql .= $ids ? " AND c.owner_user_id IN (" . implode(',', array_map('intval', $ids)) . ")" : " AND 1=0";
    }
    foreach (['dtype' => 'data_type', 'platform' => 'platform', 'qual' => 'qualification'] as $k => $col) {
        if (!empty($f[$k]) && db_has_column('contacts', $col)) { $sql .= " AND c.$col = ?"; $p[] = (string) $f[$k]; }
    }
    if (!empty($f['campaign']) && db_has_column('contacts', 'campaign')) { $sql .= " AND c.campaign LIKE ?"; $p[] = '%' . $f['campaign'] . '%'; }
    return [$sql, $p];
}

/** Stage ids by kind, e.g. ['won' => [5], 'lost' => [6], 'open' => [1,2,3,4]]. */
function crm_stage_kinds(int $clientId): array
{
    $k = ['open' => [], 'won' => [], 'lost' => []];
    foreach (crm_stages($clientId) as $s) $k[$s['kind']][] = (int) $s['id'];
    return $k;
}

/**
 * One row per salesperson.
 *
 * "Assigned" counts leads handed to them in the period (a reassignment away still counts — they
 * were given the chance). "Won" counts leads they own now that moved into a won stage in the
 * period. Conversion is won ÷ assigned. Response time is the median from assignment to the first
 * time a person (not a bot) replied.
 */
function crm_report_sales(int $clientId, array $f): array
{
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    $kinds = crm_stage_kinds($clientId);
    [$w, $p] = crm_report_where($clientId, $f);

    $rows = [];
    $blank = fn($uid, $name) => ['user_id' => $uid, 'name' => $name, 'assigned' => 0, 'contacted' => 0,
                                 'worked' => 0, 'activities' => 0, 'won' => 0, 'won_value' => 0.0, 'overdue' => 0, 'resp' => []];

    // Handed out in the period.
    $given = db_all("SELECT e.to_val AS uid, e.contact_id, e.created_at, c.first_response_at
                       FROM crm_events e JOIN contacts c ON c.id = e.contact_id
                      WHERE e.kind='assigned' AND e.to_val IS NOT NULL AND e.created_at BETWEEN ? AND ? AND {$w}",
                    array_merge([$from, $to], $p));
    $seen = [];
    foreach ($given as $g) {
        $uid = (int) $g['uid'];
        $key = $uid . ':' . $g['contact_id'];
        if (isset($seen[$key])) continue;            // the same lead given to them twice counts once
        $seen[$key] = true;
        $rows[$uid] ??= $blank($uid, crm_user_name($uid));
        $rows[$uid]['assigned']++;
        if ($g['first_response_at'] && strtotime((string) $g['first_response_at']) >= strtotime((string) $g['created_at'])) {
            $rows[$uid]['contacted']++;
            $rows[$uid]['resp'][] = strtotime((string) $g['first_response_at']) - strtotime((string) $g['created_at']);
        }
    }

    // Worked: leads of theirs that moved stage at all in the period.
    foreach (db_all("SELECT c.owner_user_id AS uid, COUNT(DISTINCT e.contact_id) n
                       FROM crm_events e JOIN contacts c ON c.id = e.contact_id
                      WHERE e.kind='stage' AND e.created_at BETWEEN ? AND ? AND c.owner_user_id IS NOT NULL AND {$w}
                      GROUP BY c.owner_user_id", array_merge([$from, $to], $p)) as $r) {
        $uid = (int) $r['uid'];
        $rows[$uid] ??= $blank($uid, crm_user_name($uid));
        $rows[$uid]['worked'] = (int) $r['n'];
    }

    // Calls, meetings, visits, WhatsApps and emails they logged — the work behind the numbers.
    if (db_has_column('crm_notes', 'kind')) {
        foreach (db_all("SELECT n.user_id AS uid, COUNT(*) n FROM crm_notes n JOIN contacts c ON c.id = n.contact_id
                          WHERE n.kind <> 'note' AND n.user_id IS NOT NULL AND n.created_at BETWEEN ? AND ? AND {$w}
                          GROUP BY n.user_id", array_merge([$from, $to], $p)) as $r) {
            $uid = (int) $r['uid'];
            $rows[$uid] ??= $blank($uid, crm_user_name($uid));
            $rows[$uid]['activities'] = (int) $r['n'];
        }
    }

    // Won in the period.
    if ($kinds['won']) {
        $ph = implode(',', array_fill(0, count($kinds['won']), '?'));
        // DISTINCT, not GROUP BY: one row per lead however many times it was moved into Won,
        // without relying on a server mode that tolerates grouping by id alone.
        foreach (db_all("SELECT DISTINCT c.id, c.owner_user_id AS uid, c.deal_value
                           FROM crm_events e JOIN contacts c ON c.id = e.contact_id
                          WHERE e.kind='stage' AND e.to_val IN ($ph) AND e.created_at BETWEEN ? AND ?
                            AND c.stage_id IN ($ph) AND c.owner_user_id IS NOT NULL AND {$w}",
                        array_merge($kinds['won'], [$from, $to], $kinds['won'], $p)) as $r) {
            $uid = (int) $r['uid'];
            $rows[$uid] ??= $blank($uid, crm_user_name($uid));
            $rows[$uid]['won']++;
            $rows[$uid]['won_value'] += (float) $r['deal_value'];
        }
    }

    // Overdue right now — a live number, not a period one.
    if ($kinds['open']) {
        $ph = implode(',', array_fill(0, count($kinds['open']), '?'));
        foreach (db_all("SELECT c.owner_user_id AS uid, COUNT(*) n FROM contacts c
                          WHERE c.stage_id IN ($ph) AND c.next_followup_at < NOW() AND c.owner_user_id IS NOT NULL AND {$w}
                          GROUP BY c.owner_user_id", array_merge($kinds['open'], $p)) as $r) {
            $uid = (int) $r['uid'];
            $rows[$uid] ??= $blank($uid, crm_user_name($uid));
            $rows[$uid]['overdue'] = (int) $r['n'];
        }
    }

    foreach ($rows as &$r) {
        $r['median_response'] = crm_median($r['resp']);
        $r['conversion'] = $r['assigned'] ? round(100 * $r['won'] / $r['assigned'], 1) : null;
        unset($r['resp']);
    }
    unset($r);
    usort($rows, fn($a, $b) => [$b['won'], $b['assigned']] <=> [$a['won'], $a['assigned']]);
    return $rows;
}

/**
 * Leads added in the period, and how far they got.
 *
 * "Reached" means the lead was in that stage at any point, so a lead that went New → Viewing →
 * Won counts in all three. That is what makes it a funnel: each step shows how many got at least
 * that far, and the drop between steps is where leads are being lost.
 */
function crm_report_funnel(int $clientId, array $f): array
{
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    [$w, $p] = crm_report_where($clientId, $f);
    $leads = array_map('intval', array_column(db_all(
        "SELECT c.id FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND {$w}", array_merge([$from, $to], $p)), 'id'));
    $stages = crm_stages($clientId);
    $out = ['total' => count($leads), 'stages' => []];
    if (!$leads) {
        foreach ($stages as $s) $out['stages'][] = ['id' => (int) $s['id'], 'name' => $s['name'], 'kind' => $s['kind'], 'reached' => 0];
        return $out;
    }
    $ph = implode(',', array_fill(0, count($leads), '?'));
    $reached = [];
    foreach (db_all("SELECT to_val, COUNT(DISTINCT contact_id) n FROM crm_events
                      WHERE kind IN ('added','stage') AND contact_id IN ($ph) GROUP BY to_val", $leads) as $r) {
        $reached[(int) $r['to_val']] = (int) $r['n'];
    }
    foreach ($stages as $s) {
        $out['stages'][] = ['id' => (int) $s['id'], 'name' => $s['name'], 'kind' => $s['kind'],
                            'reached' => $reached[(int) $s['id']] ?? 0];
    }
    return $out;
}

/** Leads by where they came from, and how many of each were won. */
function crm_report_sources(int $clientId, array $f): array
{
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    $kinds = crm_stage_kinds($clientId);
    [$w, $p] = crm_report_where($clientId, $f);
    $won = $kinds['won'] ?: [0];
    $ph = implode(',', array_fill(0, count($won), '?'));
    $rows = db_all("SELECT COALESCE(c.source,'') AS source, COUNT(*) AS leads,
                           SUM(c.stage_id IN ($ph)) AS won,
                           SUM(CASE WHEN c.stage_id IN ($ph) THEN COALESCE(c.deal_value,0) ELSE 0 END) AS won_value
                      FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND {$w}
                     GROUP BY c.source ORDER BY leads DESC",
                   array_merge($won, $won, [$from, $to], $p));
    foreach ($rows as &$r) {
        $r['label'] = crm_source_label($r['source']);
        $r['rate']  = $r['leads'] ? round(100 * $r['won'] / $r['leads'], 1) : null;
    }
    unset($r);
    return $rows;
}

/**
 * Click-to-WhatsApp ads, measured by what they produced in SALES, not clicks.
 *
 * The ad catalogue from the CTWA work (wa_ads) already knows every ad and which contact it
 * brought; joining the pipeline turns "this ad got 40 messages" into "this ad got 3 sales".
 */
function crm_report_ads(int $clientId, array $f): array
{
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    $kinds = crm_stage_kinds($clientId);
    [$w, $p] = crm_report_where($clientId, $f);
    $won = $kinds['won'] ?: [0];
    $ph = implode(',', array_fill(0, count($won), '?'));
    try {
        return db_all("SELECT a.source_id, a.headline, COUNT(c.id) AS leads, SUM(c.stage_id IN ($ph)) AS won,
                              SUM(CASE WHEN c.stage_id IN ($ph) THEN COALESCE(c.deal_value,0) ELSE 0 END) AS won_value
                         FROM contacts c JOIN wa_ads a ON a.client_id = c.client_id AND a.source_id = c.ad_source_id
                        WHERE c.crm_added_at BETWEEN ? AND ? AND {$w}
                        GROUP BY a.id ORDER BY won DESC, leads DESC LIMIT 50",
                      array_merge($won, $won, [$from, $to], $p));
    } catch (Throwable $e) {
        return [];                                      // no ad catalogue on this install yet
    }
}

/**
 * Median time spent in each stage, for leads that moved through it.
 *
 * Measured from entering a stage to the next move out of it. A lead still sitting in a stage has
 * no exit yet, so it is left out rather than counted as "zero days" — that would make the stage
 * where deals stall look like the fastest one.
 */
function crm_report_time_in_stage(int $clientId, array $f): array
{
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    [$w, $p] = crm_report_where($clientId, $f);
    $events = db_all("SELECT e.contact_id, e.to_val, e.created_at FROM crm_events e JOIN contacts c ON c.id = e.contact_id
                       WHERE e.kind IN ('added','stage') AND c.crm_added_at BETWEEN ? AND ? AND {$w}
                       ORDER BY e.contact_id, e.id", array_merge([$from, $to], $p));
    $spans = [];
    $prev = null;
    foreach ($events as $e) {
        if ($prev && (int) $prev['contact_id'] === (int) $e['contact_id']) {
            $spans[(int) $prev['to_val']][] = strtotime((string) $e['created_at']) - strtotime((string) $prev['created_at']);
        }
        $prev = $e;
    }
    $out = [];
    foreach (crm_stages($clientId) as $s) {
        if ($s['kind'] !== 'open') continue;             // nobody "waits" in Won or Lost
        $xs = $spans[(int) $s['id']] ?? [];
        $out[] = ['name' => $s['name'], 'median' => crm_median($xs), 'n' => count($xs)];
    }
    return $out;
}

/** Leads won and lost per week in the period. */
function crm_report_weekly(int $clientId, array $f): array
{
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    $kinds = crm_stage_kinds($clientId);
    [$w, $p] = crm_report_where($clientId, $f);
    $weeks = [];
    foreach (['won', 'lost'] as $k) {
        if (!$kinds[$k]) continue;
        $ph = implode(',', array_fill(0, count($kinds[$k]), '?'));
        // Keyed by the Monday that starts each week, which is also the label.
        foreach (db_all("SELECT DATE_SUB(DATE(e.created_at), INTERVAL WEEKDAY(e.created_at) DAY) AS monday,
                                COUNT(DISTINCT e.contact_id) n
                           FROM crm_events e JOIN contacts c ON c.id = e.contact_id
                          WHERE e.kind='stage' AND e.to_val IN ($ph) AND e.created_at BETWEEN ? AND ? AND {$w}
                          GROUP BY monday", array_merge($kinds[$k], [$from, $to], $p)) as $r) {
            $weeks[$r['monday']] ??= ['week' => date('j M', strtotime((string) $r['monday'])), 'won' => 0, 'lost' => 0];
            $weeks[$r['monday']][$k] = (int) $r['n'];
        }
    }
    ksort($weeks);
    return array_values($weeks);
}


/**
 * Each project: how many leads it brought in the period, how many were won and lost, for how much.
 * Leads not filed under a project are one row of their own, so the totals still add up.
 */
function crm_report_projects(int $clientId, array $f): array
{
    if (!db_has_column('contacts', 'project_id')) return [];
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    $kinds = crm_stage_kinds($clientId);
    [$w, $p] = crm_report_where($clientId, $f);
    $won = $kinds['won'] ?: [0]; $lost = $kinds['lost'] ?: [0];
    $phw = implode(',', array_fill(0, count($won), '?')); $phl = implode(',', array_fill(0, count($lost), '?'));
    $rows = db_all("SELECT c.project_id, COUNT(*) AS leads, SUM(c.stage_id IN ($phw)) AS won, SUM(c.stage_id IN ($phl)) AS lost,
                           SUM(CASE WHEN c.stage_id IN ($phw) THEN COALESCE(c.deal_value,0) ELSE 0 END) AS won_value,
                           SUM(c.score >= 70 AND c.stage_id NOT IN ($phw) AND c.stage_id NOT IN ($phl)) AS hot
                      FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND {$w}
                     GROUP BY c.project_id ORDER BY leads DESC",
                   array_merge($won, $lost, $won, $won, $lost, [$from, $to], $p));
    $names = crm_project_names($clientId);
    foreach ($rows as &$r) {
        $r['label'] = $r['project_id'] !== null ? ($names[(int) $r['project_id']] ?? 'A removed project') : 'No project';
        $r['rate']  = $r['leads'] ? round(100 * $r['won'] / $r['leads'], 1) : null;
    }
    unset($r);
    return $rows;
}

/**
 * Why deals were lost in the period: each reason, how often, and in which projects most.
 * Counted from the moment of losing (the history), so a lead lost this month counts this month
 * even if it arrived last quarter.
 */
function crm_report_lost(int $clientId, array $f): array
{
    if (!db_has_column('contacts', 'lost_reason')) return [];
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    [$w, $p] = crm_report_where($clientId, $f);
    $rows = db_all("SELECT COALESCE(NULLIF(c.lost_reason,''), 'No reason given') AS reason, c.project_id, COUNT(DISTINCT c.id) AS n
                      FROM crm_events e JOIN contacts c ON c.id = e.contact_id JOIN crm_stages s ON s.id = c.stage_id
                     WHERE e.kind='stage' AND s.kind='lost' AND e.to_val = CAST(c.stage_id AS CHAR)
                       AND e.created_at BETWEEN ? AND ? AND {$w}
                     GROUP BY reason, c.project_id", array_merge([$from, $to], $p));
    $names = crm_project_names($clientId);
    $by = [];
    foreach ($rows as $r) {
        $k = (string) $r['reason'];
        $by[$k] ??= ['reason' => $k, 'n' => 0, 'projects' => []];
        $by[$k]['n'] += (int) $r['n'];
        $pl = $r['project_id'] !== null ? ($names[(int) $r['project_id']] ?? 'A removed project') : 'No project';
        $by[$k]['projects'][$pl] = ($by[$k]['projects'][$pl] ?? 0) + (int) $r['n'];
    }
    $total = array_sum(array_column($by, 'n'));
    foreach ($by as &$r) { arsort($r['projects']); $r['share'] = $total ? round(100 * $r['n'] / $total) : 0; }
    unset($r);
    usort($by, fn($a, $b) => $b['n'] <=> $a['n']);
    return $by;
}
