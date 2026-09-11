<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * The client-facing report: a date-ranged summary that prints cleanly.
 * The print stylesheet hides the app chrome, so "Print → Save as PDF" produces
 * a shareable document without a PDF library on the host.
 */

$cid   = (int) $CLIENT['id'];
$range = metrics_range((string) ($_GET['range'] ?? '30'));

$totals    = metrics_totals($cid, $range);
$series    = metrics_timeseries($cid, $range);
$byConn    = metrics_by_connector($cid, $range, 12);
$byKeyword = metrics_by_keyword($cid, $range, 12);
$byDomain  = metrics_top_domains($cid, $range, 10);

$rangeForm = '<form method="get" class="filter-row">'
    . '<select name="range" data-autosubmit>';
foreach (['7' => t('ui.last_7'), '30' => t('ui.last_30'), '90' => t('ui.last_90')] as $v => $label) {
    $sel = (string) $range['days'] === $v ? ' selected' : '';
    $rangeForm .= '<option value="' . e($v) . '"' . $sel . '>' . e($label) . '</option>';
}
$rangeForm .= '</select></form>'
    . '<a class="btn btn-sm" href="mentions.php?export=1&amp;from=' . e(substr($range['from'], 0, 10))
    . '&amp;to=' . e(substr($range['to'], 0, 10)) . '">' . e(t('ui.export_csv')) . '</a>'
    . '<button class="btn btn-sm btn-primary" onclick="window.print()">' . e(t('ui.print')) . '</button>';

client_header(t('nav.reports'), 'reports', $CLIENT);
page_head(t('rp.title'),
    t('rp.generated', ['date' => date('Y-m-d H:i')]) . ' · ' . t('ui.last_' . $range['days']),
    $rangeForm);
?>

<div class="card">
  <h2><?= e((string) ($CLIENT['brand_name'] ?: $CLIENT['name'])) ?> — <?= e(t('rp.summary')) ?></h2>
  <div class="stats-row" style="margin-bottom:0">
    <?= stat_tile(t('dash.total'),    fmt_num($totals['total']), 'accent') ?>
    <?= stat_tile(t('dash.positive'), fmt_num($totals['positive']), 'good') ?>
    <?= stat_tile(t('dash.negative'), fmt_num($totals['negative']), 'danger') ?>
    <?= stat_tile(t('dash.neutral'),  fmt_num($totals['neutral'])) ?>
    <?= stat_tile(t('dash.net'),      ($totals['net'] > 0 ? '+' : '') . $totals['net'] . '%',
                  $totals['net'] >= 0 ? 'good' : 'danger') ?>
    <?= stat_tile(t('dash.reach'),    fmt_num($totals['reach'])) ?>
  </div>
</div>

<div class="card">
  <h2><?= e(t('dash.volume')) ?></h2>
  <?= chart_bars($series, t('dash.volume'), 210) ?>
</div>

<div class="grid2">
  <div class="card">
    <h2><?= e(t('dash.split')) ?></h2>
    <?= chart_donut([
        t('sent.positive') => ['value' => $totals['positive'], 'color' => CHART_POS],
        t('sent.neutral')  => ['value' => $totals['neutral'] + $totals['unknown'], 'color' => CHART_NEU],
        t('sent.negative') => ['value' => $totals['negative'], 'color' => CHART_NEG],
    ], t('dash.total')) ?>
    <?= chart_legend() ?>
  </div>

  <div class="card card-flush">
    <div class="card-head"><h2><?= e(t('rp.by_source')) ?></h2></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th><?= e(t('src.connector')) ?></th><th><?= e(t('dash.total')) ?></th>
                   <th><?= e(t('sent.negative')) ?></th></tr></thead>
        <tbody>
        <?php if (!$byConn): ?>
          <tr><td colspan="3"><div class="empty"><?= e(t('dash.no_data')) ?></div></td></tr>
        <?php else: foreach ($byConn as $c):
          $meta = listen_connector((string) $c['connector']); ?>
          <tr>
            <td><?= e($meta['label'] ?? (string) $c['connector']) ?></td>
            <td><?= e(fmt_num((int) $c['total'])) ?></td>
            <td><?= e(fmt_num((int) $c['negative'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid2">
  <div class="card card-flush">
    <div class="card-head"><h2><?= e(t('rp.by_keyword')) ?></h2></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th><?= e(t('kw.term')) ?></th><th><?= e(t('kw.kind')) ?></th>
                   <th><?= e(t('dash.total')) ?></th><th>+</th><th>−</th></tr></thead>
        <tbody>
        <?php if (!$byKeyword): ?>
          <tr><td colspan="5"><div class="empty"><?= e(t('dash.no_data')) ?></div></td></tr>
        <?php else: foreach ($byKeyword as $k): ?>
          <tr>
            <td dir="auto"><?= e((string) $k['term']) ?></td>
            <td><span class="pill gray"><?= e(t('kw.kind.' . $k['kind'])) ?></span></td>
            <td><?= e(fmt_num((int) $k['total'])) ?></td>
            <td style="color:var(--success)"><?= e(fmt_num((int) $k['positive'])) ?></td>
            <td style="color:var(--danger)"><?= e(fmt_num((int) $k['negative'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2><?= e(t('dash.top_sources')) ?></h2>
    <?php
    $rows = [];
    foreach ($byDomain as $d) { $rows[] = ['label' => (string) $d['domain'], 'value' => (int) $d['total']]; }
    echo chart_hbars($rows);
    ?>
  </div>
</div>

<?php layout_footer(); ?>
