<?php
declare(strict_types=1);

/**
 * The reports library: every CRM report behind one set of filters, one layout and one export.
 *
 * Each report returns the same shape —
 *
 *   cols   key => [label, type]           type: text | num | money | pct | dur | days
 *   rows   the table, one array per row
 *   total  an optional last row
 *   chart  optional: ['type' => 'bars'|'pair'|'funnel', 'label' => col, 'series' => [col => name, …]]
 *   drill  optional closure(row, col): list filters for that number, or null
 *   tiles  optional headline numbers above the table: [label, value, type]
 *   note   optional sentence under the title
 *
 * — so the page draws any of them, the export writes any of them, and every number can open the
 * exact leads behind it in the leads list.
 */

require_once __DIR__ . '/crm_reports.php';
require_once __DIR__ . '/crm_dashboard.php';

/** Period presets, as people say them. */
function crm_lib_periods(): array
{
    return ['today' => 'Today', '7d' => '7 days', '1m' => '1 month', '3m' => '3 months', '6m' => '6 months', '1y' => '1 year', 'custom' => 'Custom'];
}

/** The shared filters from a request: a period (preset or dates) and the same slices as the leads list. */
function crm_lib_filters(array $get): array
{
    $period = (string) ($get['period'] ?? '');
    if (!isset(crm_lib_periods()[$period])) $period = (!empty($get['from']) || !empty($get['to'])) ? 'custom' : '1m';
    $to = date('Y-m-d');
    $from = match ($period) {
        'today' => $to,
        '7d'    => date('Y-m-d', strtotime('-6 days')),
        '3m'    => date('Y-m-d', strtotime('-3 months +1 day')),
        '6m'    => date('Y-m-d', strtotime('-6 months +1 day')),
        '1y'    => date('Y-m-d', strtotime('-1 year +1 day')),
        'custom'=> '',
        default => date('Y-m-d', strtotime('-1 month +1 day')),
    };
    if ($period === 'custom') {
        $from = !empty($get['from']) && strtotime((string) $get['from']) ? date('Y-m-d', strtotime((string) $get['from'])) : date('Y-m-d', strtotime('-1 month +1 day'));
        $to   = !empty($get['to']) && strtotime((string) $get['to']) ? date('Y-m-d', strtotime((string) $get['to'])) : $to;
        if ($from > $to) [$from, $to] = [$to, $from];
    }
    $f = ['period' => $period, 'from' => $from, 'to' => $to];
    foreach (['owner', 'team', 'source', 'project', 'dtype', 'platform', 'campaign', 'qual'] as $k) {
        $v = $get[$k] ?? '';
        $f[$k] = is_array($v) ? '' : trim((string) $v);
    }
    // A salesperson's reports are about their own leads (the WHERE applies that anyway).
    if (function_exists('is_sales') && is_sales() && !(function_exists('crm_is_team_leader') && crm_is_team_leader())) $f['owner'] = '';
    return $f;
}

/** The filters worth keeping in links (not the empty ones). */
function crm_lib_active(array $f): array
{
    return array_filter($f, fn($v, $k) => $v !== '' && !($k === 'period' && $v === '1m'), ARRAY_FILTER_USE_BOTH);
}

/** Leads-list filters for "leads added in this report's period, in this slice". */
function crm_lib_drill_base(array $f): array
{
    $d = ['from' => $f['from'], 'to' => $f['to']];
    foreach (['owner', 'team', 'source', 'project', 'dtype', 'platform', 'campaign', 'qual'] as $k) if (($f[$k] ?? '') !== '') $d[$k] = $f[$k];
    return $d;
}

/** Leads-list filters for "won (or lost) in this period, in this slice" — by when it happened. */
function crm_lib_drill_closed(array $f, string $kind): array
{
    $d = crm_lib_drill_base($f);
    unset($d['from'], $d['to']);
    return $d + ['state' => $kind, 'mfrom' => $f['from'], 'mto' => $f['to']];
}

