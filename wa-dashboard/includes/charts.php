<?php
declare(strict_types=1);

/**
 * Charts for the dashboards and reports — drawn on the server, no chart library.
 *
 *   viz_period()      the period a page shows (7 / 30 / 90 days, 12 months or chosen dates), its
 *                     buckets (day, week or month) and the period before it, for comparisons
 *   viz_kpi()         a number with its change against the previous period and a sparkline
 *   viz_trend()       a line (or columns, stacked or not) over time, with a crosshair tooltip
 *   viz_bars()        horizontal bars for comparing categories, the number written on each
 *   viz_funnel()      steps that people drop out of (sent → delivered → read → replied)
 *   viz_share()       one bar split into its parts (channels, statuses), with a legend
 *   viz_heat()        weekday × hour heat map
 *
 * Every chart can show its numbers as a table ("Show as a table"), colors come from the --viz-*
 * tokens in dashboard.css (light and dark), and identity is never color alone: a legend or
 * direct labels always name the series. Hover and keyboard behaviour live in assets/viz.js.
 */

/* ───────────────────────── periods ───────────────────────── */

function viz_periods(): array
{
    return ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days', '12m' => 'Last 12 months'];
}

/**
 * The period from the query string (p=7d|30d|90d|12m, or from/to dates).
 * Returns from, to (Y-m-d), bucket (day|week|month), prev_from, prev_to, label, days, key.
 */
function viz_period(array $get, string $default = '30d'): array
{
    $key = (string) ($get['p'] ?? '');
    $okDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
    if ($key === 'custom' || ($key === '' && $okDate($get['from'] ?? null) && $okDate($get['to'] ?? null))) {
        $from = $okDate($get['from'] ?? null) ? (string) $get['from'] : date('Y-m-d', strtotime('-29 days'));
        $to   = $okDate($get['to'] ?? null) ? (string) $get['to'] : date('Y-m-d');
        if ($from > $to) [$from, $to] = [$to, $from];
        if ($to > date('Y-m-d')) $to = date('Y-m-d');
        if ((strtotime($to) - strtotime($from)) / 86400 > 3 * 366) $from = date('Y-m-d', strtotime($to . ' -3 years'));
        $key = 'custom';
        $label = date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to));
    } else {
        if (!isset(viz_periods()[$key])) $key = isset(viz_periods()[$default]) ? $default : '30d';
        $to = date('Y-m-d');
        $from = $key === '12m' ? date('Y-m-01', strtotime('-11 months')) : date('Y-m-d', strtotime('-' . ((int) $key - 1) . ' days'));
        $label = viz_periods()[$key];
    }
    $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $bucket = $days <= 31 ? 'day' : ($days <= 190 ? 'week' : 'month');
    $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));
    return ['key' => $key, 'from' => $from, 'to' => $to, 'bucket' => $bucket, 'days' => $days, 'label' => $label,
            'prev_from' => $prevFrom, 'prev_to' => $prevTo,
            'start' => $from . ' 00:00:00', 'end' => $to . ' 23:59:59',
            'prev_start' => $prevFrom . ' 00:00:00', 'prev_end' => $prevTo . ' 23:59:59'];
}

/** "vs the previous 30 days", for the comparison line under a number. */
function viz_prev_words(array $p): string
{
    return $p['key'] === '12m' ? 'vs the 12 months before' : 'vs the previous ' . $p['days'] . ' days';
}

