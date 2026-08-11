<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$clientFilter = (int) ($_GET['client'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));

$where = '1=1'; $params = [];
if ($clientFilter) { $where .= ' AND ct.client_id = ?'; $params[] = $clientFilter; }
if ($q !== '') { $where .= ' AND (ct.phone_e164 LIKE ? OR ct.name LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }

$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50; $off = ($page - 1) * $per;
$total = (int) db_val("SELECT COUNT(*) FROM contacts ct WHERE $where", $params);
$contacts = db_all(
    "SELECT ct.*, cl.name AS client_name FROM contacts ct
       JOIN clients cl ON cl.id = ct.client_id
      WHERE $where ORDER BY ct.id DESC LIMIT $per OFFSET $off", $params
);
$pages = (int) max(1, ceil($total / $per));
$clients = db_all("SELECT id, name, (SELECT COUNT(*) FROM contacts c2 WHERE c2.client_id=clients.id) AS n FROM clients ORDER BY name");

layout_header('Contacts', 'admin', 'contacts');
page_head('All Contacts');
?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Total Contacts</span><span class="val accent"><?= number_format((int) db_val("SELECT COUNT(*) FROM contacts")) ?></span></div>
  <div class="stat-tile"><span class="lbl">Opted In</span><span class="val"><?= number_format((int) db_val("SELECT COUNT(*) FROM contacts WHERE opt_in_status='in'")) ?></span></div>
  <div class="stat-tile"><span class="lbl">Opted Out</span><span class="val danger"><?= number_format((int) db_val("SELECT COUNT(*) FROM contacts WHERE opt_in_status='out'")) ?></span></div>
  <div class="stat-tile"><span class="lbl">Clients</span><span class="val"><?= count($clients) ?></span></div>
</div>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;max-width:560px;width:100%">
      <select name="client" onchange="this.form.submit()">
        <option value="0">All clients</option>
        <?php foreach ($clients as $cl): ?><option value="<?= (int) $cl['id'] ?>" <?= $clientFilter === (int) $cl['id'] ? 'selected' : '' ?>><?= e((string) $cl['name']) ?> (<?= (int) $cl['n'] ?>)</option><?php endforeach; ?>
      </select>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search number or name…" style="flex:1;min-width:160px">
      <button class="btn btn-ghost btn-sm" type="submit">Search</button>
    </form>
    <span class="text-muted" style="font-size:12.5px"><?= number_format($total) ?> shown</span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Phone</th><th>Name</th><th>Client</th><th>Status</th><th>Added</th></tr></thead>
      <tbody>
      <?php if (!$contacts): ?><tr><td colspan="5"><div class="empty">No contacts match.</div></td></tr><?php endif; ?>
      <?php foreach ($contacts as $ct): ?>
        <tr>
          <td class="mono">+<?= e((string) $ct['phone_e164']) ?></td>
          <td><?= e((string) $ct['name']) ?: '<span class="text-muted">—</span>' ?></td>
          <td><a class="btn-link" href="client.php?id=<?= (int) $ct['client_id'] ?>"><?= e((string) $ct['client_name']) ?></a></td>
          <td><?= $ct['opt_in_status'] === 'out' ? '<span class="pill red">Opted out</span>' : '<span class="pill green">Opted in</span>' ?></td>
          <td class="text-muted"><?= e(date('d M Y', strtotime((string) $ct['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div style="padding:14px 18px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
      <?php for ($p = 1; $p <= min($pages, 30); $p++): ?>
        <a class="btn <?= $p === $page ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="contacts.php?page=<?= $p ?><?= $clientFilter ? '&client=' . $clientFilter : '' ?><?= $q ? '&q=' . urlencode($q) : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php layout_footer(); ?>