/**
 * Every report: key => [group, title, what it answers, builder]. Later phases add to this list;
 * the library page and the export need nothing else to show a new one.
 */
function crm_lib_catalog(): array
{
    return [
        'overview'   => ['Overview',       'Overview',                    'Leads, contact, visits and sales for the period, week by week.', 'crm_lib_overview'],
        'status'     => ['Sales & status', 'Leads by stage',              'Where the leads that arrived in the period stand now.', 'crm_lib_status'],
        'funnel'     => ['Sales & status', 'How far leads got',           'How many reached each stage, and where they drop off.', 'crm_lib_funnel'],
        'stage_time' => ['Sales & status', 'Where deals wait',            'How long leads spend in each stage before moving on.', 'crm_lib_stage_time'],
        'won_lost'   => ['Sales & status', 'Won and lost by week',        'Deals closed each week, counted when they closed.', 'crm_lib_won_lost'],
        'lost'       => ['Sales & status', 'Why deals were lost',         'Each lost reason, how often, and in which projects.', 'crm_lib_lost'],
        'sources'    => ['Marketing',      'Leads vs sales by source',    'Forms, ads, messages, imports: which one sells.', 'crm_lib_split_source'],
        'campaigns'  => ['Marketing',      'Leads vs sales by campaign',  'Each campaign\'s leads, sales and conversion.', 'crm_lib_split_campaign'],
        'platforms'  => ['Marketing',      'Leads vs sales by platform',  'Facebook, Instagram, WhatsApp ads, website…', 'crm_lib_split_platform'],
        'projects'   => ['Marketing',      'Leads vs sales by project',   'Which project brings leads, and which sells.', 'crm_lib_split_project'],
        'fresh_cold' => ['Marketing',      'Fresh leads vs cold data',    'New leads from campaigns against old data, side by side.', 'crm_lib_split_dtype'],
        'ads'        => ['Marketing',      'Click-to-WhatsApp ads',       'Each ad by the sales it brought, not the clicks.', 'crm_lib_ads'],
        'sales'      => ['Team',           'Sales performance',           'Per salesperson: leads, reply speed, activities, won, conversion.', 'crm_lib_sales'],
        'activity'   => ['Team',           'What each person logged',     'Calls, WhatsApps, meetings, visits and comments per person.', 'crm_lib_activity'],
        'visits'     => ['Team',           'Site visits',                 'Booked, came, didn\'t come, and how many visitors bought.', 'crm_lib_visits'],
        'teams'      => ['Team',           'Leads vs sales by team',      'Each team\'s leads, sales and conversion.', 'crm_lib_split_team'],
    ];
}

/** Run one report. */
function crm_lib_run(int $clientId, string $key, array $f): ?array
{
    $cat = crm_lib_catalog();
    if (!isset($cat[$key])) return null;
    $r = ($cat[$key][3])($clientId, $f);
    return $r + ['cols' => [], 'rows' => [], 'total' => null, 'chart' => null, 'drill' => null, 'tiles' => [], 'note' => ''];
}

/** A cell as text, for the page and the export alike. */
function crm_lib_fmt($v, string $type, int $clientId): string
{
    if ($v === null || $v === '') return $type === 'text' ? '' : '—';
    return match ($type) {
        'num'   => number_format((float) $v),
        'money' => crm_money_fmt($v, $clientId),
        // A headline tile: 55.6M EGP rather than 55,600,000 EGP on two lines.
        'money_short' => (abs((float) $v) >= 1e6 ? rtrim(rtrim(number_format((float) $v / 1e6, 1), '0'), '.') . 'M'
                          : (abs((float) $v) >= 1e4 ? rtrim(rtrim(number_format((float) $v / 1e3, 1), '0'), '.') . 'K' : number_format((float) $v)))
                         . ' ' . crm_currency($clientId),
        'pct'   => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%',
        'dur'   => crm_duration((float) $v),
        'days'  => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . ' d',
        default => (string) $v,
    };
}

/* ───────────────────────── the reports ───────────────────────── */

