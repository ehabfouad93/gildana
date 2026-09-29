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
        'daily'      => ['Daily & delays', 'Daily report',                'Per salesperson, for a day: leads given, contacted, calls, visits, won.', 'crm_lib_daily'],
        'delay'      => ['Daily & delays', 'Delays',                      'Right now: leads waiting too long, overdue follow-ups, visits nobody closed.', 'crm_lib_delay'],
        'first_reply'=> ['Daily & delays', 'First response time',         'How fast each salesperson first answered new leads.', 'crm_lib_first_reply'],
        'rotation'   => ['Daily & delays', 'Rotation',                    'Leads moved between people, and whether moving them helped.', 'crm_lib_rotation'],
        'churn'      => ['Daily & delays', 'Good leads going cold',       'Warm and hot leads with nothing done for days — reassign or call today.', 'crm_lib_churn'],
        'roi'        => ['Marketing',      'Return on ad spend',          'Each campaign: spend, cost per lead, per visit and per sale, and what it earned back.', 'crm_lib_roi'],
        'roi_ads'    => ['Marketing',      'Return on ad spend, by ad',   'The same, ad by ad — which creative actually sells.', 'crm_lib_roi_ads'],
        'quality'    => ['Marketing',      'Lead quality by source',      'Wrong numbers, not qualified, no answer, repeats — per source.', 'crm_lib_quality_source'],
        'quality_campaign' => ['Marketing', 'Lead quality by campaign',   'The same, per campaign: which campaigns bring real buyers.', 'crm_lib_quality_campaign'],
        'platform_status' => ['Marketing', 'Platform × stage × sales',   'Facebook, Instagram, WhatsApp… and where their leads stand now.', 'crm_lib_platform_status'],
        'project_status'  => ['Sales & status', 'Project × stage',       'Each project\'s leads, stage by stage.', 'crm_lib_project_status'],
        'campaign_sales'  => ['Marketing', 'Campaign × salesperson',     'Who sells each campaign\'s leads best.', 'crm_lib_campaign_sales'],
        'substatus'  => ['Sales & status', 'Stage × sub-status',          'Each stage broken down by what actually happened.', 'crm_lib_substatus'],
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
        'x'     => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '×',
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
        if ($r['k'] === null) return $dim === 'project' ? ['project' => 'none'] : ($dim === 'campaign' ? ['ceq' => '__none'] : []);
        return [$dim === 'campaign' ? 'ceq' : $drillKey => (string) $r['k']];
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

/* ───────────────────────── daily & delays ───────────────────────── */

