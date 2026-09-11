<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * Source health across every client — the operations screen. When a client says
 * "I'm not seeing anything", this page answers why.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if ((string) ($_POST['action'] ?? '') === 'reactivate') {
        $sid = (int) ($_POST['id'] ?? 0);
        db_run(
            "UPDATE sources
                SET status='active', consecutive_failures=0, last_error='', next_fetch_at=NOW()
              WHERE id=?",
            [$sid]
        );
        flash(t('ui.saved'));
        redirect('health.php');
    }
    if ((string) ($_POST['action'] ?? '') === 'reactivate_all') {
        db_run("UPDATE sources SET status='active', consecutive_failures=0, last_error='', next_fetch_at=NOW()
                 WHERE status='error'");
        flash(t('ui.saved'));
        redirect('health.php');
    }
}

$sources = db_all(
    "SELECT s.*, c.name AS client_name, k.term AS keyword_term
       FROM sources s
       JOIN clients c ON c.id = s.client_id
       LEFT JOIN keywords k ON k.id = s.keyword_id
      ORDER BY s.consecutive_failures DESC, s.last_ok_at IS NULL DESC, s.client_id"
);

$runs = db_all(
    "SELECT f.*, c.name AS client_name
       FROM fetch_runs f JOIN clients c ON c.id = f.client_id
      ORDER BY f.id DESC LIMIT 60"
);

$spend = db_all(
    "SELECT connector, SUM(billable) AS units, COUNT(*) AS runs
       FROM fetch_runs
      WHERE started_at >= DATE_FORMAT(NOW(), '%Y-%m-01') AND billable > 0
      GROUP BY connector ORDER BY units DESC"
);

$broken = 0;
foreach ($sources as $s) { if ((string) $s['status'] === 'error') $broken++; }

$action = $broken > 0
    ? '<form method="post">' . csrf_field()
      . '<input type="hidden" name="action" value="reactivate_all">'
      . '<button class="btn btn-sm">Re-enable all failed</button></form>'
    : '';

layout_header(t('nav.health'), 'admin', 'health');
page_head(t('nav.health'), '', $action);

if (!worker_alive()) echo '<div class="alert error">' . e(t('ad.cron_stale')) . '</div>';
?>

<?php if ($spend): ?>
<div class="card">
  <h2>Billable API usage this month</h2>
  <div class="filter-row">
    <?php foreach ($spend as $s): ?>
      <span class="pill gold"><?= e((string) $s['connector']) ?>:
        <?= e(fmt_num((int) $s['units'])) ?> units / <?= (int) $s['runs'] ?> runs</span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card card-flush">
  <div class="card-head"><h2><?= e(t('nav.sources')) ?></h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th><?= e(t('nav.clients')) ?></th><th><?= e(t('src.connector')) ?></th>
            <th><?= e(t('kw.term')) ?></th><th><?= e(t('ui.status')) ?></th>
            <th>Fails</th><th><?= e(t('src.last_fetch')) ?></th>
            <th><?= e(t('src.next_fetch')) ?></th><th><?= e(t('ui.error')) ?></th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$sources): ?>
        <tr><td colspan="9"><div class="empty"><?= e(t('ui.empty')) ?></div></td></tr>
      <?php else: foreach ($sources as $s):
        $meta = listen_connector((string) $s['connector']); ?>
        <tr>
          <td><a href="client.php?id=<?= (int) $s['client_id'] ?>"><?= e((string) $s['client_name']) ?></a></td>
          <td><?= e($meta['label'] ?? (string) $s['connector']) ?></td>
          <td class="text-muted" dir="auto"><?= e((string) ($s['keyword_term'] ?? t('ui.all'))) ?></td>
          <td><?= status_pill((string) $s['status'] === 'active' ? (string) $s['last_status'] : (string) $s['status']) ?></td>
          <td <?= (int) $s['consecutive_failures'] > 0 ? 'style="color:var(--danger);font-weight:600"' : '' ?>>
            <?= (int) $s['consecutive_failures'] ?></td>
          <td class="text-muted nowrap"><?= e(time_ago((string) $s['last_ok_at'])) ?></td>
          <td class="text-muted nowrap"><?= e(fmt_dt((string) $s['next_fetch_at'], 'm-d H:i')) ?></td>
          <td class="text-muted" style="max-width:260px"><?= e(excerpt((string) $s['last_error'], 120)) ?></td>
          <td class="nowrap">
            <?php if ((int) $s['consecutive_failures'] > 0): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reactivate">
                <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-sm btn-ghost" type="submit">Retry</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card card-flush">
  <div class="card-head"><h2>Recent fetch runs</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead>
        <tr><th><?= e(t('nav.clients')) ?></th><th><?= e(t('src.connector')) ?></th>
            <th><?= e(t('ui.status')) ?></th><th>HTTP</th><th>Seen</th><th>New</th>
            <th>Filtered</th><th>ms</th><th><?= e(t('ui.created')) ?></th></tr>
      </thead>
      <tbody>
      <?php if (!$runs): ?>
        <tr><td colspan="9"><div class="empty"><?= e(t('ui.empty')) ?></div></td></tr>
      <?php else: foreach ($runs as $r): ?>
        <tr>
          <td class="text-muted"><?= e((string) $r['client_name']) ?></td>
          <td><?= e((string) $r['connector']) ?></td>
          <td><?= status_pill((string) $r['status']) ?></td>
          <td class="text-muted"><?= (int) $r['http_code'] ?></td>
          <td><?= (int) $r['items_seen'] ?></td>
          <td><strong><?= (int) $r['items_new'] ?></strong></td>
          <td class="text-muted"><?= (int) $r['items_filtered'] ?></td>
          <td class="text-muted"><?= (int) $r['duration_ms'] ?></td>
          <td class="text-muted nowrap"><?= e(time_ago((string) $r['started_at'])) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer(); ?>