function crm_lib_overview(int $cid, array $f): array
{
    [$w, $p] = crm_report_where($cid, $f);
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    $kinds = crm_stage_kinds($cid);
    $leads = (int) db_val("SELECT COUNT(*) FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND {$w}", array_merge([$from, $to], $p));
    $contacted = (int) db_val("SELECT COUNT(*) FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND c.first_response_at IS NOT NULL AND {$w}", array_merge([$from, $to], $p));
    $won = 0; $wonValue = 0.0;
    if ($kinds['won']) {
        $ph = implode(',', array_fill(0, count($kinds['won']), '?'));
        $r = db_row("SELECT COUNT(*) n, COALESCE(SUM(x.v), 0) v FROM (SELECT DISTINCT c.id, c.deal_value AS v FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                      WHERE e.kind='stage' AND e.to_val IN ($ph) AND c.stage_id IN ($ph) AND e.created_at BETWEEN ? AND ? AND {$w}) x",
                    array_merge(array_map('strval', $kinds['won']), $kinds['won'], [$from, $to], $p));
        $won = (int) ($r['n'] ?? 0); $wonValue = (float) ($r['v'] ?? 0);
    }
    $came = 0;
    try {
        [$sc, $ssp] = crm_scope('c');
        $came = (int) db_val("SELECT COUNT(*) FROM crm_visits v JOIN contacts c ON c.id=v.contact_id WHERE v.client_id=? AND v.status='done' AND v.starts_at BETWEEN ? AND ?{$sc}",
                             array_merge([$cid, $from, $to], $ssp));
    } catch (Throwable $e) {}
    $days = (strtotime($f['to']) - strtotime($f['from'])) / 86400;
    $series = crm_series($cid, $f, $days > 120 ? 'month' : 'week');
    return [
        'tiles' => [['Leads added', $leads, 'num'], ['Contacted', $leads ? round(100 * $contacted / $leads) : null, 'pct'],
                    ['Came to a visit', $came, 'num'], ['Won', $won, 'num'], ['Won value', $wonValue ?: null, 'money_short'],
                    ['Won ÷ leads', $leads ? round(100 * $won / $leads, 1) : null, 'pct']],
        'cols'  => ['label' => [$days > 120 ? 'Month' : 'Week of', 'text'], 'leads' => ['Leads', 'num'], 'won' => ['Won', 'num'], 'lost' => ['Lost', 'num']],
        'rows'  => $series,
        'total' => ['label' => 'Total', 'leads' => array_sum(array_column($series, 'leads')), 'won' => array_sum(array_column($series, 'won')), 'lost' => array_sum(array_column($series, 'lost'))],
        'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['leads' => 'Leads', 'won' => 'Won']],
        'drill' => fn($r, $c) => $c === 'leads' && $r['label'] === 'Total' ? crm_lib_drill_base($f) : ($c === 'won' && $r['label'] === 'Total' ? crm_lib_drill_closed($f, 'won') : null),
        'empty' => $leads === 0 && $won === 0,
    ];
}

function crm_lib_status(int $cid, array $f): array
{
    $rows = crm_status_split($cid, $f);
    $ids = array_column(crm_stages($cid), 'id', 'name');
    $total = array_sum(array_column($rows, 'n'));
    foreach ($rows as &$r) { $r['share'] = $total ? round(100 * $r['n'] / $total, 1) : null; $r['stage_id'] = $ids[$r['label']] ?? null; }
    unset($r);
    return ['cols' => ['label' => ['Stage', 'text'], 'n' => ['Leads', 'num'], 'share' => ['Share', 'pct']], 'rows' => $rows,
            'total' => ['label' => 'Total', 'n' => $total, 'share' => $total ? 100 : null],
            'chart' => ['type' => 'bars', 'label' => 'label', 'series' => ['n' => 'Leads']],
            'drill' => fn($r, $c) => $c === 'n' ? crm_lib_drill_base($f) + (!empty($r['stage_id']) ? ['stage' => $r['stage_id']] : []) : null,
            'empty' => $total === 0, 'note' => 'Leads that arrived in the period, by the stage they are in today.'];
}

