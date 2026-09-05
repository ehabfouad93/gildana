<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];
$contacts   = (int) db_val("SELECT COUNT(*) FROM contacts WHERE client_id=? AND opt_in_status='in'", [$cid]);
$optedOut   = (int) db_val("SELECT COUNT(*) FROM contacts WHERE client_id=? AND opt_in_status='out'", [$cid]);
$templates  = (int) db_val("SELECT COUNT(*) FROM templates WHERE client_id=? AND status='APPROVED'", [$cid]);
$campaigns  = (int) db_val("SELECT COUNT(*) FROM campaigns WHERE client_id=?", [$cid]);
$sent30     = (int) db_val("SELECT COUNT(*) FROM campaign_messages WHERE client_id=? AND status IN ('sent','delivered','read') AND sent_at > (NOW() - INTERVAL 30 DAY)", [$cid]);

$recent = db_all(
    "SELECT id,name,status,total_count,sent_count,delivered_count,failed_count,created_at
       FROM campaigns WHERE client_id=? ORDER BY id DESC LIMIT 6", [$cid]
);

client_header('Dashboard', 'dashboard', $CLIENT);
page_head('Welcome, ' . $CLIENT['name']);

if (!client_ready($CLIENT)): ?>
  <div class="alert info"><?= channel_is_personal($CLIENT)
      ? 'Your WhatsApp number isn\'t linked yet. Go to <strong>Settings → My WhatsApp Number</strong> and scan the QR code to start sending.'
      : 'Your WhatsApp number isn\'t connected yet. ' . e(BRAND_PARENT) . ' needs to add your API credentials before you can send campaigns.' ?></div>
<?php endif; ?>

<?php
/* The first-run walkthrough, answered from real data rather than a list someone ticks by
   hand. It disappears the moment everything is done — a checklist of six ticks is clutter. */
$gs = guide_checklist($CLIENT);
if ($gs['done'] < $gs['total']): ?>
  <div class="card">
    <div class="gs-head">
      <h2 style="margin:0;border:0;padding:0">Getting started</h2>
      <span class="gs-count"><?= $gs['done'] ?> of <?= $gs['total'] ?> done</span>
    </div>
    <p class="text-muted" style="font-size:12.5px;margin:0">
      In this order, and each one takes a few minutes. This card goes away once you are set up.
    </p>
    <div class="gs-bar"><span style="width:<?= (int) round(100 * $gs['done'] / max(1, $gs['total'])) ?>%"></span></div>
    <div class="gs-list">
      <?php foreach ($gs['items'] as $i): ?>
        <div class="gs-item <?= $i['done'] ? 'done' : '' ?>">
          <span class="gs-tick" aria-hidden="true">✓</span>
          <span class="gs-text">
            <b><?= e($i['label']) ?></b>
            <span><?= e($i['help']) ?></span>
          </span>
          <a class="btn btn-ghost btn-sm" href="<?= e($i['url']) ?>"><?= e($i['cta']) ?></a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Credits</span><span class="val accent"><?= number_format((int) $CLIENT['credits_balance']) ?></span><span class="sub">1 credit / message</span></div>
  <div class="stat-tile"><span class="lbl">Opted-in Contacts</span><span class="val"><?= number_format($contacts) ?></span><span class="sub"><?= $optedOut ?> opted out</span></div>
  <div class="stat-tile"><span class="lbl">Approved Templates</span><span class="val"><?= $templates ?></span></div>
  <div class="stat-tile"><span class="lbl">Sent (30d)</span><span class="val"><?= number_format($sent30) ?></span></div>
</div>

<div class="card card-flush">
  <div style="padding:16px 22px" class="row-between">
    <h2 style="border:0;padding:0;margin:0">Recent Campaigns</h2>
    <a class="btn btn-primary btn-sm" href="campaign_new.php">+ New Campaign</a>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Campaign</th><th>Status</th><th>Sent</th><th>Delivered</th><th>Failed</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="7"><div class="empty">No campaigns yet. Create your first campaign to get started.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $c): ?>
        <tr>
          <td><?= e($c['name']) ?></td>
          <td><?= status_pill((string) $c['status']) ?></td>
          <td><?= (int) $c['sent_count'] ?> / <?= (int) $c['total_count'] ?></td>
          <td><?= (int) $c['delivered_count'] ?></td>
          <td><?= (int) $c['failed_count'] ? '<span class="pill red">' . (int) $c['failed_count'] . '</span>' : '0' ?></td>
          <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $c['created_at']))) ?></td>
          <td style="text-align:right"><a class="btn-link" href="report.php?id=<?= (int) $c['id'] ?>">View →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer(); ?>