/** People who own or handled leads in scope, id => name — so a report lists the team, zeros included. */
function crm_lib_people(int $cid, array $f): array
{
    $ids = function_exists('crm_visible_owner_ids') ? crm_visible_owner_ids() : null;
    $rows = db_all("SELECT id, COALESCE(NULLIF(name,''), email) name FROM users WHERE client_id=? AND role='client' AND status='active'
                     AND client_role IN ('sales','admin') ORDER BY client_role='sales' DESC, name", [$cid]);
    $out = [];
    foreach ($rows as $u) {
        if ($ids !== null && !in_array((int) $u['id'], $ids, true)) continue;
        if (!empty($f['owner']) && (int) $f['owner'] !== (int) $u['id']) continue;
        if (!empty($f['team']) && !in_array((int) $u['id'], crm_team_member_ids((int) $f['team']), true)) continue;
        $out[(int) $u['id']] = (string) $u['name'];
    }
    return $out;
}

/**
 * The day (or days) per salesperson — the report a manager reads every evening. Counts are of
 * what HAPPENED in the period: leads given, first contacts, calls and their outcomes, visits
 * people came to, deals closed — plus what is overdue right now.
 */
function crm_lib_daily(int $cid, array $f): array
{
    [$w, $p] = crm_report_where($cid, $f);
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    $people = crm_lib_people($cid, $f);
    $rows = [];
    foreach ($people as $uid => $name) $rows[$uid] = ['label' => $name, 'uid' => $uid, 'given' => 0, 'contacted' => 0, 'calls' => 0, 'answered' => 0,
        'no_answer' => 0, 'whatsapp' => 0, 'meetings' => 0, 'visits' => 0, 'won' => 0, 'lost' => 0, 'overdue' => 0];
    $add = function (array $list, string $col) use (&$rows) { foreach ($list as $r) if (isset($rows[(int) $r['uid']])) $rows[(int) $r['uid']][$col] = (int) $r['n']; };
    $add(db_all("SELECT e.to_val uid, COUNT(DISTINCT e.contact_id) n FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                  WHERE e.kind='assigned' AND e.to_val IS NOT NULL AND e.created_at BETWEEN ? AND ? AND {$w} GROUP BY e.to_val", array_merge([$from, $to], $p)), 'given');
    $add(db_all("SELECT c.owner_user_id uid, COUNT(*) n FROM contacts c WHERE c.first_response_at BETWEEN ? AND ? AND {$w} GROUP BY c.owner_user_id", array_merge([$from, $to], $p)), 'contacted');
    if (db_has_column('crm_notes', 'kind')) {
        foreach (db_all("SELECT n.user_id uid, SUM(n.kind='call') calls,
                                SUM(n.outcome IN ('answered','interested','not_interested','booked','busy','sent_info')) answered,
                                SUM(n.outcome='no_answer') no_answer, SUM(n.kind='whatsapp') whatsapp, SUM(n.kind='meeting') meetings
                           FROM crm_notes n JOIN contacts c ON c.id=n.contact_id
                          WHERE n.user_id IS NOT NULL AND n.created_at BETWEEN ? AND ? AND {$w} GROUP BY n.user_id", array_merge([$from, $to], $p)) as $r) {
            if (!isset($rows[(int) $r['uid']])) continue;
            foreach (['calls', 'answered', 'no_answer', 'whatsapp', 'meetings'] as $k) $rows[(int) $r['uid']][$k] = (int) $r[$k];
        }
    }
    try {
        $add(db_all("SELECT v.user_id uid, COUNT(*) n FROM crm_visits v JOIN contacts c ON c.id=v.contact_id
                      WHERE v.status='done' AND v.starts_at BETWEEN ? AND ? AND {$w} GROUP BY v.user_id", array_merge([$from, $to], $p)), 'visits');
    } catch (Throwable $e) {}
    $kinds = crm_stage_kinds($cid);
    foreach (['won', 'lost'] as $k) {
        if (!$kinds[$k]) continue;
        $ph = implode(',', array_fill(0, count($kinds[$k]), '?'));
        $add(db_all("SELECT c.owner_user_id uid, COUNT(DISTINCT c.id) n FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                      WHERE e.kind='stage' AND e.to_val IN ($ph) AND c.stage_id IN ($ph) AND e.created_at BETWEEN ? AND ? AND {$w} GROUP BY c.owner_user_id",
                    array_merge(array_map('strval', $kinds[$k]), $kinds[$k], [$from, $to], $p)), $k);
    }
    $add(db_all("SELECT c.owner_user_id uid, COUNT(*) n FROM contacts c JOIN crm_stages s ON s.id=c.stage_id
                  WHERE s.kind='open' AND c.next_followup_at < NOW() AND {$w} GROUP BY c.owner_user_id", $p), 'overdue');
    $rows = array_values($rows);
    $cols = ['label' => ['Salesperson', 'text'], 'given' => ['Leads given', 'num'], 'contacted' => ['First contact', 'num'], 'calls' => ['Calls', 'num'],
             'answered' => ['Answered', 'num'], 'no_answer' => ['No answer', 'num'], 'whatsapp' => ['WhatsApps', 'num'], 'meetings' => ['Meetings', 'num'],
             'visits' => ['Came to a visit', 'num'], 'won' => ['Won', 'num'], 'lost' => ['Lost', 'num'], 'overdue' => ['Overdue now', 'num']];
    $t = ['label' => 'Team'];
    foreach (array_keys($cols) as $k) if ($k !== 'label') $t[$k] = array_sum(array_column($rows, $k));
    return ['cols' => $cols, 'rows' => $rows, 'total' => $t,
            'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['calls' => 'Calls', 'answered' => 'Answered']],
            'drill' => fn($r, $c) => match ($c) {
                'won', 'lost' => crm_lib_drill_closed(array_merge($f, ['owner' => (string) ($r['uid'] ?? $f['owner'])]), $c),
                'overdue'     => array_filter(['owner' => (string) ($r['uid'] ?? ''), 'due' => 'overdue']),
                default       => null,
            },
            'empty' => !$rows, 'note' => 'What happened ' . ($f['from'] === $f['to'] ? 'on ' . date('l j F', strtotime($f['from'])) : 'in the period') . '. "Overdue now" is as of this moment.'];
}

/** The daily report as a WhatsApp message: the team line, then each salesperson in one line. */
function crm_lib_daily_text(int $cid, string $day, ?array $scope = null): string
{
    $f = ['from' => $day, 'to' => $day, 'owner' => '', 'team' => '', 'source' => '', 'project' => '', 'dtype' => '', 'platform' => '', 'campaign' => '', 'qual' => ''];
    $r = crm_lib_daily($cid, $f);
    $t = $r['total'];
    $lines = ['Daily report — ' . date('D j M', strtotime($day)),
              'Team: ' . $t['given'] . ' new · ' . $t['contacted'] . ' contacted · ' . $t['calls'] . ' calls (' . $t['answered'] . ' answered) · '
              . $t['visits'] . ' visits · ' . $t['won'] . ' won · ' . $t['overdue'] . ' overdue'];
    foreach ($r['rows'] as $x) {
        if ($scope !== null && !in_array((int) $x['uid'], $scope, true)) continue;
        $lines[] = '• ' . $x['label'] . ': ' . $x['given'] . ' new, ' . $x['calls'] . ' calls, ' . $x['visits'] . ' visits, ' . $x['won'] . ' won'
                 . ($x['overdue'] ? ', ' . $x['overdue'] . ' overdue' : '');
    }
    return implode("\n", $lines);
}

/**
 * Evening pass: at the account's hour, the day's report to each manager (in the app, and on
 * WhatsApp if the account sends "daily" alerts). Once a day.
 */
function crm_lib_daily_tick(): int
{
    if (!db_has_column('crm_settings', 'daily_hour')) return 0;
    $sent = 0;
    foreach (db_all("SELECT s.client_id, s.daily_hour FROM crm_settings s JOIN clients c ON c.id=s.client_id
                      WHERE s.daily_hour IS NOT NULL AND c.status='active' AND (s.daily_sent_on IS NULL OR s.daily_sent_on < CURDATE())") as $s) {
        if ((int) date('G') < (int) $s['daily_hour']) continue;
        $cid = (int) $s['client_id'];
        if (!db_run("UPDATE crm_settings SET daily_sent_on=CURDATE() WHERE client_id=? AND (daily_sent_on IS NULL OR daily_sent_on < CURDATE())", [$cid])) continue;
        $client = db_row("SELECT * FROM clients WHERE id=?", [$cid]);
        $GLOBALS['CLIENT'] = $client; $GLOBALS['PERM_USER'] = ['id' => 0, 'role' => 'admin'];    // the whole team, as the system
        $text = crm_lib_daily_text($cid, date('Y-m-d'));
        foreach (crm_admin_ids($cid) as $a) { crm_notice($cid, $a, 'daily', null, ['day' => date('Y-m-d'), 'text' => $text]); $sent++; }
        // Team leaders get their own team's lines.
        foreach (crm_teams($cid) as $t) {
            $lead = (int) ($t['leader_user_id'] ?? 0);
            if (!$lead || in_array($lead, crm_admin_ids($cid), true)) continue;
            crm_notice($cid, $lead, 'daily', null, ['day' => date('Y-m-d'), 'text' => crm_lib_daily_text($cid, date('Y-m-d'), crm_team_member_ids((int) $t['id']))]);
            $sent++;
        }
        unset($GLOBALS['CLIENT'], $GLOBALS['PERM_USER']);
    }
    return $sent;
}

/** What is late right now, per salesperson. Not a period report: it is the state of today. */
function crm_lib_delay(int $cid, array $f): array
{
    $f2 = $f; $f2['from'] = '2000-01-01'; $f2['to'] = date('Y-m-d');
    [$w, $p] = crm_report_where($cid, $f2);
    $s = crm_settings($cid);
    $late = (int) ($s['first_contact_minutes'] ?? 0) ?: 60;
    $rows = [];
    foreach (crm_lib_people($cid, $f) as $uid => $name) $rows[$uid] = ['label' => $name, 'uid' => $uid, 'late' => 0, 'overdue' => 0, 'oldest' => null, 'avg' => null, 'missed' => 0];
    foreach (db_all("SELECT c.owner_user_id uid, COUNT(*) n FROM contacts c JOIN crm_stages s ON s.id=c.stage_id
                      WHERE s.kind='open' AND c.first_response_at IS NULL AND c.assigned_at < NOW() - INTERVAL ? MINUTE AND {$w} GROUP BY c.owner_user_id",
                    array_merge([$late], $p)) as $r) if (isset($rows[(int) $r['uid']])) $rows[(int) $r['uid']]['late'] = (int) $r['n'];
    foreach (db_all("SELECT c.owner_user_id uid, COUNT(*) n, MAX(TIMESTAMPDIFF(HOUR, c.next_followup_at, NOW())) oldest, AVG(TIMESTAMPDIFF(HOUR, c.next_followup_at, NOW())) av
                       FROM contacts c JOIN crm_stages s ON s.id=c.stage_id WHERE s.kind='open' AND c.next_followup_at < NOW() AND {$w} GROUP BY c.owner_user_id", $p) as $r) {
        if (!isset($rows[(int) $r['uid']])) continue;
        $rows[(int) $r['uid']]['overdue'] = (int) $r['n'];
        $rows[(int) $r['uid']]['oldest'] = $r['oldest'] !== null ? (float) $r['oldest'] / 24 : null;
        $rows[(int) $r['uid']]['avg'] = $r['av'] !== null ? (float) $r['av'] / 24 : null;
    }
    try {
        foreach (db_all("SELECT v.user_id uid, COUNT(*) n FROM crm_visits v JOIN contacts c ON c.id=v.contact_id
                          WHERE v.status='scheduled' AND v.starts_at < NOW() - INTERVAL 3 HOUR AND {$w} GROUP BY v.user_id", $p) as $r)
            if (isset($rows[(int) $r['uid']])) $rows[(int) $r['uid']]['missed'] = (int) $r['n'];
    } catch (Throwable $e) {}
    $rows = array_values($rows);
    usort($rows, fn($a, $b) => [$b['late'] + $b['overdue'], $b['missed']] <=> [$a['late'] + $a['overdue'], $a['missed']]);
    return ['cols' => ['label' => ['Salesperson', 'text'], 'late' => ['Not contacted in time', 'num'], 'overdue' => ['Overdue follow-ups', 'num'],
                       'oldest' => ['Oldest overdue', 'days'], 'avg' => ['Typical lateness', 'days'], 'missed' => ['Visits with no outcome', 'num']],
            'rows' => $rows,
            'total' => ['label' => 'Team', 'late' => array_sum(array_column($rows, 'late')), 'overdue' => array_sum(array_column($rows, 'overdue')),
                        'oldest' => ($o = array_filter(array_column($rows, 'oldest'), fn($x) => $x !== null)) ? max($o) : null, 'avg' => null,
                        'missed' => array_sum(array_column($rows, 'missed'))],
            'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['late' => 'Not contacted in time', 'overdue' => 'Overdue follow-ups']],
            'drill' => fn($r, $c) => match ($c) {
                'late'    => array_filter(['owner' => (string) ($r['uid'] ?? ''), 'status' => 'late']),
                'overdue' => array_filter(['owner' => (string) ($r['uid'] ?? ''), 'due' => 'overdue']),
                default   => null,
            },
            'empty' => !$rows, 'note' => 'As of now, whatever the period. "Not contacted in time" means given more than ' . $late . ' minutes ago with no call or message yet.'];
}

/** How fast new leads were first answered, in buckets, per salesperson. */
function crm_lib_first_reply(int $cid, array $f): array
{
    [$w, $p] = crm_report_where($cid, $f);
    $rows = [];
    foreach (crm_lib_people($cid, $f) as $uid => $name) $rows[$uid] = ['label' => $name, 'uid' => $uid, 'b5' => 0, 'b60' => 0, 'b1d' => 0, 'bmore' => 0, 'none' => 0, 'all' => 0, 'secs' => []];
    foreach (db_all("SELECT c.owner_user_id uid, TIMESTAMPDIFF(SECOND, c.assigned_at, c.first_response_at) s
                       FROM contacts c WHERE c.assigned_at BETWEEN ? AND ? AND c.owner_user_id IS NOT NULL AND {$w}",
                    array_merge([$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'], $p)) as $r) {
        $u = (int) $r['uid']; if (!isset($rows[$u])) continue;
        $rows[$u]['all']++;
        if ($r['s'] === null || (int) $r['s'] < 0) { $rows[$u]['none']++; continue; }
        $sec = (int) $r['s']; $rows[$u]['secs'][] = $sec;
        $rows[$u][$sec < 300 ? 'b5' : ($sec < 3600 ? 'b60' : ($sec < 86400 ? 'b1d' : 'bmore'))]++;
    }
    $all = [];
    foreach ($rows as &$r) { $all = array_merge($all, $r['secs']); $r['median'] = crm_median($r['secs']); $r['fast'] = $r['all'] ? round(100 * $r['b5'] / $r['all']) : null; unset($r['secs']); }
    unset($r);
    $rows = array_values(array_filter($rows, fn($r) => $r['all'] > 0));
    $t = ['label' => 'Team', 'median' => crm_median($all)];
    foreach (['b5', 'b60', 'b1d', 'bmore', 'none', 'all'] as $k) $t[$k] = array_sum(array_column($rows, $k));
    $t['fast'] = $t['all'] ? round(100 * $t['b5'] / $t['all']) : null;
    return ['cols' => ['label' => ['Salesperson', 'text'], 'all' => ['Leads given', 'num'], 'b5' => ['Under 5 min', 'num'], 'b60' => ['5–60 min', 'num'],
                       'b1d' => ['1–24 hours', 'num'], 'bmore' => ['Over a day', 'num'], 'none' => ['Not yet', 'num'], 'fast' => ['Under 5 min', 'pct'],
                       'median' => ['Typical', 'dur']],
            'rows' => $rows, 'total' => $t, 'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['b5' => 'Under 5 min', 'none' => 'Not yet']],
            // No drill-down: the leads list has no "given between these dates" filter, and a link must open exactly the number shown.
            'empty' => !$rows, 'note' => 'Leads given in the period: time from being given to the first call or message by a person. '
                     . 'For the leads waiting right now, see the Delays report.'];
}

/** Leads moved between people in the period: how often, from whom, to whom — and did it help. */
function crm_lib_rotation(int $cid, array $f): array
{
    [$w, $p] = crm_report_where($cid, $f);
    $from = $f['from'] . ' 00:00:00'; $to = $f['to'] . ' 23:59:59';
    $kinds = crm_stage_kinds($cid); $won = $kinds['won'] ?: [0];
    $rows = [];
    foreach (crm_lib_people($cid, $f) as $uid => $name) $rows[$uid] = ['label' => $name, 'uid' => $uid, 'taken' => 0, 'given' => 0, 'given_won' => 0];
    $moves = db_all("SELECT e.contact_id, e.from_val, e.to_val, c.stage_id FROM crm_events e JOIN contacts c ON c.id=e.contact_id
                      WHERE e.kind IN ('assigned','reclaimed') AND e.from_val IS NOT NULL AND e.to_val IS NOT NULL AND e.created_at BETWEEN ? AND ? AND {$w}",
                    array_merge([$from, $to], $p));
    $times = [];
    foreach ($moves as $m) {
        $times[(int) $m['contact_id']] = ($times[(int) $m['contact_id']] ?? 0) + 1;
        if (isset($rows[(int) $m['from_val']])) $rows[(int) $m['from_val']]['taken']++;
        if (isset($rows[(int) $m['to_val']])) { $rows[(int) $m['to_val']]['given']++; if (in_array((int) $m['stage_id'], $won, true)) $rows[(int) $m['to_val']]['given_won']++; }
    }
    $once = count(array_filter($times, fn($n) => $n === 1)); $twice = count(array_filter($times, fn($n) => $n === 2)); $more = count(array_filter($times, fn($n) => $n >= 3));
    $movedWon = count(array_filter(array_keys($times), fn($id) => in_array((int) db_val("SELECT stage_id FROM contacts WHERE id=?", [$id]), $won, true)));
    // Leads that arrived in the period and were never moved: the comparison.
    $ph = implode(',', array_fill(0, count($won), '?'));
    $stay = db_row("SELECT COUNT(*) n, SUM(c.stage_id IN ($ph)) w FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND {$w}
                     AND NOT EXISTS (SELECT 1 FROM crm_events e WHERE e.contact_id=c.id AND e.kind IN ('assigned','reclaimed') AND e.from_val IS NOT NULL)",
                   array_merge($won, [$from, $to], $p));
    $rows = array_values(array_filter($rows, fn($r) => $r['taken'] || $r['given']));
    foreach ($rows as &$r) $r['rate'] = $r['given'] ? round(100 * $r['given_won'] / $r['given'], 1) : null;
    unset($r);
    return ['tiles' => [['Moved once', $once, 'num'], ['Moved twice', $twice, 'num'], ['Moved 3+ times', $more, 'num'],
                        ['Won after moving', count($times) ? round(100 * $movedWon / count($times), 1) : null, 'pct'],
                        ['Won, never moved', (int) ($stay['n'] ?? 0) ? round(100 * (int) $stay['w'] / (int) $stay['n'], 1) : null, 'pct']],
            'cols' => ['label' => ['Salesperson', 'text'], 'taken' => ['Taken from them', 'num'], 'given' => ['Passed to them', 'num'],
                       'given_won' => ['Of those, won', 'num'], 'rate' => ['Won ÷ passed', 'pct']],
            'rows' => $rows, 'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['taken' => 'Taken from them', 'given' => 'Passed to them']],
            'empty' => !$times, 'note' => 'Moves in the period, by hand or by the response-time rule. A lead passed around many times rarely sells — compare the two percentages above.'];
}

/** Leads worth money going quiet: hot or warm, open, nothing for N days. One row per lead. */
function crm_lib_churn(int $cid, array $f): array
{
    $days = 5;
    $f2 = $f; $f2['from'] = '2000-01-01'; $f2['to'] = date('Y-m-d');
    [$w, $p] = crm_report_where($cid, $f2);
    $rows = db_all("SELECT c.id, c.name, c.phone_e164, c.score, s.name stage, COALESCE(NULLIF(u.name,''), u.email) owner, c.next_followup_at,
                           DATEDIFF(NOW(), GREATEST(COALESCE(c.last_touch_at,'2000-01-01'), COALESCE(c.last_inbound_at,'2000-01-01'), COALESCE(c.crm_added_at, c.created_at))) quiet
                      FROM contacts c JOIN crm_stages s ON s.id=c.stage_id LEFT JOIN users u ON u.id=c.owner_user_id
                     WHERE s.kind='open' AND c.score >= 55 AND {$w}
                    HAVING quiet >= ? ORDER BY c.score DESC, quiet DESC LIMIT 100", array_merge($p, [$days]));
    $out = array_map(fn($r) => ['label' => (string) ($r['name'] ?: '#' . crm_code((int) $r['id'])), '_href' => 'crm_lead.php?id=' . (int) $r['id'],
                                'owner' => (string) ($r['owner'] ?? 'Unassigned'), 'stage' => (string) $r['stage'], 'score' => (int) $r['score'],
                                'quiet' => (int) $r['quiet'], 'next' => $r['next_followup_at'] ? date('j M', strtotime((string) $r['next_followup_at'])) : 'None'], $rows);
    return ['tiles' => [['Going cold', count($out), 'num'], ['Hot among them', count(array_filter($out, fn($r) => $r['score'] >= 70)), 'num']],
            'cols' => ['label' => ['Lead', 'text'], 'owner' => ['Owner', 'text'], 'stage' => ['Stage', 'text'], 'score' => ['Score', 'num'],
                       'quiet' => ['Days with nothing', 'num'], 'next' => ['Next follow-up', 'text']],
            'rows' => $out, 'drill' => null, 'empty' => !$out,
            'note' => 'Open leads scoring 55+ with no call, message or activity for ' . $days . ' days or more. Open them in the list to reassign: '
                    . 'Leads → Filters → "No activity for 5 days" and "Hot only".', 'list' => ['idle' => (string) $days, 'state' => 'open', 'sort' => 'hot']];
}

/** Stage by sub-status, for the leads that arrived in the period, as they stand now. */
function crm_lib_substatus(int $cid, array $f): array
{
    [$w, $p] = crm_report_where($cid, $f);
    $counts = db_all("SELECT c.stage_id, COALESCE(NULLIF(c.substatus,''), '') sub, COUNT(*) n FROM contacts c
                       WHERE c.crm_added_at BETWEEN ? AND ? AND c.stage_id IS NOT NULL AND {$w} GROUP BY c.stage_id, sub",
                     array_merge([$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'], $p));
    $by = [];
    foreach ($counts as $c) $by[(int) $c['stage_id']][(string) $c['sub']] = (int) $c['n'];
    $rows = [];
    foreach (crm_stages($cid) as $s) {
        $sid = (int) $s['id']; $tot = array_sum($by[$sid] ?? []);
        if (!$tot) continue;
        $rows[] = ['label' => $s['name'], 'sub' => 'All', 'n' => $tot, 'share' => 100, 'stage_id' => $sid, '_stage' => true];
        arsort($by[$sid]);
        foreach ($by[$sid] as $sub => $n) $rows[] = ['label' => '', 'sub' => $sub === '' ? 'No sub-status' : $sub, 'subkey' => $sub === '' ? '__none' : $sub,
                                                      'n' => $n, 'share' => round(100 * $n / $tot, 1), 'stage_id' => $sid];
    }
    return ['cols' => ['label' => ['Stage', 'text'], 'sub' => ['Sub-status', 'text'], 'n' => ['Leads', 'num'], 'share' => ['Of the stage', 'pct']],
            'rows' => $rows,
            'drill' => fn($r, $c) => $c === 'n' ? crm_lib_drill_base($f) + ['stage' => (string) $r['stage_id']] + (!empty($r['subkey']) ? ['sub' => $r['subkey']] : []) : null,
            'empty' => !$rows, 'note' => 'Leads that arrived in the period, by the stage and sub-status they are in today.'];
}

/* ───────────────────────── marketing: return on ad spend, quality ───────────────────────── */

/**
 * Spend against what it brought, by campaign or by ad. Leads are the ones that arrived in the
 * period from that campaign (Meta says which, for forms and click-to-WhatsApp ads); spend is what
 * Meta charged in the same period. Cost per sale counts sales among those leads, as they stand.
 */
function crm_lib_roi_level(int $cid, array $f, string $level): array
{
    require_once __DIR__ . '/meta_ads.php';
    [$w, $p] = crm_report_where($cid, $f);
    $col = $level === 'ad' ? 'meta_ad_id' : 'meta_campaign_id';
    $spend = meta_ads_spend($cid, $f['from'], $f['to'], $level);
    $kinds = crm_stage_kinds($cid); $won = $kinds['won'] ?: [0];
    $ph = implode(',', array_fill(0, count($won), '?'));
    $leads = [];
    foreach (db_all("SELECT c.$col k, MAX(c." . ($level === 'ad' ? 'ad_name' : 'campaign') . ") name, COUNT(*) n, SUM(c.stage_id IN ($ph)) won,
                            SUM(CASE WHEN c.stage_id IN ($ph) THEN COALESCE(c.deal_value,0) ELSE 0 END) won_value,
                            SUM(EXISTS (SELECT 1 FROM crm_visits v WHERE v.contact_id=c.id AND v.status='done')) visited
                       FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND c.$col IS NOT NULL AND c.$col <> '' AND {$w} GROUP BY c.$col",
                    array_merge($won, $won, [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'], $p)) as $r) $leads[(string) $r['k']] = $r;
    $rows = [];
    foreach (array_unique(array_merge(array_keys($spend), array_keys($leads))) as $k) {
        $s = $spend[$k] ?? null; $l = $leads[$k] ?? null;
        $sp = $s ? $s['spend'] : 0.0; $n = (int) ($l['n'] ?? 0); $wn = (int) ($l['won'] ?? 0); $vis = (int) ($l['visited'] ?? 0); $wv = (float) ($l['won_value'] ?? 0);
        $rows[] = ['label' => (string) (($s['name'] ?? '') ?: ($l['name'] ?? '') ?: $k), 'k' => $k, 'spend' => $sp ?: null, 'leads' => $n,
                   'cpl' => $n && $sp ? $sp / $n : null, 'visited' => $vis, 'cpv' => $vis && $sp ? $sp / $vis : null,
                   'won' => $wn, 'cps' => $wn && $sp ? $sp / $wn : null, 'won_value' => $wv ?: null, 'return' => $sp > 0 && $wv > 0 ? $wv / $sp : null];
    }
    usort($rows, fn($a, $b) => [(float) $b['spend'], $b['leads']] <=> [(float) $a['spend'], $a['leads']]);
    $ts = array_sum(array_map(fn($r) => (float) $r['spend'], $rows)); $tl = array_sum(array_column($rows, 'leads'));
    $tw = array_sum(array_column($rows, 'won')); $tv = array_sum(array_map(fn($r) => (float) $r['won_value'], $rows)); $tvis = array_sum(array_column($rows, 'visited'));
    $hasSpend = (bool) db_val("SELECT COUNT(*) FROM meta_ad_accounts WHERE client_id=?", [$cid]);
    return ['tiles' => [['Spent', $ts ?: null, 'money_short'], ['Leads', $tl, 'num'], ['Cost per lead', $tl && $ts ? $ts / $tl : null, 'money_short'],
                        ['Won', $tw, 'num'], ['Cost per sale', $tw && $ts ? $ts / $tw : null, 'money_short'], ['Return', $ts > 0 && $tv > 0 ? $tv / $ts : null, 'x']],
            'cols' => ['label' => [$level === 'ad' ? 'Ad' : 'Campaign', 'text'], 'spend' => ['Spent', 'money'], 'leads' => ['Leads', 'num'], 'cpl' => ['Per lead', 'money'],
                       'visited' => ['Came to a visit', 'num'], 'cpv' => ['Per visit', 'money'], 'won' => ['Won', 'num'], 'cps' => ['Per sale', 'money'],
                       'won_value' => ['Won value', 'money'], 'return' => ['Return', 'x']],
            'rows' => $rows,
            'total' => ['label' => 'Total', 'spend' => $ts ?: null, 'leads' => $tl, 'cpl' => $tl && $ts ? $ts / $tl : null, 'visited' => $tvis,
                        'cpv' => $tvis && $ts ? $ts / $tvis : null, 'won' => $tw, 'cps' => $tw && $ts ? $ts / $tw : null, 'won_value' => $tv ?: null,
                        'return' => $ts > 0 && $tv > 0 ? $tv / $ts : null],
            'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['leads' => 'Leads', 'won' => 'Won']],
            'drill' => function ($r, $c) use ($f, $level) {
                if ($r['label'] === 'Total' || empty($r['k'])) return null;
                $base = crm_lib_drill_base($f) + [$level === 'ad' ? 'mad' : 'mcamp' => (string) $r['k']];
                return match ($c) { 'leads' => $base, 'won' => $base + ['state' => 'won'], default => null };
            },
            'empty' => !$rows,
            'note' => $hasSpend ? 'Spend as Meta charged it in the period; leads that arrived in the period from each ' . ($level === 'ad' ? 'ad' : 'campaign')
                                  . ', and how many of them came to a visit and bought. Return = won value ÷ spend.'
                                : 'No ad account is connected, so there is no spend yet — connect one in Lead forms → Ad spend. Leads and sales per '
                                  . ($level === 'ad' ? 'ad' : 'campaign') . ' still show.'];
}
function crm_lib_roi(int $cid, array $f): array     { return crm_lib_roi_level($cid, $f, 'campaign'); }
function crm_lib_roi_ads(int $cid, array $f): array { return crm_lib_roi_level($cid, $f, 'ad'); }

/** How good the leads are, per source or campaign: reachable, real, interested. */
function crm_lib_quality(int $cid, array $f, string $dim): array
{
    [$w, $p] = crm_report_where($cid, $f);
    $col = $dim === 'campaign' ? "COALESCE(NULLIF(c.campaign,''), '')" : 'c.source';
    $hasOut = db_has_column('crm_notes', 'outcome');
    $rows = db_all("SELECT $col k, COUNT(*) leads,
                           SUM(c.submissions > 1) again,
                           SUM(" . ($hasOut ? "EXISTS (SELECT 1 FROM crm_notes n WHERE n.contact_id=c.id AND n.outcome='wrong_number') OR " : '') . "c.lost_reason LIKE 'Wrong number%') wrong,
                           SUM(c.qualification='not_qualified') notq, SUM(c.qualification='qualified') qual,
                           " . ($hasOut ? "SUM((SELECT COUNT(*) FROM crm_notes n WHERE n.contact_id=c.id AND n.outcome='no_answer') >= 3)" : "0") . " noans,
                           " . ($hasOut ? "SUM(EXISTS (SELECT 1 FROM crm_notes n WHERE n.contact_id=c.id AND n.outcome IN ('answered','interested','not_interested','booked','busy','visited')))" : "0") . " reached
                      FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND {$w} GROUP BY $col ORDER BY leads DESC LIMIT 50",
                   array_merge([$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'], $p));
    $out = [];
    foreach ($rows as $r) {
        $n = (int) $r['leads'];
        $out[] = ['label' => $dim === 'campaign' ? ((string) $r['k'] !== '' ? (string) $r['k'] : 'No campaign') : crm_source_label($r['k']), 'k' => (string) $r['k'],
                  'leads' => $n, 'reached' => $n ? round(100 * $r['reached'] / $n, 1) : null, 'qual' => (int) $r['qual'], 'notq' => (int) $r['notq'],
                  'wrong' => (int) $r['wrong'], 'noans' => (int) $r['noans'], 'again' => (int) $r['again'],
                  'junk' => $n ? round(100 * ((int) $r['wrong'] + (int) $r['notq']) / $n, 1) : null];
    }
    $sum = fn($k) => array_sum(array_column($out, $k));
    $tn = $sum('leads');
    return ['cols' => ['label' => [$dim === 'campaign' ? 'Campaign' : 'Source', 'text'], 'leads' => ['Leads', 'num'], 'reached' => ['Reached on the phone', 'pct'],
                       'qual' => ['Qualified', 'num'], 'notq' => ['Not qualified', 'num'], 'wrong' => ['Wrong number', 'num'], 'noans' => ['No answer 3+', 'num'],
                       'again' => ['Came in again', 'num'], 'junk' => ['Junk (wrong + not qualified)', 'pct']],
            'rows' => $out,
            'total' => ['label' => 'Total', 'leads' => $tn, 'reached' => null, 'qual' => $sum('qual'), 'notq' => $sum('notq'), 'wrong' => $sum('wrong'),
                        'noans' => $sum('noans'), 'again' => $sum('again'), 'junk' => $tn ? round(100 * ($sum('wrong') + $sum('notq')) / $tn, 1) : null],
            'chart' => ['type' => 'pair', 'label' => 'label', 'series' => ['leads' => 'Leads', 'qual' => 'Qualified']],
            'drill' => function ($r, $c) use ($f, $dim) {
                if ($r['label'] === 'Total') return null;
                $slice = $dim === 'campaign' ? ['ceq' => $r['k'] !== '' ? $r['k'] : '__none'] : ['source' => $r['k']];
                $base = crm_lib_drill_base($f) + $slice;
                return match ($c) { 'leads' => $base, 'qual' => $base + ['qual' => 'qualified'], 'notq' => $base + ['qual' => 'not_qualified'],
                                    'noans' => $base + ['noans' => '3'], 'again' => $base + ['status' => 'again'], default => null };
            },
            'empty' => !$out, 'note' => 'Leads that arrived in the period. "Reached" means at least one call answered or a real conversation logged.'];
}
function crm_lib_quality_source(int $cid, array $f): array   { return crm_lib_quality($cid, $f, 'source'); }
function crm_lib_quality_campaign(int $cid, array $f): array { return crm_lib_quality($cid, $f, 'campaign'); }

/** Leads of the period by one slice (platform, project) and by the stage they are in now. */
function crm_lib_by_stage(int $cid, array $f, string $dim): array
{
    [$w, $p] = crm_report_where($cid, $f);
    $col = $dim === 'project' ? 'c.project_id' : 'c.platform';
    $stages = crm_stages($cid);
    $cnt = db_all("SELECT $col k, c.stage_id, COUNT(*) n FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND c.stage_id IS NOT NULL AND {$w} GROUP BY $col, c.stage_id",
                  array_merge([$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'], $p));
    $pn = crm_project_names($cid);
    $by = [];
    foreach ($cnt as $r) {
        $k = (string) ($r['k'] ?? '');
        $by[$k] ??= ['label' => $dim === 'project' ? ($k !== '' ? ($pn[(int) $k] ?? 'A removed project') : 'No project') : ($k !== '' ? crm_platform_label($k) : 'Not known'),
                     'k' => $k, 'leads' => 0];
        $by[$k]['s' . $r['stage_id']] = (int) $r['n'];
        $by[$k]['leads'] += (int) $r['n'];
    }
    $wonIds = crm_stage_kinds($cid)['won'];
    foreach ($by as &$r) {
        $wn = 0; foreach ($wonIds as $sid) $wn += (int) ($r['s' . $sid] ?? 0);
        $r['rate'] = $r['leads'] ? round(100 * $wn / $r['leads'], 1) : null;
        foreach ($stages as $s) $r['s' . $s['id']] ??= 0;
    }
    unset($r);
    $rows = array_values($by);
    usort($rows, fn($a, $b) => $b['leads'] <=> $a['leads']);
    $cols = ['label' => [$dim === 'project' ? 'Project' : 'Platform', 'text'], 'leads' => ['Leads', 'num']];
    foreach ($stages as $s) $cols['s' . $s['id']] = [$s['name'], 'num'];
    $cols['rate'] = ['Won ÷ leads', 'pct'];
    $t = ['label' => 'Total', 'leads' => array_sum(array_column($rows, 'leads'))];
    foreach ($stages as $s) $t['s' . $s['id']] = array_sum(array_column($rows, 's' . $s['id']));
    $t['rate'] = null;
    return ['cols' => $cols, 'rows' => $rows, 'total' => $t, 'chart' => ['type' => 'bars', 'label' => 'label', 'series' => ['leads' => 'Leads']],
            'drill' => function ($r, $c) use ($f, $dim) {
                if ($r['label'] === 'Total') return null;
                $slice = $dim === 'project' ? ['project' => $r['k'] !== '' ? $r['k'] : 'none'] : ($r['k'] !== '' ? ['platform' => $r['k']] : null);
                if ($slice === null) return null;
                if ($c === 'leads') return crm_lib_drill_base($f) + $slice;
                if (str_starts_with($c, 's') && ctype_digit(substr($c, 1))) return crm_lib_drill_base($f) + $slice + ['stage' => substr($c, 1)];
                return null;
            },
            'empty' => !$rows, 'note' => 'Leads that arrived in the period, by the stage they are in today.'];
}
function crm_lib_platform_status(int $cid, array $f): array { return crm_lib_by_stage($cid, $f, 'platform'); }
function crm_lib_project_status(int $cid, array $f): array  { return crm_lib_by_stage($cid, $f, 'project'); }

/** Each campaign's leads, split by who they went to, and how each person did with them. */
function crm_lib_campaign_sales(int $cid, array $f): array
{
    [$w, $p] = crm_report_where($cid, $f);
    $won = crm_stage_kinds($cid)['won'] ?: [0]; $ph = implode(',', array_fill(0, count($won), '?'));
    $rows = db_all("SELECT COALESCE(NULLIF(c.campaign,''), '') camp, c.owner_user_id uid, COUNT(*) leads, SUM(c.stage_id IN ($ph)) won,
                           SUM(CASE WHEN c.stage_id IN ($ph) THEN COALESCE(c.deal_value,0) ELSE 0 END) won_value
                      FROM contacts c WHERE c.crm_added_at BETWEEN ? AND ? AND c.owner_user_id IS NOT NULL AND {$w}
                     GROUP BY camp, c.owner_user_id ORDER BY camp = '', camp, won DESC, leads DESC LIMIT 300",
                   array_merge($won, $won, [$f['from'] . ' 00:00:00', $f['to'] . ' 23:59:59'], $p));
    $out = []; $last = null;
    foreach ($rows as $r) {
        $camp = (string) $r['camp'];
        $out[] = ['label' => $camp !== $last ? ($camp !== '' ? $camp : 'No campaign') : '', 'camp' => $camp, 'uid' => (int) $r['uid'],
                  'person' => crm_user_name((int) $r['uid']), 'leads' => (int) $r['leads'], 'won' => (int) $r['won'],
                  'rate' => (int) $r['leads'] ? round(100 * $r['won'] / $r['leads'], 1) : null, 'won_value' => (float) $r['won_value'] ?: null,
                  '_stage' => $camp !== $last];
        $last = $camp;
    }
    foreach ($out as &$r) $r['_stage'] = false;
    unset($r);
    return ['cols' => ['label' => ['Campaign', 'text'], 'person' => ['Salesperson', 'text'], 'leads' => ['Leads', 'num'], 'won' => ['Won', 'num'],
                       'rate' => ['Won ÷ leads', 'pct'], 'won_value' => ['Won value', 'money']],
            'rows' => $out,
            'drill' => fn($r, $c) => in_array($c, ['leads', 'won'], true)
                ? crm_lib_drill_base($f) + ['owner' => (string) $r['uid'], 'ceq' => $r['camp'] !== '' ? $r['camp'] : '__none'] + ($c === 'won' ? ['state' => 'won'] : [])
                : null,
            'empty' => !$out, 'note' => 'Leads that arrived in the period, by campaign and by the salesperson who has them now.'];
}

/* ───────────────────────── favourites ───────────────────────── */

function crm_lib_favs(?array $user): array
{
    return array_values(array_filter(array_map('trim', explode(',', (string) ($user['report_favs'] ?? '')))));
}