function crm_lib_funnel(int $cid, array $f): array
{
    $fn = crm_report_funnel($cid, $f);
    $rows = []; $prev = $fn['total'];
    foreach ($fn['stages'] as $s) {
        if ($s['kind'] === 'lost') continue;
        $rows[] = ['label' => $s['name'], 'reached' => $s['reached'], 'of_all' => $fn['total'] ? round(100 * $s['reached'] / $fn['total'], 1) : null,
                   'kept' => $prev ? round(100 * $s['reached'] / $prev, 1) : null];
        $prev = $s['reached'];
    }
    return ['cols' => ['label' => ['Stage', 'text'], 'reached' => ['Reached it', 'num'], 'of_all' => ['Of all leads', 'pct'], 'kept' => ['From the step before', 'pct']],
            'rows' => $rows, 'chart' => ['type' => 'funnel', 'label' => 'label', 'series' => ['reached' => 'Reached']],
            'empty' => $fn['total'] === 0, 'note' => number_format($fn['total']) . ' leads arrived in the period. A lead counts in every stage it passed through.'];
}

function crm_lib_stage_time(int $cid, array $f): array
{
    $rows = array_map(fn($r) => ['label' => $r['name'], 'median' => $r['median'] !== null ? $r['median'] / 86400 : null, 'n' => $r['n']], crm_report_time_in_stage($cid, $f));
    $ids = array_column(crm_stages($cid), 'id', 'name');
    return ['cols' => ['label' => ['Stage', 'text'], 'median' => ['Typical time there', 'days'], 'n' => ['Leads that moved on', 'num']],
            'rows' => $rows, 'chart' => ['type' => 'bars', 'label' => 'label', 'series' => ['median' => 'Days']],
            'drill' => fn($r, $c) => isset($ids[$r['label']]) ? ['stage' => $ids[$r['label']], 'idle' => '7'] : null,
            'empty' => !array_sum(array_column($rows, 'n')), 'note' => 'The middle value: half the leads moved on faster than this, half slower. Click a stage to see leads quiet in it for 7+ days.'];
}

function crm_lib_won_lost(int $cid, array $f): array
{
    $rows = array_map(fn($r) => ['label' => $r['week'], 'won' => $r['won'], 'lost' => $r['lost']], crm_report_weekly($cid, $f));
    return ['cols' => ['label' => ['Week of', 'text'], 'won' => ['Won', 'num'], 'lost' => ['Lost', 'num']], 'rows' => $rows,
            'total' => ['label' => 'Total', 'won' => array_sum(array_column($rows, 'won')), 'lost' => array_sum(array_column($rows, 'lost'))],
            'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['won' => 'Won', 'lost' => 'Lost']],
            'drill' => fn($r, $c) => $r['label'] === 'Total' && in_array($c, ['won', 'lost'], true) ? crm_lib_drill_closed($f, $c) : null,
            'empty' => !$rows];
}

function crm_lib_lost(int $cid, array $f): array
{
    $rows = [];
    foreach (crm_report_lost($cid, $f) as $r) {
        $top = array_slice($r['projects'], 0, 3, true);
        $rows[] = ['label' => $r['reason'], 'n' => $r['n'], 'share' => $r['share'],
                   'where' => implode(', ', array_map(fn($k, $v) => "$k ($v)", array_keys($top), $top))];
    }
    return ['cols' => ['label' => ['Reason', 'text'], 'n' => ['Leads', 'num'], 'share' => ['Share', 'pct'], 'where' => ['Mostly in', 'text']],
            'rows' => $rows, 'chart' => ['type' => 'bars', 'label' => 'label', 'series' => ['n' => 'Leads']],
            'drill' => fn($r, $c) => $c === 'n' ? crm_lib_drill_closed($f, 'lost') + ['sub' => $r['label']] : null,
            'empty' => !$rows, 'note' => 'Counted when the lead was lost.'];
}