/** The period picker: quick choices plus a from–to form. $keep = other query values to carry. */
function viz_period_bar(array $p, array $keep = [], string $extraHtml = ''): string
{
    $q = fn(array $set) => '?' . http_build_query(array_filter($set + $keep, fn($v) => $v !== '' && $v !== null));
    $h = '<form method="get" class="viz-periods" aria-label="Period">';
    foreach ($keep as $k => $v) if ($v !== '' && $v !== null && !is_array($v)) $h .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    $h .= '<div class="viz-seg" role="group">';
    foreach (viz_periods() as $k => $l) {
        $h .= '<a href="' . e($q(['p' => $k])) . '" class="' . ($p['key'] === $k ? 'on' : '') . '"' . ($p['key'] === $k ? ' aria-current="true"' : '') . '>'
            . e(str_replace('Last ', '', $l)) . '</a>';
    }
    $h .= '</div><input type="hidden" name="p" value="custom">'
        . '<label class="viz-date"><span class="sr-only">From</span><input type="date" name="from" value="' . e($p['from']) . '" max="' . date('Y-m-d') . '"></label>'
        . '<span class="text-muted">–</span>'
        . '<label class="viz-date"><span class="sr-only">To</span><input type="date" name="to" value="' . e($p['to']) . '" max="' . date('Y-m-d') . '"></label>'
        . '<button class="btn btn-ghost btn-sm">Show</button>' . $extraHtml . '</form>';
    return $h;
}

/** Every bucket in the period, in order: key => label. Keys match viz_bucket_sql(). */
function viz_buckets(array $p): array
{
    $out = [];
    $t = strtotime($p['from']); $end = strtotime($p['to']);
    if ($p['bucket'] === 'week') $t = strtotime('monday this week', $t);
    if ($p['bucket'] === 'month') $t = strtotime(date('Y-m-01', $t));
    for ($i = 0; $t <= $end && $i < 400; $i++) {
        $out[match ($p['bucket']) { 'month' => date('Y-m', $t), default => date('Y-m-d', $t) }] =
            match ($p['bucket']) { 'month' => date('M Y', $t), 'week' => date('j M', $t), default => date('j M', $t) };
        $t = strtotime(match ($p['bucket']) { 'month' => '+1 month', 'week' => '+1 week', default => '+1 day' }, $t);
    }
    return $out;
}

/** The SQL expression giving a date column's bucket key. */
function viz_bucket_sql(string $col, string $bucket): string
{
    return match ($bucket) {
        'month' => "DATE_FORMAT($col, '%Y-%m')",
        'week'  => "DATE_FORMAT($col - INTERVAL WEEKDAY($col) DAY, '%Y-%m-%d')",
        default => "DATE_FORMAT($col, '%Y-%m-%d')",
    };
}

/** Rows of [b => key, n => value] laid onto the buckets, zeros where nothing happened. */
function viz_fill(array $buckets, array $rows, string $valCol = 'n', string $keyCol = 'b'): array
{
    $v = array_fill_keys(array_keys($buckets), 0);
    foreach ($rows as $r) if (array_key_exists((string) $r[$keyCol], $v)) $v[(string) $r[$keyCol]] = (float) $r[$valCol];
    return array_values($v);
}

/* ───────────────────────── formatting ───────────────────────── */

function viz_fmt($v, string $fmt = 'num'): string
{
    if ($v === null || $v === '') return '—';
    $v = (float) $v;
    return match ($fmt) {
        'pct'   => (abs($v) < 10 && floor($v) != $v ? number_format($v, 1) : number_format($v)) . '%',
        'dur'   => viz_dur($v),
        'money' => number_format($v),
        'dec'   => number_format($v, 1),
        default => abs($v) >= 100000 ? number_format($v / 1000, 0) . 'k' : number_format($v),
    };
}

/** Seconds as people say them: 45s, 12m, 3h 10m, 2d 4h. */
function viz_dur(float $s): string
{
    $s = (int) round($s);
    if ($s < 60) return $s . 's';
    if ($s < 3600) return round($s / 60) . 'm';
    if ($s < 86400) return floor($s / 3600) . 'h' . ($s % 3600 >= 60 ? ' ' . floor(($s % 3600) / 60) . 'm' : '');
    return floor($s / 86400) . 'd' . ($s % 86400 >= 3600 ? ' ' . floor(($s % 86400) / 3600) . 'h' : '');
}

