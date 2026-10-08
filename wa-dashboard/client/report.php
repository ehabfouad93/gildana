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
    $exF = ['sent' => "status IN ('sent','delivered','read')", 'delivered' => "status IN ('delivered','read')", 'read' => "status='read'",
            'unread' => "status IN ('sent','delivered')", 'failed' => "status IN ('failed','dead','review')", 'queued' => "status IN ('queued','sending')"][(string) ($_GET['status'] ?? '')] ?? '1=1';
    $rows = db_all("SELECT COALESCE(phone_e164, (SELECT COALESCE(NULLIF(k.name,''), CONCAT('@', k.ig_username)) FROM contacts k WHERE k.id=contact_id)) phone_e164,
                           status,wa_message_id,error_title,sent_at,delivered_at,read_at FROM campaign_messages WHERE campaign_id=? AND $exF ORDER BY id", [$id]);
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
$counts['unread'] = max(0, $counts['sent'] - $counts['read']);       // reached the phone, not opened yet
$pct = fn(int $n, int $of) => $of > 0 ? round(100 * $n / $of) . '%' : '—';
$queued = (int) db_val("SELECT COUNT(*) FROM campaign_messages WHERE campaign_id=? AND status IN ('queued','sending')", [$id]);

// per-message list (paged)
$page = max(1, (int) ($_GET['page'] ?? 1)); $per = 50; $off = ($page - 1) * $per;
$statusFilter = (string) ($_GET['status'] ?? '');
$where = "campaign_id=?"; $params = [$id];
// Each filter matches its tile: Sent = everything that went out, Unread = went out but not read yet,
// Failed = failed for good (including given up after retries, or never confirmed).
$filterSql = ['sent' => "status IN ('sent','delivered','read')", 'delivered' => "status IN ('delivered','read')", 'read' => "status='read'",
              'unread' => "status IN ('sent','delivered')", 'failed' => "status IN ('failed','dead','review')", 'queued' => "status IN ('queued','sending')"];
if (isset($filterSql[$statusFilter])) $where .= " AND " . $filterSql[$statusFilter];
$msgTotal = (int) db_val("SELECT COUNT(*) FROM campaign_messages WHERE $where", $params);
$messages = db_all("SELECT campaign_messages.*, (SELECT COALESCE(NULLIF(k.name,''), CONCAT('@', k.ig_username)) FROM contacts k WHERE k.id=campaign_messages.contact_id) AS who
                       FROM campaign_messages WHERE $where ORDER BY id DESC LIMIT $per OFFSET $off", $params);
$social = in_array((string) ($camp['channel'] ?? 'whatsapp'), ['messenger', 'instagram'], true);
$pages = (int) max(1, ceil($msgTotal / $per));

$actions = '<a class="btn btn-ghost btn-sm" href="report.php?id=' . $id . '&export=1' . ($statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '') . '">Export CSV</a><a class="btn btn-ghost btn-sm" href="campaigns.php">← Campaigns</a>';
client_header('Report · ' . $camp['name'], 'campaigns', $CLIENT);
?>
<div class="page-head">
  <h1><?= e((string) $camp['name']) ?> <span id="status-pill"><?= status_pill((string) $camp['status']) ?></span></h1>
  <div class="page-actions"><?= guide_button('campaigns') . $actions ?></div>
</div>

<p class="text-muted" style="margin:-10px 0 20px;font-size:13px">
  <?php if ($social): ?>
  <?= $camp['channel'] === 'instagram' ? 'Instagram' : 'Messenger' ?> · <strong><?= $camp['audience_kind'] === 'optin' ? 'People who agreed to receive offers' : 'People who wrote in the last 24 hours' ?></strong> ·
  <?php else: ?>
  Template <strong><?= e((string) ($camp['template_name'] ?? '—')) ?></strong> ·
  <?php endif; ?>
  List <strong><?= e((string) ($camp['list_name'] ?? '—')) ?></strong> ·
  Created <?= e(date('d M Y, H:i', strtotime((string) $camp['created_at']))) ?>
</p>

<div class="stats-row">
  <a class="stat-tile" href="report.php?id=<?= $id ?>"><span class="lbl">Total</span><span class="val" id="s-total"><?= $counts['total'] ?></span><span class="sub" id="s-queued"><?= $queued ?> pending</span></a>
  <a class="stat-tile" href="report.php?id=<?= $id ?>&status=sent"><span class="lbl">Sent</span><span class="val" id="s-sent"><?= $counts['sent'] ?></span><span class="sub" id="p-sent"><?= $pct($counts['sent'], $counts['total']) ?> of all</span></a>
  <a class="stat-tile" href="report.php?id=<?= $id ?>&status=delivered"><span class="lbl">Delivered</span><span class="val" id="s-delivered"><?= $counts['delivered'] ?></span><span class="sub" id="p-delivered"><?= $pct($counts['delivered'], $counts['sent']) ?> of sent</span></a>
  <a class="stat-tile" href="report.php?id=<?= $id ?>&status=read"><span class="lbl">Read</span><span class="val accent" id="s-read"><?= $counts['read'] ?></span><span class="sub" id="p-read"><?= $pct($counts['read'], $counts['sent']) ?> of sent</span></a>
  <a class="stat-tile" href="report.php?id=<?= $id ?>&status=unread"><span class="lbl">Unread</span><span class="val" id="s-unread"><?= $counts['unread'] ?></span><span class="sub" id="p-unread"><?= $pct($counts['unread'], $counts['sent']) ?> of sent</span></a>
  <a class="stat-tile" href="report.php?id=<?= $id ?>&status=failed"><span class="lbl">Failed</span><span class="val danger" id="s-failed"><?= $counts['failed'] ?></span><span class="sub" id="p-failed"><?= $pct($counts['failed'], $counts['total']) ?> of all</span></a>
</div>

<?php
/* Why did messages fail? Group the top reasons so "0 sent / N failed" is never a mystery. */
$failReasons = db_all(
    "SELECT COALESCE(NULLIF(error_title,''),'Unknown error') AS reason, error_code, COUNT(*) AS n
       FROM campaign_messages WHERE campaign_id=? AND status='failed'
      GROUP BY reason, error_code ORDER BY n DESC LIMIT 5", [$id]
);
if ($failReasons): ?>
  <div class="alert error" style="margin-bottom:16px">
    <strong>Why messages failed</strong>
    <ul style="margin:8px 0 0;padding-inline-start:18px;font-size:13px">
      <?php foreach ($failReasons as $fr): ?>
        <li><strong><?= number_format((int) $fr['n']) ?>×</strong> <?= e((string) $fr['reason']) ?><?php
          $code = trim((string) $fr['error_code']);
          if ($code !== '') echo ' <span class="text-muted">(#' . e($code) . ')</span>';
          // Plain-language cause + what to actually do about it. The ladder itself lives in
          // wa_error_explain() so this page, the Inbox and Needs attention cannot disagree
          // about whether a given code is worth retrying.
          $hint = wa_error_explain($code, (string) $fr['reason'])['hint'];
          if ($hint !== '') echo '<div class="text-muted" style="font-size:12px;margin-top:2px">' . $hint . '</div>';
        ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card card-flush">
  <div style="padding:14px 18px" class="row-between">
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php
      $filters = ['' => 'All', 'sent' => 'Sent', 'delivered' => 'Delivered', 'read' => 'Read', 'unread' => 'Unread', 'failed' => 'Failed', 'queued' => 'Pending'];
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
      <thead><tr><th><?= $social ? 'Person' : 'Phone' ?></th><th>Status</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Error</th></tr></thead>
      <tbody>
      <?php if (!$messages): ?><tr><td colspan="6"><div class="empty">No messages<?= $statusFilter ? ' with this status' : '' ?>.</div></td></tr><?php endif; ?>
      <?php foreach ($messages as $m): ?>
        <tr>
          <td class="<?= $social ? '' : 'mono' ?>"><?= $social ? e((string) ($m['who'] ?? '—')) : '+' . e((string) $m['phone_e164']) ?></td>
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
      const sent = +d.sent_count || 0, read = +d.read_count || 0, total = +d.total_count || 0;
      const pc = (n, of) => of > 0 ? Math.round(100 * n / of) + '%' : '—';
      document.getElementById('s-unread').textContent = Math.max(0, sent - read);
      document.getElementById('p-sent').textContent = pc(sent, total) + ' of all';
      document.getElementById('p-delivered').textContent = pc(+d.delivered_count || 0, sent) + ' of sent';
      document.getElementById('p-read').textContent = pc(read, sent) + ' of sent';
      document.getElementById('p-unread').textContent = pc(Math.max(0, sent - read), sent) + ' of sent';
      document.getElementById('p-failed').textContent = pc(+d.failed_count || 0, total) + ' of all';
      document.getElementById('s-queued').textContent = (d.queued||0)+' pending';
      if (['completed','failed','canceled'].includes(d.status)) { clearInterval(poll); location.reload(); }
    } catch(e){}
  }, 5000);
}
</script>

<?php layout_footer(); ?>
