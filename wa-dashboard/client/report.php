<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];
$id  = (int) ($_GET['id'] ?? 0);
$camp = db_row(
    "SELECT c.*, t.wa_name AS template_name, l.name AS list_name
       FROM campaigns c LEFT JOIN templates t ON t.id=c.template_id
       LEFT JOIN contact_lists l ON l.id=c.list_id
      WHERE c.id=? AND c.client_id=?", [$id, $cid]
);
if (!$camp) { http_response_code(404); exit('Campaign not found.'); }

/* ── CSV export of per-message results ── */
if (($_GET['export'] ?? '') === '1') {
    $rows = db_all("SELECT phone_e164,status,wa_message_id,error_title,sent_at,delivered_at,read_at FROM campaign_messages WHERE campaign_id=? ORDER BY id", [$id]);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="campaign-' . $id . '-' . date('Y-m-d') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo "phone,status,wa_message_id,error,sent_at,delivered_at,read_at\n";
    foreach ($rows as $r) {
        echo implode(',', array_map('csv_cell', [
            (string) $r['phone_e164'], (string) $r['status'], (string) $r['wa_message_id'], (string) $r['error_title'],
            (string) $r['sent_at'], (string) $r['delivered_at'], (string) $r['read_at'],
        ])) . "\n";
    }
    exit;
}

/* ── live counters (JSON for polling) ── */
if (($_GET['stats'] ?? '') === '1') {
    $c = db_row("SELECT status,total_count,sent_count,delivered_count,read_count,failed_count FROM campaigns WHERE id=? AND client_id=?", [$id, $cid]);
    $queued = (int) db_val("SELECT COUNT(*) FROM campaign_messages WHERE campaign_id=? AND status IN ('queued','sending')", [$id]);
    $c['queued'] = $queued;
    json_out($c);
}

$counts = [
    'total'     => (int) $camp['total_count'],
    'sent'      => (int) $camp['sent_count'],
    'delivered' => (int) $camp['delivered_count'],
    'read'      => (int) $camp['read_count'],
    'failed'    => (int) $camp['failed_count'],
];
$queued = (int) db_val("SELECT COUNT(*) FROM campaign_messages WHERE campaign_id=? AND status IN ('queued','sending')", [$id]);

// per-message list (paged)
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50; $off = ($page - 1) * $per;
$statusFilter = (string) ($_GET['status'] ?? '');
$where = "campaign_id=?"; $params = [$id];
if (in_array($statusFilter, ['queued','sending','sent','delivered','read','failed'], true)) { $where .= " AND status=?"; $params[] = $statusFilter; }
$msgTotal = (int) db_val("SELECT COUNT(*) FROM campaign_messages WHERE $where", $params);
$messages = db_all("SELECT * FROM campaign_messages WHERE $where ORDER BY id DESC LIMIT $per OFFSET $off", $params);
$pages = (int) max(1, ceil($msgTotal / $per));

$actions = '<a class="btn btn-ghost btn-sm" href="report.php?id=' . $id . '&export=1">Export CSV</a><a class="btn btn-ghost btn-sm" href="campaigns.php">← Campaigns</a>';
client_header('Report · ' . $camp['name'], 'campaigns', $CLIENT);
?>
<div class="page-head">
  <h1><?= e((string) $camp['name']) ?> <span id="status-pill"><?= status_pill((string) $camp['status']) ?></span></h1>
  <div class="page-actions"><?= $actions ?></div>
</div>

<p class="text-muted" style="margin:-10px 0 20px;font-size:13px">
  Template <strong><?= e((string) ($camp['template_name'] ?? '—')) ?></strong> ·
  List <strong><?= e((string) ($camp['list_name'] ?? '—')) ?></strong> ·
  Created <?= e(date('d M Y, H:i', strtotime((string) $camp['created_at']))) ?>
</p>

<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Total</span><span class="val" id="s-total"><?= $counts['total'] ?></span></div>
  <div class="stat-tile"><span class="lbl">Sent</span><span class="val" id="s-sent"><?= $counts['sent'] ?></span><span class="sub" id="s-queued"><?= $queued ?> pending</span></div>
  <div class="stat-tile"><span class="lbl">Delivered</span><span class="val" id="s-delivered"><?= $counts['delivered'] ?></span></div>
  <div class="stat-tile"><span class="lbl">Read</span><span class="val" id="s-read"><?= $counts['read'] ?></span></div>
  <div class="stat-tile"><span class="lbl">Failed</span><span class="val danger" id="s-failed"><?= $counts['failed'] ?></span></div>
</div>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php
      $filters = ['' => 'All', 'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'failed' => 'Failed', 'queued' => 'Pending'];
      foreach ($filters as $val => $lbl):
        $on = ($val === $statusFilter || ($val === '' && $statusFilter === ''));
      ?>
        <a class="btn <?= $on ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="report.php?id=<?= $id ?><?= $val ? '&status=' . $val : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <span class="text-muted" style="font-size:12.5px"><?= number_format($msgTotal) ?> message<?= $msgTotal === 1 ? '' : 's' ?></span>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Phone</th><th>Status</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Error</th></tr></thead>
      <tbody>
      <?php if (!$messages): ?><tr><td colspan="6"><div class="empty">No messages<?= $statusFilter ? ' with this status' : '' ?>.</div></td></tr><?php endif; ?>
      <?php foreach ($messages as $m): ?>
        <tr>
          <td class="mono">+<?= e((string) $m['phone_e164']) ?></td>
          <td><?= msg_status_pill((string) $m['status']) ?></td>
          <td class="text-muted"><?= $m['sent_at'] ? e(date('d M H:i', strtotime((string) $m['sent_at']))) : '—' ?></td>
          <td class="text-muted"><?= $m['delivered_at'] ? e(date('d M H:i', strtotime((string) $m['delivered_at']))) : '—' ?></td>
          <td class="text-muted"><?= $m['read_at'] ? e(date('d M H:i', strtotime((string) $m['read_at']))) : '—' ?></td>
          <td class="text-muted" style="font-size:12px"><?= e((string) ($m['error_title'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div style="padding:14px 18px;display:flex;gap:6px;justify-content:center;flex-wrap:wrap">
      <?php for ($p = 1; $p <= min($pages, 30); $p++): ?>
        <a class="btn <?= $p === $page ? 'btn-dark' : 'btn-ghost' ?> btn-sm" href="report.php?id=<?= $id ?><?= $statusFilter ? '&status=' . $statusFilter : '' ?>&page=<?= $p ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<script>
/* Live-poll counters while the campaign is still active. */
const ACTIVE = <?= in_array($camp['status'], ['sending','scheduled','queued'], true) ? 'true' : 'false' ?>;
if (ACTIVE) {
  const poll = setInterval(async () => {
    try {
      const r = await fetch('report.php?id=<?= $id ?>&stats=1');
      const d = await r.json();
      for (const k of ['total','sent','delivered','read','failed']) {
        const el = document.getElementById('s-'+k); if (el) el.textContent = d[k+'_count'] ?? d[k] ?? el.textContent;
      }
      document.getElementById('s-queued').textContent = (d.queued||0)+' pending';
      if (['completed','failed','canceled'].includes(d.status)) { clearInterval(poll); location.reload(); }
    } catch(e){}
  }, 5000);
}
</script>

<?php layout_footer(); ?>
