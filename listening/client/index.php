<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid   = (int) $CLIENT['id'];
$range = metrics_range((string) ($_GET['range'] ?? '30'));

$totals     = metrics_totals($cid, $range);
$series     = metrics_timeseries($cid, $range);
$byConn     = metrics_by_connector($cid, $range);
$byKeyword  = metrics_by_keyword($cid, $range);
$recentNeg  = metrics_recent($cid, 6, 'negative');
$lastFetch  = metrics_last_fetch($cid);
$sourceCount = (int) db_val("SELECT COUNT(*) FROM sources WHERE client_id = ?", [$cid]);
$terms      = array_column(keywords_active($cid), 'term');

$rangeSelect = range_selector((int) $range['days']);

client_header(t('nav.dashboard'), 'dashboard', $CLIENT);
page_head((string) ($CLIENT['brand_name'] ?: $CLIENT['name']), t('app.tagline'), $rangeSelect);

/* Tell the client plainly when nothing is being collected — an empty dashboard
   with no explanation reads as a broken product. */
if ($sourceCount === 0): ?>
  <div class="alert warn"><?= e(t('dash.no_sources')) ?>
    <a href="sources.php"><?= e(t('src.add')) ?> →</a></div>
<?php elseif ($lastFetch !== null && strtotime($lastFetch . ' UTC') < time() - 7200): ?>
  <div class="alert warn"><?= e(t('dash.stale')) ?></div>
<?php endif; ?>

<div class="stats-row">
  <?= stat_tile(t('dash.total'),    fmt_num($totals['total']), 'accent') ?>
  <?= stat_tile(t('dash.positive'), fmt_num($totals['positive']), 'good') ?>
  <?= stat_tile(t('dash.negative'), fmt_num($totals['negative']), 'danger') ?>
  <?= stat_tile(t('dash.neutral'),  fmt_num($totals['neutral'])) ?>
  <?= stat_tile(t('dash.net'),      ($totals['net'] > 0 ? '+' : '') . $totals['net'] . '%',
                $totals['net'] >= 0 ? 'good' : 'danger') ?>
  <?= stat_tile(t('dash.reach'),    fmt_num($totals['reach'])) ?>
</div>

<div class="card">
  <h2><?= e(t('dash.volume')) ?></h2>
  <?= chart_bars($series, t('dash.volume')) ?>
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

  <div class="card">
    <h2><?= e(t('dash.top_sources')) ?></h2>
    <?php
    $rows = [];
    foreach ($byConn as $c) {
        $meta = listen_connector((string) $c['connector']);
        $rows[] = ['label' => $meta['label'] ?? (string) $c['connector'], 'value' => (int) $c['total']];
    }
    echo chart_hbars($rows);
    ?>
  </div>
</div>

<div class="grid2">
  <div class="card">
    <h2><?= e(t('dash.top_keywords')) ?></h2>
    <?php
    $rows = [];
    foreach ($byKeyword as $k) {
        $rows[] = ['label' => (string) $k['term'], 'value' => (int) $k['total']];
    }
    echo chart_hbars($rows);
    ?>
  </div>

  <div class="card card-flush">
    <div class="card-head">
      <h2><?= e(t('dash.latest_negative')) ?></h2>
      <a class="btn btn-sm btn-ghost" href="mentions.php?sentiment=negative"><?= e(t('nav.mentions')) ?> →</a>
    </div>
    <?php if (!$recentNeg): ?>
      <div class="empty"><?= e(t('dash.no_data')) ?></div>
    <?php else: ?>
      <?php foreach ($recentNeg as $m) { echo mention_card($m, $terms, true); } ?>
    <?php endif; ?>
  </div>
</div>

<?php layout_footer(); ?>
