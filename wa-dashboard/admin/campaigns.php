<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$clientFilter = (int) ($_GET['client'] ?? 0);
$statusFilter = (string) ($_GET['status'] ?? '');

$where = '1=1'; $params = [];
if ($clientFilter) { $where .= ' AND c.client_id = ?'; $params[] = $clientFilter; }
if (in_array($statusFilter, ['draft','scheduled','sending','completed','failed','paused','canceled'], true)) { $where .= ' AND c.status = ?'; $params[] = $statusFilter; }

$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 40; $off = ($page - 1) * $per;
$total = (int) db_val("SELECT COUNT(*) FROM campaigns c WHERE $where", $params);
$campaigns = db_all(
    "SELECT c.*, cl.name AS client_name, t.wa_name AS template_name
       FROM campaigns c
       JOIN clients cl ON cl.id = c.client_id
       LEFT JOIN templates t ON t.id = c.template_id
      WHERE $where ORDER BY c.id DESC LIMIT $per OFFSET $off", $params
);
$pages = (int) max(1, ceil($total / $per));
$clients = db_all("SELECT id, name FROM clients ORDER BY name");

layout_header('Campaigns', 'admin', 'campaigns');
page_head('All Campaigns');
?>
<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
      <select name="client" onchange="this.form.submit()">
        <option value="0">All clients</option>
        <?php foreach ($clients as $cl): ?><option value="<?= (int) $cl['id'] ?>" <?= $clientFilter === (int) $cl['id'] ? 'selected' : '' ?>><?= e((string) $cl['name']) ?></option><?php endforeach; ?>
      </select>
      <select name="status" onchange="this.form.submit()">
        <?php foreach (['' => 'Any status','sending' => 'Sending','scheduled' => 'Scheduled','completed' => 'Completed','failed' => 'Failed','paused' => 'Paused','canceled' => 'Canceled'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $statusFilter === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <span class="text-muted" style="font-size:12.5px"><?= number_format($total) ?> campaign<?= $total === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Campaign</th><th>Client</th><th>Template</th><th>Status</th><th>Sent</th><th>Delivered</th><th>Failed</th><th>Created</th></tr></thead>
      <tbody>
      <?php if (!$campaigns): ?><tr><td colspan="8"><div class="empty">No campaigns match.</div></td></tr><?php endif; ?>
      <?php foreach ($campaigns as $c): ?>
        <tr>
          <td><strong><?= e((string) $c['name']) ?></strong></td>
          <td><a class="btn-link" href="client.php?id=<?= (int) $c['client_id'] ?>"><?= e((string) $c['client_name']) ?></a></td>
          <td class="text-muted"><?= e((string) ($c['template_name'] ?? '—')) ?></td>
          <td><?= status_pill((string) $c['status']) ?></td>
          <td><?= (int) $c['sent_count'] ?>/<?= (int) $c['total_count'] ?></td>
          <td><?= (int) $c['delivered_count'] ?></td>
          <td><?= (int) $c['failed_count'] ? '<span class="pill red">' . (int) $c['failed_count'] . '</span>' : '0' ?></td>
          <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $c['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div style="padding:14px 18px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
      <?php for ($p = 1; $p <= min($pages, 30); $p++): ?>
        <a class="btn <?= $p === $page ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="campaigns.php?page=<?= $p ?><?= $clientFilter ? '&client=' . $clientFilter : '' ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>
