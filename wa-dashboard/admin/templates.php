<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$clientFilter = (int) ($_GET['client'] ?? 0);
$where = '1=1'; $params = [];
if ($clientFilter) { $where .= ' AND t.client_id = ?'; $params[] = $clientFilter; }

$templates = db_all(
    "SELECT t.*, cl.name AS client_name FROM templates t
       JOIN clients cl ON cl.id = t.client_id
      WHERE $where ORDER BY cl.name, t.status='APPROVED' DESC, t.wa_name LIMIT 500", $params
);
$clients = db_all("SELECT id, name FROM clients ORDER BY name");

layout_header('Templates', 'admin', 'templates');
page_head('All Templates');
?>
<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <form method="get">
      <select name="client" onchange="this.form.submit()">
        <option value="0">All clients</option>
        <?php foreach ($clients as $cl): ?><option value="<?= (int) $cl['id'] ?>" <?= $clientFilter === (int) $cl['id'] ? 'selected' : '' ?>><?= e((string) $cl['name']) ?></option><?php endforeach; ?>
      </select>
    </form>
    <span class="text-muted" style="font-size:12.5px"><?= count($templates) ?> template<?= count($templates) === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Template</th><th>Client</th><th>Language</th><th>Category</th><th>Vars</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$templates): ?><tr><td colspan="6"><div class="empty">No templates synced yet.</div></td></tr><?php endif; ?>
      <?php foreach ($templates as $t):
        $stCls = $t['status'] === 'APPROVED' ? 'green' : ($t['status'] === 'REJECTED' ? 'red' : 'gold');
      ?>
        <tr>
          <td><strong><?= e((string) $t['wa_name']) ?></strong></td>
          <td><a class="btn-link" href="client.php?id=<?= (int) $t['client_id'] ?>"><?= e((string) $t['client_name']) ?></a></td>
          <td class="text-muted"><?= e((string) $t['language']) ?></td>
          <td class="text-muted"><?= e((string) $t['category']) ?: '—' ?></td>
          <td><?= (int) $t['variable_count'] ?></td>
          <td><span class="pill <?= $stCls ?>"><?= e(ucfirst(strtolower((string) $t['status']))) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_footer(); ?>
