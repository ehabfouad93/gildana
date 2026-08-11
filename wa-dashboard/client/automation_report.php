<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid  = (int) $CLIENT['id'];
$fid  = (int) ($_GET['id'] ?? 0);
$flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='bot'", [$fid, $cid]);
if (!$flow) { http_response_code(404); exit('Automation not found.'); }

if (($_GET['export'] ?? '') === 'runs') {
    $rows = db_all("SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE r.flow_id=? ORDER BY r.id DESC", [$fid]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="automation-' . $fid . '-runs.csv"');
    echo "\xEF\xBB\xBF"; echo "phone,name,status,score,grade,created\n";
    foreach ($rows as $r) echo implode(',', array_map('csv_cell', [(string) $r['phone_e164'], (string) $r['name'], (string) $r['status'], (string) (int) $r['score'], (string) ($r['grade'] ?? ''), (string) $r['created_at']])) . "\n";
    exit;
}

$counts = db_row("SELECT COUNT(*) total, SUM(status='completed') completed, SUM(status IN ('waiting_input','waiting_timer','active')) active, SUM(status='blocked') blocked FROM flow_runs WHERE flow_id=?", [$fid]) ?: [];
$msg = db_row("SELECT COUNT(*) sends, SUM(status IN ('delivered','read')) delivered, SUM(status='read') `read`, SUM(status='failed') failed FROM flow_messages WHERE flow_id=?", [$fid]) ?: [];
$perStep = db_all(
    "SELECT s.type, m.step_id, COUNT(*) sends, SUM(m.status IN ('delivered','read')) delivered, SUM(m.status='read') `read`
       FROM flow_messages m LEFT JOIN flow_steps s ON s.id=m.step_id
      WHERE m.flow_id=? GROUP BY m.step_id ORDER BY m.step_id", [$fid]
);
$runs = db_all("SELECT r.*, c.phone_e164, c.name FROM flow_runs r JOIN contacts c ON c.id=r.contact_id WHERE r.flow_id=? ORDER BY r.id DESC LIMIT 100", [$fid]);

$actions = '<a class="btn btn-ghost btn-sm" href="automation_edit.php?id=' . $fid . '">Edit</a><a class="btn btn-ghost btn-sm" href="automation_report.php?id=' . $fid . '&export=runs">Export CSV</a>';
client_header('Report · ' . $flow['name'], 'automations', $CLIENT);
page_head('Report — ' . $flow['name'], $actions);
?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Runs</span><span class="val accent"><?= (int) ($counts['total'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Completed</span><span class="val"><?= (int) ($counts['completed'] ?? 0) ?></span><span class="sub"><?= (int) ($counts['active'] ?? 0) ?> in progress</span></div>
  <div class="stat-tile"><span class="lbl">Messages sent</span><span class="val"><?= (int) ($msg['sends'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Delivered</span><span class="val"><?= (int) ($msg['delivered'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Blocked</span><span class="val <?= (int) ($counts['blocked'] ?? 0) ? 'danger' : '' ?>"><?= (int) ($counts['blocked'] ?? 0) ?></span></div>
</div>

<?php if ($perStep): ?>
<div class="card card-flush">
  <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Per-step performance</h2></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Step</th><th>Sent</th><th>Delivered</th><th>Read</th></tr></thead>
    <tbody><?php foreach ($perStep as $p): ?>
      <tr><td><?= e((string) ($p['type'] ?? 'step')) ?></td><td><?= (int) $p['sends'] ?></td><td><?= (int) $p['delivered'] ?></td><td><?= (int) $p['read'] ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="card card-flush">
  <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Recent runs</h2></div>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Phone</th><th>Name</th><th>Status</th><th>Score</th><th>Created</th></tr></thead>
    <tbody>
    <?php if (!$runs): ?><tr><td colspan="5"><div class="empty">No runs yet.</div></td></tr><?php endif; ?>
    <?php foreach ($runs as $r): ?>
      <tr>
        <td class="mono">+<?= e((string) $r['phone_e164']) ?></td>
        <td><?= e((string) $r['name']) ?: '—' ?></td>
        <td><?= msg_status_pill($r['status'] === 'waiting_input' || $r['status'] === 'waiting_timer' ? 'sending' : ($r['status'] === 'completed' ? 'delivered' : ($r['status'] === 'blocked' ? 'failed' : 'sent'))) ?></td>
        <td><?= (int) $r['score'] ?></td>
        <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $r['created_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php layout_footer(); ?>
