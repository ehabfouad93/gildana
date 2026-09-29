<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];

$agg = db_row(
    "SELECT
        COUNT(*)                       AS campaigns,
        COALESCE(SUM(total_count),0)   AS total,
        COALESCE(SUM(sent_count),0)    AS sent,
        COALESCE(SUM(delivered_count),0) AS delivered,
        COALESCE(SUM(read_count),0)    AS `read`,
        COALESCE(SUM(failed_count),0)  AS failed
     FROM campaigns WHERE client_id=?", [$cid]
) ?: [];

$campaigns = db_all(
    "SELECT c.*, t.wa_name AS template_name
       FROM campaigns c LEFT JOIN templates t ON t.id=c.template_id
      WHERE c.client_id=? ORDER BY c.id DESC LIMIT 100", [$cid]
);

$pct = fn($n, $d) => $d > 0 ? round(100 * $n / $d) : 0;

client_header('Reports', 'reports', $CLIENT);
page_head('Reports');
?>

<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Campaigns</span><span class="val"><?= (int) ($agg['campaigns'] ?? 0) ?></span></div>
  <div class="stat-tile"><span class="lbl">Messages Sent</span><span class="val"><?= number_format((int) ($agg['sent'] ?? 0)) ?></span></div>
  <div class="stat-tile"><span class="lbl">Delivered Rate</span><span class="val accent"><?= $pct((int) ($agg['delivered'] ?? 0), (int) ($agg['sent'] ?? 0)) ?>%</span></div>
  <div class="stat-tile"><span class="lbl">Read Rate</span><span class="val"><?= $pct((int) ($agg['read'] ?? 0), (int) ($agg['sent'] ?? 0)) ?>%</span></div>
  <div class="stat-tile"><span class="lbl">Failed</span><span class="val <?= (int) ($agg['failed'] ?? 0) ? 'danger' : '' ?>"><?= number_format((int) ($agg['failed'] ?? 0)) ?></span></div>
</div>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Delivered</th><th>Read</th><th>Failed</th><th></th></tr></thead>
      <tbody>
      <?php if (!$campaigns): ?><tr><td colspan="7"><div class="empty">No campaigns yet.</div></td></tr><?php endif; ?>
      <?php foreach ($campaigns as $c):
        $s = (int) $c['sent_count'];
      ?>
        <tr>
          <td><strong><?= e((string) $c['name']) ?></strong><br><small class="text-muted"><?= e((string) ($c['template_name'] ?? '')) ?></small></td>
          <td><?= status_pill((string) $c['status']) ?></td>
          <td><?= $s ?> / <?= (int) $c['total_count'] ?></td>
          <td><?= (int) $c['delivered_count'] ?> <small class="text-muted">(<?= $pct((int) $c['delivered_count'], $s) ?>%)</small></td>
          <td><?= (int) $c['read_count'] ?> <small class="text-muted">(<?= $pct((int) $c['read_count'], $s) ?>%)</small></td>
          <td><?= (int) $c['failed_count'] ? '<span class="pill red">' . (int) $c['failed_count'] . '</span>' : '0' ?></td>
          <td style="text-align: end"><a class="btn btn-ghost btn-sm" href="report.php?id=<?= (int) $c['id'] ?>">Details</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
/* ── which ads are actually sending people ──
   Populated by Click-to-WhatsApp referrals as they arrive, so it fills itself with no setup.
   Absent entirely until the first ad click, rather than showing an empty table to the many
   clients who do not run ads at all. */
require_once __DIR__ . '/../includes/ads.php';
$ads = ads_list($cid);
if ($ads): ?>
<div class="card card-flush" id="ads">
  <div class="page-head" style="padding:16px 18px 0;margin:0">
    <h2 style="margin:0">Ads</h2>
    <p class="text-muted" style="font-size:12.5px;margin:4px 0 0">
      People who messaged you by tapping an ad, credited to the ad that first brought them.
    </p>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Ad</th><th>Leads</th><th>Hot</th><th>First click</th><th>Last click</th></tr></thead>
      <tbody>
      <?php foreach ($ads as $a): ?>
        <tr>
          <td><strong><?= e(ads_label($a['headline'], (string) $a['source_id'])) ?></strong>
            <br><small class="text-muted mono"><?= e((string) $a['source_id']) ?></small></td>
          <td><?= (int) $a['leads'] ?></td>
          <td><?= (int) $a['hot'] ? '<span class="pill red">' . (int) $a['hot'] . '</span>' : '0' ?></td>
          <td class="text-muted"><?= e(date('j M Y', strtotime((string) $a['first_seen_at']))) ?></td>
          <td class="text-muted"><?= e(date('j M, H:i', strtotime((string) $a['last_seen_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php layout_footer(); ?>