/** A rounded-up axis maximum and its ticks (4 steps of 1, 2, 2.5 or 5 × 10ⁿ). */
function viz_ticks(float $max, int $steps = 4): array
{
    if ($max <= 0) return [1, [0, 1]];
    $raw = $max / $steps;
    $mag = 10 ** floor(log10($raw));
    $step = $mag;
    foreach ([1, 2, 2.5, 5, 10] as $m) { if ($m * $mag >= $raw) { $step = $m * $mag; break; } }
    if ($max >= 4 && $step < 1) $step = 1;
    $top = $step * ceil($max / $step);
    $ticks = [];
    for ($v = 0; $v <= $top + 1e-9; $v += $step) $ticks[] = round($v, 6);
    return [$top, $ticks];
}

function viz_once(): string
{
    static $done = false;
    if ($done) return '';
    $done = true;
    $f = __DIR__ . '/../assets/viz.js';
    $base = function_exists('asset_base') ? asset_base() : '../assets/';
    return '<script src="' . e($base . 'viz.js') . '?v=' . (is_file($f) ? filemtime($f) : 1) . '" defer></script>';
}

/* ───────────────────────── pieces ───────────────────────── */

function viz_spark(array $values, int $slot = 1): string
{
    $n = count($values);
    if ($n < 2) return '';
    $max = max($values) ?: 1; $min = min(0, min($values));
    $pts = [];
    foreach ($values as $i => $v) $pts[] = round(100 * $i / ($n - 1), 2) . ',' . round(28 - 26 * (($v - $min) / max(1e-9, $max - $min)), 2);
    [$lx, $ly] = explode(',', end($pts));
    return '<svg class="viz-spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
        . '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="var(--viz-' . $slot . ')" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>'
        . '</svg><span class="viz-spark-end" style="--x:' . $lx . '%;--yf:' . round($ly / 30, 4) . ';--c:var(--viz-' . $slot . ')" aria-hidden="true"></span>';
}

/**
 * A headline number. $prev = the same number for the previous period (null: no comparison).
 * Options: fmt, up_good (default true; false for failures, reply time), sub, href, spark (values), slot, id, compare (words).
 */
function viz_kpi(string $label, $value, $prev = null, array $o = []): string
{
    $fmt = $o['fmt'] ?? 'num';
    $delta = '';
    if ($prev !== null && $value !== null) {
        $v = (float) $value; $pv = (float) $prev;
        $upGood = $o['up_good'] ?? true;
        $words = $o['compare'] ?? 'vs previous period';
        if ($fmt === 'pct') {
            $d = round($v - $pv, 1);
            $txt = ($d > 0 ? '+' : '') . $d . ' pts';
        } elseif ($pv == 0.0) {
            $d = $v > 0 ? 1 : 0;
            $txt = $v > 0 ? 'new' : 'no change';
        } else {
            $d = round(100 * ($v - $pv) / abs($pv));
            $txt = ($d > 0 ? '+' : '') . $d . '%';
        }
        $cls = $d == 0 ? 'flat' : (($d > 0) === $upGood ? 'good' : 'bad');
        $icon = $d == 0 ? '→' : ($d > 0 ? '▲' : '▼');
        $delta = '<span class="viz-delta ' . $cls . '" title="' . e(viz_fmt($prev, $fmt) . ' ' . $words) . '"><i aria-hidden="true">' . $icon . '</i> '
            . e($txt) . ' <span class="viz-delta-w">' . e($words) . '</span></span>';
    }
    $tag = !empty($o['href']) ? 'a' : 'div';
    return '<' . $tag . ' class="viz-kpi"' . (!empty($o['href']) ? ' href="' . e($o['href']) . '"' : '') . (!empty($o['id']) ? ' id="' . e($o['id']) . '"' : '') . '>'
        . '<span class="viz-kpi-l">' . e($label) . '</span>'
        . '<span class="viz-kpi-v">' . e(viz_fmt($value, $fmt)) . '</span>'
        . $delta
        . (!empty($o['sub']) ? '<span class="viz-kpi-s">' . e($o['sub']) . '</span>' : '')
        . (!empty($o['spark']) ? '<span class="viz-kpi-sp">' . viz_spark(array_values($o['spark']), (int) ($o['slot'] ?? 1)) . '</span>' : '')
        . '</' . $tag . '>';
}