/** Leads vs sales by one slice — the shape behind sources, campaigns, platforms, projects, teams. */
function crm_lib_split(int $cid, array $f, string $dim, string $title): array
{
    $rows = crm_split($cid, $f, $dim);
    $drillKey = ['source' => 'source', 'project' => 'project', 'owner' => 'owner', 'campaign' => 'campaign', 'platform' => 'platform',
                 'dtype' => 'dtype', 'team' => 'team', 'qual' => 'qual'][$dim] ?? null;
    $out = array_map(fn($r) => ['label' => $r['label'], 'k' => $r['k'], 'leads' => (int) $r['leads'], 'won' => (int) $r['won'],
                                'lost' => (int) $r['lost'], 'won_value' => (float) $r['won_value'], 'rate' => $r['rate']], $rows);
    $t = ['label' => 'Total', 'leads' => array_sum(array_column($out, 'leads')), 'won' => array_sum(array_column($out, 'won')),
          'lost' => array_sum(array_column($out, 'lost')), 'won_value' => array_sum(array_column($out, 'won_value'))];
    $t['rate'] = $t['leads'] ? round(100 * $t['won'] / $t['leads'], 1) : null;
    $slice = function (array $r) use ($drillKey, $dim) {
        if ($r['label'] === 'Total' || !$drillKey) return [];
        if ($r['k'] === null) return $dim === 'project' ? ['project' => 'none'] : ($dim === 'campaign' ? ['campaign' => '__none'] : []);
        return [$drillKey => (string) $r['k']];
    };
    return ['cols' => ['label' => [$title, 'text'], 'leads' => ['Leads', 'num'], 'won' => ['Won', 'num'], 'lost' => ['Lost', 'num'],
                       'rate' => ['Won ÷ leads', 'pct'], 'won_value' => ['Won value', 'money']],
            'rows' => $out, 'total' => $t, 'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['leads' => 'Leads', 'won' => 'Won']],
            'drill' => function ($r, $c) use ($f, $slice) {
                if ($c === 'leads') return crm_lib_drill_base($f) + $slice($r);
                if ($c === 'won' || $c === 'lost') { $d = crm_lib_drill_base($f) + $slice($r); return $d + ['state' => $c]; }
                return null;
            },
            'empty' => $t['leads'] === 0, 'note' => 'Leads that arrived in the period, and how many of them are won or lost now.'];
}
function crm_lib_split_source(int $cid, array $f): array   { return crm_lib_split($cid, $f, 'source', 'Source'); }
function crm_lib_split_campaign(int $cid, array $f): array { return crm_lib_split($cid, $f, 'campaign', 'Campaign'); }
function crm_lib_split_platform(int $cid, array $f): array { return crm_lib_split($cid, $f, 'platform', 'Platform'); }
function crm_lib_split_project(int $cid, array $f): array  { return crm_lib_split($cid, $f, 'project', 'Project'); }
function crm_lib_split_dtype(int $cid, array $f): array    { return crm_lib_split($cid, $f, 'dtype', 'Data'); }
function crm_lib_split_team(int $cid, array $f): array     { return crm_lib_split($cid, $f, 'team', 'Team'); }

function crm_lib_ads(int $cid, array $f): array
{
    $rows = array_map(fn($r) => ['label' => (string) ($r['headline'] ?: 'Ad ' . $r['source_id']), 'leads' => (int) $r['leads'], 'won' => (int) $r['won'],
                                 'rate' => $r['leads'] ? round(100 * $r['won'] / $r['leads'], 1) : null, 'won_value' => (float) $r['won_value']], crm_report_ads($cid, $f));
    return ['cols' => ['label' => ['Ad', 'text'], 'leads' => ['Leads', 'num'], 'won' => ['Won', 'num'], 'rate' => ['Won ÷ leads', 'pct'], 'won_value' => ['Won value', 'money']],
            'rows' => $rows, 'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['leads' => 'Leads', 'won' => 'Won']],
            'empty' => !$rows, 'note' => 'Leads who first wrote from a click-to-WhatsApp ad.'];
}

