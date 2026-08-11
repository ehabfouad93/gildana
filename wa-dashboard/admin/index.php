<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$clientCount   = (int) db_val("SELECT COUNT(*) FROM clients");
$activeClients = (int) db_val("SELECT COUNT(*) FROM clients WHERE status='active'");
$campaignCount = (int) db_val("SELECT COUNT(*) FROM campaigns");
$sentTotal     = (int) db_val("SELECT COALESCE(SUM(sent_count),0) FROM campaigns");
$lowCredit     = db_all("SELECT id,name,credits_balance FROM clients WHERE status='active' AND credits_balance < 100 ORDER BY credits_balance ASC LIMIT 8");

$recent = db_all(
    "SELECT c.id, c.name, c.status, c.total_count, c.sent_count, c.created_at, cl.name AS client_name
       FROM campaigns c JOIN clients cl ON cl.id = c.client_id
       ORDER BY c.id DESC LIMIT 8"
);

layout_header('Overview', 'admin', 'overview');
page_head('Overview');
?>

<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Clients</span><span class="val accent"><?= $clientCount ?></span><span class="sub"><?= $activeClients ?> active</span></div>
  <div class="stat-tile"><span class="lbl">Campaigns</span><span class="val"><?= $campaignCount ?></span><span class="sub">all time</span></div>
  <div class="stat-tile"><span class="lbl">Messages Sent</span><span class="val"><?= number_format($sentTotal) ?></span><span class="sub">accepted by API</span></div>
  <div class="stat-tile"><span class="lbl">Low Credit</span><span class="val <?= $lowCredit ? 'danger' : '' ?>"><?= count($lowCredit) ?></span><span class="sub">clients &lt; 100</span></div>
</div>

<div class="card card-flush">
  <div style="padding:16px 22px" class="row-between">
    <h2 style="border:0;padding:0;margin:0">Recent Campaigns</h2>
    <a class="btn btn-ghost btn-sm" href="clients.php">Manage Clients →</a>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Campaign</th><th>Client</th><th>Status</th><th>Progress</th><th>Created</th></tr></thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="5"><div class="empty">No campaigns yet.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><?= e($c['client_name']) ?></td>
          <td><?= status_pill((string) $c['status']) ?></td>
          <td><?= (int) $c['sent_count'] ?> / <?= (int) $c['total_count'] ?></td>
          <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $c['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($lowCredit): ?>
<div class="card">
  <h2>Low-credit clients</h2>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Client</th><th>Balance</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($lowCredit as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><span class="pill red"><?= (int) $c['credits_balance'] ?> credits</span></td>
          <td style="text-align:right"><a class="btn-link" href="client.php?id=<?= (int) $c['id'] ?>#credits">Top up →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layout_footer();