function viz_legend(array $series): string
{
    $h = '<div class="viz-legend">';
    foreach ($series as $s) $h .= '<span><i class="viz-key" style="--c:var(--viz-' . (int) ($s['slot'] ?? 1) . ')"></i>' . e((string) $s['label']) . '</span>';
    return $h . '</div>';
}

function viz_table(array $head, array $rows): string
{
    $h = '<details class="viz-table"><summary>Show as a table</summary><div class="table-wrap"><table class="data"><thead><tr>';
    foreach ($head as $i => $c) $h .= '<th' . ($i ? ' class="num"' : '') . '>' . e((string) $c) . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $h .= '<tr>';
        foreach (array_values($r) as $i => $c) $h .= '<td' . ($i ? ' class="num"' : '') . '>' . e((string) $c) . '</td>';
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div></details>';
}

/**
 * Over time. $labels = bucket labels; $series = [['label' =>, 'values' => [], 'slot' => 1..4], …].
 * Options: type line|columns, stack (columns), area (line, default when one series), fmt, height, table (default true),
 *          empty (message when everything is zero), id.
 */
function viz_trend(array $labels, array $series, array $o = []): string
{
    $type = $o['type'] ?? 'line';
    $fmt = $o['fmt'] ?? 'num';
    $n = count($labels);
    $series = array_values(array_slice($series, 0, 4));
    foreach ($series as $i => &$s) { $s['slot'] = (int) ($s['slot'] ?? $i + 1); $s['values'] = array_map('floatval', array_values($s['values'])); }
    unset($s);
    $total = 0.0; foreach ($series as $s) $total += array_sum(array_map('abs', $s['values']));
    if ($n === 0 || !$series || $total == 0.0) {
        return '<div class="viz-empty">' . e($o['empty'] ?? 'Nothing in this period yet.') . '</div>';
    }
    $stack = $type === 'columns' && !empty($o['stack']);
    $max = 0.0;
    for ($i = 0; $i < $n; $i++) {
        if ($stack) { $t = 0; foreach ($series as $s) $t += $s['values'][$i] ?? 0; $max = max($max, $t); }
        else foreach ($series as $s) $max = max($max, $s['values'][$i] ?? 0);
    }
    [$top, $ticks] = viz_ticks($max);
    $h = (int) ($o['height'] ?? 220);
    $data = ['labels' => array_values($labels), 'fmt' => $fmt, 'type' => $type, 'stack' => $stack, 'top' => $top,
             'series' => array_map(fn($s) => ['label' => $s['label'], 'slot' => $s['slot'], 'values' => $s['values']], $series)];
    $out = '<div class="viz-chart viz-' . e($type) . '" style="--h:' . $h . 'px" data-viz="' . e(json_encode($data, JSON_UNESCAPED_UNICODE)) . '"'
         . (!empty($o['id']) ? ' id="' . e($o['id']) . '"' : '') . '>';
    if (count($series) > 1) $out .= viz_legend($series);
    $out .= '<div class="viz-frame"><div class="viz-y" aria-hidden="true">';
    foreach ($ticks as $t) $out .= '<span style="bottom:' . round(100 * $t / $top, 3) . '%">' . e(viz_fmt($t, $fmt === 'pct' ? 'pct' : ($fmt === 'dur' ? 'dur' : 'num'))) . '</span>';
    $out .= '</div><div class="viz-plot" tabindex="0" role="img" aria-label="' . e(($o['aria'] ?? 'Chart') . ', ' . reset($labels) . ' to ' . end($labels) . '. Use left and right arrows to read each point.') . '">';
    $out .= '<div class="viz-grid" aria-hidden="true">';
    foreach ($ticks as $t) $out .= '<i style="bottom:' . round(100 * $t / $top, 3) . '%"></i>';
    $out .= '</div>';
    if ($type === 'columns') {
        $out .= '<div class="viz-cols">';
        for ($i = 0; $i < $n; $i++) {
            $out .= '<div class="viz-col' . ($stack ? ' stack' : '') . '">';
            foreach (($stack ? array_reverse($series) : $series) as $s) {
                $v = $s['values'][$i] ?? 0;
                $out .= '<span class="viz-colbar" style="height:' . round(100 * $v / $top, 3) . '%;--c:var(--viz-' . $s['slot'] . ')"></span>';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
    } else {
        $area = $o['area'] ?? count($series) === 1;
        $out .= '<svg viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true" focusable="false">';
        foreach ($series as $s) {
            $pts = [];
            foreach ($s['values'] as $i => $v) $pts[] = round($n > 1 ? 1000 * $i / ($n - 1) : 500, 2) . ',' . round(1000 - 1000 * $v / $top, 2);
            if ($area) $out .= '<polygon points="0,1000 ' . implode(' ', $pts) . ' ' . ($n > 1 ? '1000' : '500') . ',1000" fill="var(--viz-' . $s['slot'] . ')" opacity=".10"/>';
            $out .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="var(--viz-' . $s['slot'] . ')" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>';
        }
        $out .= '</svg>';
        // Direct labels at the end of each line (four or fewer), so the legend is not the only key.
        if (count($series) > 1) {
            // Lines that end close together would print their labels on top of each other: spread them.
            $gap = 100 * 18 / max(60, $h);
            $ends = [];
            foreach ($series as $s) { $lv = end($s['values']); $ends[] = ['pos' => 100 * $lv / $top, 'v' => $lv, 'slot' => $s['slot']]; }
            usort($ends, fn($a, $b) => $a['pos'] <=> $b['pos']);
            for ($i = 1; $i < count($ends); $i++) if ($ends[$i]['pos'] - $ends[$i - 1]['pos'] < $gap) $ends[$i]['pos'] = $ends[$i - 1]['pos'] + $gap;
            $over = end($ends)['pos'] - 100;
            if ($over > 0) foreach ($ends as &$en) $en['pos'] -= $over;
            unset($en);
            foreach ($ends as $en) {
                $out .= '<span class="viz-endlbl" style="bottom:' . round($en['pos'], 3) . '%;--c:var(--viz-' . $en['slot'] . ')">' . e(viz_fmt($en['v'], $fmt)) . '</span>';
            }
        }
    }
    $out .= '<div class="viz-cross" aria-hidden="true"></div></div></div>';
    // Bottom labels, thinned so they never collide.
    $every = max(1, (int) ceil($n / 8));
    $out .= '<div class="viz-x" aria-hidden="true">';
    $shown = 0;
    foreach (array_values($labels) as $i => $l) {
        if ($i % $every !== 0 && $i !== $n - 1) continue;
        if ($i === $n - 1 && $i % $every !== 0 && ($n - 1) % $every < $every / 2) continue;
        $pos = $type === 'columns' ? 100 * ($i + 0.5) / $n : ($n > 1 ? 100 * $i / ($n - 1) : 50);
        $cls = [];
        if ($type !== 'columns' && $i === 0) $cls[] = 'first';
        if ($type !== 'columns' && $i === $n - 1) $cls[] = 'last';
        if ($shown % 2 === 1 && $i !== $n - 1) $cls[] = 'alt';          // hidden on narrow screens
        if ($i === $n - 1 && $shown % 2 === 1) $cls[] = 'keep';
        $shown++;
        $out .= '<span' . ($cls ? ' class="' . implode(' ', $cls) . '"' : '') . ' style="left:' . round($pos, 3) . '%">' . e((string) $l) . '</span>';
    }
    $out .= '</div></div>';
    if ($o['table'] ?? true) {
        $rows = [];
        foreach (array_values($labels) as $i => $l) {
            $r = [(string) $l];
            foreach ($series as $s) $r[] = viz_fmt($s['values'][$i] ?? 0, $fmt);
            $rows[] = $r;
        }
        $out .= viz_table(array_merge([$o['x_label'] ?? 'Period'], array_column($series, 'label')), $rows);
    }
    return $out . viz_once();
}

/**
 * Categories side by side. $rows = [['label' =>, 'href' => optional, key => value, …]];
 * $series = [[key, label, slot], …]. Options: fmt, sub (key of a small note per row), max_rows, empty.
 */
function viz_bars(array $rows, array $series, array $o = []): string
{
    if (!$rows) return '<div class="viz-empty">' . e($o['empty'] ?? 'Nothing in this period yet.') . '</div>';
    $fmt = $o['fmt'] ?? 'num';
    $rows = array_slice($rows, 0, (int) ($o['max_rows'] ?? 12));
    $max = 0.0;
    foreach ($rows as $r) foreach ($series as [$k]) $max = max($max, (float) ($r[$k] ?? 0));
    $max = $max ?: 1;
    $out = count($series) > 1 ? viz_legend(array_map(fn($s) => ['label' => $s[1], 'slot' => $s[2]], $series)) : '';
    $out .= '<div class="viz-bars">';
    foreach ($rows as $r) {
        $tip = $r['label'] . ' — ' . implode(' · ', array_map(fn($s) => $s[1] . ': ' . viz_fmt($r[$s[0]] ?? 0, $s[3] ?? $fmt), $series))
             . (!empty($o['sub']) && isset($r[$o['sub']]) ? ' · ' . $r[$o['sub']] : '');
        $lbl = !empty($r['href']) ? '<a href="' . e($r['href']) . '">' . e((string) $r['label']) . '</a>' : e((string) $r['label']);
        $out .= '<div class="viz-bar-row" data-tip="' . e($tip) . '"><span class="viz-bar-l" title="' . e((string) $r['label']) . '">' . $lbl
              . (!empty($o['sub']) && isset($r[$o['sub']]) ? '<small>' . e((string) $r[$o['sub']]) . '</small>' : '') . '</span><span class="viz-bar-g">';
        foreach ($series as $s) {
            $v = (float) ($r[$s[0]] ?? 0);
            $out .= '<span class="viz-bar-line"><span class="viz-bar-track"><span class="viz-bar" style="width:' . ($v > 0 ? max(0.6, round(100 * $v / $max, 2)) : 0) . '%;--c:var(--viz-' . (int) $s[2] . ')"></span></span>'
                  . '<span class="viz-bar-v">' . e(viz_fmt($v, $s[3] ?? $fmt)) . '</span></span>';
        }
        $out .= '</span></div>';
    }
    return $out . '</div>' . viz_once();
}

/**
 * Steps people drop out of. $steps = [[label, n], …] in order. Each bar is its share of the first
 * step; between bars, the share that carried on. Options: note (per-step notes array).
 */
function viz_funnel(array $steps, array $o = []): string
{
    $first = (float) ($steps[0][1] ?? 0);
    if ($first <= 0) return '<div class="viz-empty">' . e($o['empty'] ?? 'Nothing in this period yet.') . '</div>';
    $out = '<ol class="viz-funnel">';
    $prev = null;
    foreach (array_values($steps) as $i => [$label, $n]) {
        $n = (float) $n;
        $pct = round(100 * $n / $first, 1);
        $cont = $prev !== null && $prev > 0 ? round(100 * $n / $prev) : null;
        $out .= '<li style="--w:' . max(0.6, $pct) . '%;--step:' . min($i, 4) . '" data-tip="' . e($label . ': ' . viz_fmt($n) . ' (' . $pct . '% of ' . $steps[0][0] . ')') . '">'
              . ($cont !== null ? '<span class="viz-f-cont">' . $cont . '% carried on</span>' : '')
              . '<span class="viz-f-l">' . e((string) $label) . '</span>'
              . '<span class="viz-f-track"><span class="viz-f-bar"></span></span>'
              . '<span class="viz-f-v"><b>' . e(viz_fmt($n)) . '</b> ' . ($i ? e(viz_fmt($pct, 'pct')) : '') . '</span></li>';
        $prev = $n;
    }
    return $out . '</ol>' . viz_once();
}

/**
 * One bar in parts. $parts = [[label, n, slot], …] — slot 1..8, or 'bad' for failures (status red).
 * Options: fmt, empty, legend (default true).
 */
function viz_share(array $parts, array $o = []): string
{
    $parts = array_values(array_filter($parts, fn($p) => (float) $p[1] > 0));
    $total = array_sum(array_map(fn($p) => (float) $p[1], $parts));
    if ($total <= 0) return ($o['legend'] ?? true) ? '<div class="viz-empty">' . e($o['empty'] ?? 'Nothing in this period yet.') . '</div>'
                                                   : '<span class="text-muted">' . e($o['empty'] ?? '—') . '</span>';
    $col = fn($s) => $s === 'bad' ? 'var(--viz-bad)' : 'var(--viz-' . (int) $s . ')';
    $out = '<div class="viz-share" role="img" aria-label="' . e(implode(', ', array_map(fn($p) => $p[0] . ' ' . round(100 * $p[1] / $total) . '%', $parts))) . '">';
    foreach ($parts as $p) {
        $out .= '<span style="flex:' . (float) $p[1] . ' 1 0;--c:' . $col($p[2]) . '" data-tip="' . e($p[0] . ': ' . viz_fmt($p[1], $o['fmt'] ?? 'num') . ' (' . round(100 * $p[1] / $total, 1) . '%)') . '"></span>';
    }
    $out .= '</div>';
    if ($o['legend'] ?? true) {
        $out .= '<ul class="viz-share-l">';
        foreach ($parts as $p) {
            $out .= '<li><i class="viz-key" style="--c:' . $col($p[2]) . '"></i><span>' . e((string) $p[0]) . '</span><b>' . e(viz_fmt($p[1], $o['fmt'] ?? 'num')) . '</b><small>' . round(100 * $p[1] / $total) . '%</small></li>';
        }
        $out .= '</ul>';
    }
    return $out . viz_once();
}

/** Weekday × hour. $m[weekday 0=Mon..6][hour 0..23] = count. */
function viz_heat(array $m, array $o = []): string
{
    $max = 0; foreach ($m as $row) foreach ($row as $v) $max = max($max, (float) $v);
    if ($max <= 0) return '<div class="viz-empty">' . e($o['empty'] ?? 'Nothing in this period yet.') . '</div>';
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $unit = $o['unit'] ?? 'messages';
    $out = '<div class="viz-heat" role="img" aria-label="' . e($o['aria'] ?? 'When it happens, by weekday and hour') . '"><span></span>';
    for ($hr = 0; $hr < 24; $hr++) $out .= '<span class="viz-heat-h">' . ($hr % 3 === 0 ? sprintf('%02d', $hr) : '') . '</span>';
    foreach ($days as $d => $dn) {
        $out .= '<span class="viz-heat-d">' . $dn . '</span>';
        for ($hr = 0; $hr < 24; $hr++) {
            $v = (float) ($m[$d][$hr] ?? 0);
            $lv = $v > 0 ? 1 + (int) min(5, floor(6 * $v / ($max + 1e-9))) : 0;
            $out .= '<span class="viz-cell l' . $lv . '" data-tip="' . e($dn . ' ' . sprintf('%02d:00', $hr) . ' — ' . number_format($v) . ' ' . $unit) . '"></span>';
        }
    }
    $out .= '</div><div class="viz-heat-key" aria-hidden="true"><span>Fewer</span>';
    for ($l = 1; $l <= 6; $l++) $out .= '<i class="viz-cell l' . $l . '"></i>';
    $out .= '<span>More</span></div>';
    // The busiest hours, in words.
    $flat = [];
    foreach ($m as $d => $row) foreach ($row as $hr => $v) if ($v > 0) $flat[] = [$v, $d, $hr];
    rsort($flat);
    if ($flat) {
        $out .= '<p class="viz-note">Busiest: ' . e(implode(', ', array_map(fn($x) => $days[$x[1]] . ' ' . sprintf('%02d:00', $x[2]), array_slice($flat, 0, 3)))) . '.</p>';
    }
    return $out . viz_once();
}

/** A titled chart card. */
function viz_card(string $title, string $body, string $sub = '', string $id = '', string $extra = ''): string
{
    return '<section class="card viz-card"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '><header class="viz-card-h"><div><h2>' . e($title) . '</h2>'
        . ($sub !== '' ? '<p>' . e($sub) . '</p>' : '') . '</div>' . $extra . '</header>' . $body . '</section>';
}
