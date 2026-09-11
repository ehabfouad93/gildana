<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$clients = db_all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM mentions m
              WHERE m.client_id = c.id AND m.fetched_at >= CURDATE()) AS today,
            (SELECT COUNT(*) FROM mentions m
              WHERE m.client_id = c.id AND m.sentiment = 'negative'
                AND m.fetched_at >= CURDATE()) AS today_neg,
            (SELECT COUNT(*) FROM sources s
              WHERE s.client_id = c.id AND s.status = 'error') AS bad_sources,
            (SELECT MAX(s.last_ok_at) FROM sources s WHERE s.client_id = c.id) AS last_ok
       FROM clients c
      ORDER BY c.status = 'active' DESC, c.name"
);

$totalToday  = (int) db_val("SELECT COUNT(*) FROM mentions WHERE fetched_at >= CURDATE()");
$negToday    = (int) db_val("SELECT COUNT(*) FROM mentions WHERE sentiment='negative' AND fetched_at >= CURDATE()");
$badSources  = (int) db_val("SELECT COUNT(*) FROM sources WHERE status='error'");
$pendingAi   = (int) db_val("SELECT COUNT(*) FROM mentions WHERE classify_state='needs_ai'");

layout_header(t('nav.overview'), 'admin', 'overview');
page_head(t('nav.overview'), t('app.tagline'));

if (!worker_alive()) {
    echo '<div class="alert error">' . e(t('ad.cron_stale')) . '</div>';
}
?>

<div class="stats-row">
  <?= stat_tile(t('ad.clients'),        fmt_num(count($clients)), 'accent') ?>
  <?= stat_tile(t('ad.mentions_today'), fmt_num($totalToday)) ?>
  <?= stat_tile(t('ad.negative_today'), fmt_num($negToday), $negToday > 0 ? 'danger' : '') ?>
  <?= stat_tile(t('ad.sources_error'),  fmt_num($badSources), $badSources > 0 ? 'danger' : '') ?>
  <?= stat_tile('AI queue',             fmt_num($pendingAi)) ?>
</div>

<div class="card card-flush">
  <div class="card-head">
    <h2><?= e(t('ad.clients')) ?></h2>
    <a class="btn btn-sm btn-primary" href="clients.php"><?= e(t('ad.add_client')) ?></a>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr>
          <th><?= e(t('ui.name')) ?></th>
          <th><?= e(t('ad.mentions_today')) ?></th>
          <th><?= e(t('ad.negative_today')) ?></th>
          <th><?= e(t('src.last_fetch')) ?></th>
          <th><?= e(t('ui.status')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$clients): ?>
        <tr><td colspan="6"><div class="empty"><?= e(t('ui.empty')) ?></div></td></tr>
      <?php else: foreach ($clients as $c): ?>
        <tr>
          <td>
            <strong><?= e((string) $c['name']) ?></strong>
            <?php if ((string) $c['brand_name'] !== '' && $c['brand_name'] !== $c['name']): ?>
              <br><span class="text-muted" style="font-size:11.5px"><?= e((string) $c['brand_name']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= e(fmt_num((int) $c['today'])) ?></td>
          <td <?= (int) $c['today_neg'] > 0 ? 'style="color:var(--danger);font-weight:600"' : '' ?>>
            <?= e(fmt_num((int) $c['today_neg'])) ?></td>
          <td class="text-muted nowrap"><?= e(time_ago((string) $c['last_ok'])) ?></td>
          <td>
            <?= status_pill((string) $c['status'] === 'active' ? 'active' : 'paused') ?>
            <?php if ((int) $c['bad_sources'] > 0): ?>
              <span class="pill red"><?= (int) $c['bad_sources'] ?> <?= e(t('ui.error')) ?></span>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <a class="btn btn-sm btn-ghost" href="client.php?id=<?= (int) $c['id'] ?>"><?= e(t('ui.edit')) ?></a>
            <a class="btn btn-sm" href="open_workspace.php?id=<?= (int) $c['id'] ?>&amp;csrf=<?= e(csrf_token()) ?>">
              <?= e(t('ad.open_ws')) ?> →</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer(); ?>
