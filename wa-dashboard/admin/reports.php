<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$agg = db_row(
    "SELECT COUNT(*) campaigns,
            COALESCE(SUM(total_count),0) total,
            COALESCE(SUM(sent_count),0) sent,
            COALESCE(SUM(delivered_count),0) delivered,
            COALESCE(SUM(read_count),0) `read`,
            COALESCE(SUM(failed_count),0) failed
       FROM campaigns"
) ?: [];

$perClient = db_all(
    "SELECT cl.id, cl.name,
            COUNT(c.id) campaigns,
            COALESCE(SUM(c.sent_count),0) sent,
            COALESCE(SUM(c.delivered_count),0) delivered,
            COALESCE(SUM(c.read_count),0) `read`,
            COALESCE(SUM(c.failed_count),0) failed,
            cl.credits_balance
       FROM clients cl LEFT JOIN campaigns c ON c.client_id = cl.id
      GROUP BY cl.id ORDER BY sent DESC, cl.name"
);
$pct = fn($n, $d) => $d > 0 ? round(100 * $n / $d) : 0;

layout_header('Reports', 'admin', 'reports');
page_head('Global Reports');
?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Campaigns</span><span class="val"><?= number_format((int) ($agg['campaigns'] ?? 0)) ?></span></div>
  <div class="stat-tile"><span class="lbl">Messages Sent</span><span class="val accent"><?= number_format((int) ($agg['sent'] ?? 0)) ?></span></div>
  <div class="stat-tile"><span class="lbl">Delivered Rate</span><span class="val"><?= $pct((int) ($agg['delivered'] ?? 0), (int) ($agg['sent'] ?? 0)) ?>%</span></div>
  <div class="stat-tile"><span class="lbl">Read Rate</span><span class="val"><?= $pct((int) ($agg['read'] ?? 0), (int) ($agg['sent'] ?? 0)) ?>%</span></div>
  <div class="stat-tile"><span class="lbl">Failed</span><span class="val <?= (int) ($agg['failed'] ?? 0) ? 'danger' : '' ?>"><?= number_format((int) ($agg['failed'] ?? 0)) ?></span></div>
</div>

<div class="card card-flush">
  <div style="padding:16px 22px"><h2 style="border:0;padding:0;margin:0">Per-client performance</h2></div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Client</th><th>Campaigns</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Failed</th><th>Credits</th></tr></thead>
      <tbody>
      <?php if (!$perClient): ?><tr><td colspan="7"><div class="empty">No clients yet.</div></td></tr><?php endif; ?>
      <?php foreach ($perClient as $r): $s = (int) $r['sent']; ?>
        <tr>
          <td><a class="btn-link" href="client.php?id=<?= (int) $r['id'] ?>"><?= e((string) $r['name']) ?></a></td>
          <td><?= (int) $r['campaigns'] ?></td>
          <td><?= number_format($s) ?></td>
          <td><?= number_format((int) $r['delivered']) ?> <small class="text-muted">(<?= $pct((int) $r['delivered'], $s) ?>%)</small></td>
          <td><?= number_format((int) $r['read']) ?> <small class="text-muted">(<?= $pct((int) $r['read'], $s) ?>%)</small></td>
          <td><?= (int) $r['failed'] ? '<span class="pill red">' . number_format((int) $r['failed']) . '</span>' : '0' ?></td>
          <td><?= number_format((int) $r['credits_balance']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_footer(); ?>
