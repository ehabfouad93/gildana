<?php
declare(strict_types=1);

/**
 * Charts as inline SVG built in PHP.
 *
 * No charting library and no build step — the platform has neither, and a
 * server-rendered SVG also prints correctly (the reports page relies on the
 * browser's Print-to-PDF) and needs no JavaScript to appear.
 *
 * Every chart carries a <title> for screen readers and is direction-aware, so
 * an Arabic RTL layout reads right-to-left without a second implementation.
 */

const CHART_POS = '#2f7d32';
const CHART_NEG = '#c0392b';
const CHART_NEU = '#9aa4b0';
const CHART_ACC = '#c4973a';

/**
 * Stacked daily volume: negative on the bottom, then neutral, then positive.
 *
 * @param array $days rows of ['day','positive','negative','neutral']
 */
function chart_bars(array $days, string $title = '', int $height = 190): string
{
    if (!$days) return '<div class="empty">' . e(t('dash.no_data')) . '</div>';

    $w      = 720;
    $h      = $height;
    $padB   = 26;   // room for date labels
    $padT   = 8;
    $inner  = $h - $padB - $padT;
    $n      = count($days);
    $slot   = $w / max(1, $n);
    $barW   = max(2.0, min(26.0, $slot * 0.62));

    $max = 1;
    foreach ($days as $d) {
        $max = max($max, (int) $d['positive'] + (int) $d['negative'] + (int) $d['neutral']);
    }

    $rtl  = is_rtl();
    $bars = '';
    $ticks = '';
    $labelEvery = (int) max(1, ceil($n / 8));

    foreach ($days as $i => $d) {
        // In RTL the timeline runs right to left, so mirror the x position.
        $slotIndex = $rtl ? ($n - 1 - $i) : $i;
        $x  = $slotIndex * $slot + ($slot - $barW) / 2;
        $y  = $h - $padB;

        foreach ([['negative', CHART_NEG], ['neutral', CHART_NEU], ['positive', CHART_POS]] as [$key, $color]) {
            $v = (int) $d[$key];
            if ($v <= 0) continue;
            $bh = ($v / $max) * $inner;
            $y -= $bh;
            $bars .= sprintf(
                '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="%s" rx="1.5"><title>%s — %s: %d</title></rect>',
                $x, $y, $barW, $bh, $color, e((string) $d['day']), e(t('sent.' . $key)), $v
            );
        }

        if ($i % $labelEvery === 0) {
            $ticks .= sprintf(
                '<text x="%.2f" y="%d" font-size="10" fill="#8a8578" text-anchor="middle">%s</text>',
                $slotIndex * $slot + $slot / 2, $h - 8, e(date('j M', strtotime((string) $d['day']) ?: time()))
            );
        }
    }

    $baseline = sprintf('<line x1="0" y1="%d" x2="%d" y2="%d" stroke="rgba(75,54,33,.16)" stroke-width="1"/>',
        $h - $padB, $w, $h - $padB);

    return '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($title) . '">'
         . ($title !== '' ? '<title>' . e($title) . '</title>' : '')
         . $baseline . $bars . $ticks
         . '</svg>' . chart_legend();
}

/**
 * Sentiment donut with the total in the middle.
 *
 * @param array $slices [label => ['value'=>int,'color'=>string]]
 */
function chart_donut(array $slices, string $centerLabel = '', int $size = 190): string
{
    $total = 0;
    foreach ($slices as $s) { $total += (int) $s['value']; }
    if ($total <= 0) return '<div class="empty">' . e(t('dash.no_data')) . '</div>';

    $r      = $size / 2 - 14;
    $c      = $size / 2;
    $stroke = 26;
    $circ   = 2 * M_PI * $r;

    $offset = 0.0;
    $arcs   = '';
    foreach ($slices as $label => $s) {
        $v = (int) $s['value'];
        if ($v <= 0) continue;
        $len = ($v / $total) * $circ;
        $arcs .= sprintf(
            '<circle cx="%.1f" cy="%.1f" r="%.1f" fill="none" stroke="%s" stroke-width="%d"'
            . ' stroke-dasharray="%.3f %.3f" stroke-dashoffset="%.3f" transform="rotate(-90 %.1f %.1f)">'
            . '<title>%s: %d (%d%%)</title></circle>',
            $c, $c, $r, $s['color'], $stroke, $len, $circ - $len, -$offset, $c, $c,
            e((string) $label), $v, (int) round($v / $total * 100)
        );
        $offset += $len;
    }

    $mid = sprintf(
        '<text x="%.1f" y="%.1f" text-anchor="middle" font-size="26" font-weight="650" fill="#2a221a">%s</text>',
        $c, $c + 4, e(fmt_num($total))
    );
    if ($centerLabel !== '') {
        $mid .= sprintf(
            '<text x="%.1f" y="%.1f" text-anchor="middle" font-size="10" fill="#8a8578">%s</text>',
            $c, $c + 20, e($centerLabel)
        );
    }

    return '<svg class="chart" viewBox="0 0 ' . $size . ' ' . $size . '" style="max-width:' . $size . 'px;margin:0 auto"'
         . ' role="img" aria-label="' . e($centerLabel) . '">' . $arcs . $mid . '</svg>';
}

/**
 * Horizontal bars for rankings (top sources, top keywords).
 *
 * @param array $rows [['label'=>string,'value'=>int], …]
 */
function chart_hbars(array $rows, string $title = ''): string
{
    $rows = array_values(array_filter($rows, function ($r) { return (int) $r['value'] > 0; }));
    if (!$rows) return '<div class="empty">' . e(t('dash.no_data')) . '</div>';

    $max  = 1;
    foreach ($rows as $r) { $max = max($max, (int) $r['value']); }

    $out = '<div class="hbars">';
    foreach ($rows as $r) {
        $pct = ((int) $r['value'] / $max) * 100;
        $out .= '<div class="hbar-row">'
              . '<span class="hbar-label" title="' . e((string) $r['label']) . '">' . e((string) $r['label']) . '</span>'
              . '<span class="hbar-track"><span class="hbar-fill" style="width:' . round($pct, 1) . '%"></span></span>'
              . '<span class="hbar-val">' . e(fmt_num((int) $r['value'])) . '</span>'
              . '</div>';
    }
    return $out . '</div>';
}

/** Shared legend for the sentiment charts. */
function chart_legend(): string
{
    return '<div class="chart-legend">'
         . '<span><i style="background:' . CHART_POS . '"></i>' . e(t('sent.positive')) . '</span>'
         . '<span><i style="background:' . CHART_NEU . '"></i>' . e(t('sent.neutral')) . '</span>'
         . '<span><i style="background:' . CHART_NEG . '"></i>' . e(t('sent.negative')) . '</span>'
         . '</div>';
}