function crm_lib_sales(int $cid, array $f): array
{
    $rows = array_map(fn($r) => ['label' => $r['name'], 'user_id' => $r['user_id'], 'assigned' => $r['assigned'], 'contacted' => $r['contacted'],
        'median_response' => $r['median_response'], 'activities' => $r['activities'], 'worked' => $r['worked'], 'won' => $r['won'],
        'won_value' => $r['won_value'], 'conversion' => $r['conversion'], 'overdue' => $r['overdue']], crm_report_sales($cid, $f));
    return ['cols' => ['label' => ['Salesperson', 'text'], 'assigned' => ['Leads given', 'num'], 'contacted' => ['Contacted', 'num'],
                       'median_response' => ['Typical reply time', 'dur'], 'activities' => ['Activities', 'num'], 'worked' => ['Moved on', 'num'],
                       'won' => ['Won', 'num'], 'won_value' => ['Won value', 'money'], 'conversion' => ['Won ÷ given', 'pct'], 'overdue' => ['Overdue now', 'num']],
            'rows' => $rows, 'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['assigned' => 'Leads given', 'won' => 'Won']],
            'drill' => fn($r, $c) => match ($c) {
                'won'     => crm_lib_drill_closed($f, 'won') + ['owner' => (string) $r['user_id']],
                'overdue' => ['owner' => (string) $r['user_id'], 'due' => 'overdue'],
                'assigned'=> ['owner' => (string) $r['user_id'], 'from' => $f['from'], 'to' => $f['to']],
                default   => null,
            },
            'empty' => !$rows, 'note' => 'Reply time is from being given the lead to the first call or message by a person — bots do not count.'];
}

function crm_lib_activity(int $cid, array $f): array
{
    $m = crm_activity_matrix($cid, $f);
    $kinds = crm_activity_kinds();
    $rows = [];
    foreach ($m as $uid => $k) {
        $row = ['label' => crm_user_name($uid)];
        foreach ($kinds as $kk => $_) $row[$kk] = (int) ($k[$kk] ?? 0);
        $row['all'] = array_sum(array_intersect_key($row, $kinds));
        $rows[] = $row;
    }
    usort($rows, fn($a, $b) => $b['all'] <=> $a['all']);
    $cols = ['label' => ['Person', 'text']];
    foreach ($kinds as $kk => $l) $cols[$kk] = [$l === 'Comment' ? 'Comments' : $l . 's', 'num'];
    $cols['all'] = ['All', 'num'];
    return ['cols' => $cols, 'rows' => $rows, 'chart' => ['type' => 'bars', 'label' => 'label', 'series' => ['call' => 'Calls']], 'empty' => !$rows];
}

function crm_lib_visits(int $cid, array $f): array
{
    $rows = array_map(fn($r) => ['label' => $r['label'], 'booked' => (int) $r['booked'], 'came' => (int) $r['came'], 'no_show' => (int) $r['no_show'],
                                 'show_rate' => $r['show_rate'], 'bought' => (int) $r['bought'], 'sale_rate' => $r['sale_rate']],
                      crm_report_visits($cid, $f['from'], $f['to'], 'user'));
    return ['cols' => ['label' => ['Salesperson', 'text'], 'booked' => ['Booked', 'num'], 'came' => ['Came', 'num'], 'no_show' => ['Didn\'t come', 'num'],
                       'show_rate' => ['Show rate', 'pct'], 'bought' => ['Visitors who bought', 'num'], 'sale_rate' => ['Visit → sale', 'pct']],
            'rows' => $rows, 'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['came' => 'Came', 'bought' => 'Bought']],
            'empty' => !$rows, 'note' => 'Visits planned in the period (cancelled ones left out).'];
}

/* ───────────────────────── favourites ───────────────────────── */

function crm_lib_favs(?array $user): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) ($user['report_favs'] ?? '')))));
}
