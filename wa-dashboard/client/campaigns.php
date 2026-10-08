<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];

/* ── AJAX status actions ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $a  = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $camp = db_row("SELECT * FROM campaigns WHERE id=? AND client_id=?", [$id, $cid]);
    if (!$camp) json_out(['ok' => false]);

    if ($a === 'pause' && in_array($camp['status'], ['sending', 'scheduled'], true)) {
        db_run("UPDATE campaigns SET status='paused' WHERE id=?", [$id]);
        json_out(['ok' => true]);
    }
    if ($a === 'resume' && $camp['status'] === 'paused') {
        db_run("UPDATE campaigns SET status='sending', started_at=COALESCE(started_at,NOW()) WHERE id=?", [$id]);
        trigger_worker(); // resume sending immediately
        json_out(['ok' => true]);
    }
    if ($a === 'cancel' && in_array($camp['status'], ['sending', 'scheduled', 'paused'], true)) {
        db_run("UPDATE campaigns SET status='canceled', completed_at=NOW() WHERE id=?", [$id]);
        // Drop still-queued messages so they never send.
        db_run("UPDATE campaign_messages SET status='failed', error_code='canceled', error_title='Campaign canceled', updated_at=NOW() WHERE campaign_id=? AND status IN ('queued','sending')", [$id]);
        json_out(['ok' => true]);
    }
    json_out(['ok' => false, 'error' => 'invalid transition']);
}

$campaigns = db_all(
    "SELECT c.*, t.wa_name AS template_name, l.name AS list_name
       FROM campaigns c
       LEFT JOIN templates t ON t.id = c.template_id
       LEFT JOIN contact_lists l ON l.id = c.list_id
      WHERE c.client_id = ? ORDER BY c.id DESC", [$cid]
);

$actions = '<a class="btn btn-primary btn-sm" href="campaign_new.php">+ New Campaign</a>';
if (can_use('sms')) $actions = '<a class="btn btn-ghost btn-sm" href="sms.php">+ SMS</a>' . $actions;
if (client_has_channel($CLIENT, 'messenger') || client_has_channel($CLIENT, 'instagram')) {
    $actions = '<a class="btn btn-ghost btn-sm" href="campaign_social.php">+ Messenger / Instagram</a>' . $actions;
}
client_header('Campaigns', 'campaigns', $CLIENT);
page_head('Campaigns', $actions);

// Every campaign together: how many people were sent to, read it, have not read it yet, or never got it.
$sum = ['total' => 0, 'sent' => 0, 'read' => 0, 'failed' => 0];
foreach ($campaigns as $c_) { $sum['total'] += (int) $c_['total_count']; $sum['sent'] += (int) $c_['sent_count']; $sum['read'] += (int) $c_['read_count']; $sum['failed'] += (int) $c_['failed_count']; }
$sum['unread'] = max(0, $sum['sent'] - $sum['read']);
$pc = fn(int $n, int $of) => $of > 0 ? round(100 * $n / $of) . '%' : '—';
?>
<?php if ($campaigns): ?>
<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Recipients</span><span class="val"><?= number_format($sum['total']) ?></span><span class="sub"><?= count($campaigns) ?> campaign<?= count($campaigns) === 1 ? '' : 's' ?></span></div>
  <div class="stat-tile"><span class="lbl">Sent</span><span class="val"><?= number_format($sum['sent']) ?></span><span class="sub"><?= $pc($sum['sent'], $sum['total']) ?> of recipients</span></div>
  <div class="stat-tile"><span class="lbl">Read</span><span class="val accent"><?= number_format($sum['read']) ?></span><span class="sub"><?= $pc($sum['read'], $sum['sent']) ?> of sent</span></div>
  <div class="stat-tile"><span class="lbl">Unread</span><span class="val"><?= number_format($sum['unread']) ?></span><span class="sub"><?= $pc($sum['unread'], $sum['sent']) ?> of sent</span></div>
  <div class="stat-tile"><span class="lbl">Failed</span><span class="val danger"><?= number_format($sum['failed']) ?></span><span class="sub"><?= $pc($sum['failed'], $sum['total']) ?> of recipients</span></div>
</div>
<?php endif; ?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Campaign</th><th>Template</th><th>Status</th><th>Progress</th><th class="num">Sent</th><th class="num">Read</th><th class="num">Unread</th><th class="num">Failed</th><th>When</th><th></th></tr></thead>
      <tbody>
      <?php if (!$campaigns): ?>
        <tr><td colspan="10"><div class="empty">No campaigns yet.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($campaigns as $c):
        $total = max(1, (int) $c['total_count']);
        $sentPct = (int) round(100 * (int) $c['sent_count'] / $total);
        $delPct  = (int) round(100 * (int) $c['delivered_count'] / $total);
        $failPct = (int) round(100 * (int) $c['failed_count'] / $total);
      ?>
        <tr>
          <td><strong><?= e((string) $c['name']) ?></strong></td>
          <td class="text-muted"><?php if (($c['channel'] ?? '') === 'sms'): ?><span class="pill gray">SMS</span> <?= e(mb_strimwidth((string) $c['body_text'], 0, 40, '…')) ?><?php elseif (in_array($c['channel'] ?? 'whatsapp', ['messenger', 'instagram'], true)): ?><span class="pill <?= $c['channel'] === 'instagram' ? 'gold' : 'blue' ?>"><?= $c['channel'] === 'instagram' ? 'Instagram' : 'Messenger' ?></span> <?= $c['audience_kind'] === 'optin' ? 'Offers opt-in' : 'Last 24h' ?><?php else: ?><?= e((string) ($c['template_name'] ?? '—')) ?><?php endif; ?></td>
          <td><?= status_pill((string) $c['status']) ?></td>
          <td style="min-width:150px">
            <div class="progress" title="<?= (int) $c['sent_count'] ?> sent / <?= (int) $c['total_count'] ?>">
              <span class="p-delivered" style="width:<?= $delPct ?>%"></span>
              <span class="p-sent" style="width:<?= max(0, $sentPct - $delPct) ?>%"></span>
              <span class="p-failed" style="width:<?= $failPct ?>%"></span>
            </div>
            <small class="text-muted"><?= (int) $c['sent_count'] ?>/<?= (int) $c['total_count'] ?> sent · <?= (int) $c['delivered_count'] ?> delivered</small>
          </td>
          <?php $unread = max(0, (int) $c['sent_count'] - (int) $c['read_count']); $rl = 'report.php?id=' . (int) $c['id'] . '&status='; ?>
          <td class="num"><a href="<?= $rl ?>sent"><?= (int) $c['sent_count'] ?></a></td>
          <td class="num"><a href="<?= $rl ?>read"><?= (int) $c['read_count'] ?></a>
            <?php if ((int) $c['sent_count']): ?><small class="text-muted cm-pct"><?= round(100 * (int) $c['read_count'] / (int) $c['sent_count']) ?>%</small><?php endif; ?></td>
          <td class="num"><a href="<?= $rl ?>unread"><?= $unread ?></a></td>
          <td class="num"><?= (int) $c['failed_count'] ? '<a href="' . $rl . 'failed" class="pill red">' . (int) $c['failed_count'] . '</a>' : '0' ?></td>
          <td class="text-muted">
            <?= $c['status'] === 'scheduled' && $c['scheduled_at']
                ? e(date('d M, H:i', strtotime((string) $c['scheduled_at'])))
                : e(date('d M, H:i', strtotime((string) $c['created_at']))) ?>
          </td>
          <td style="text-align: end;white-space:nowrap">
            <a class="btn btn-ghost btn-sm" href="report.php?id=<?= (int) $c['id'] ?>">Report</a>
            <?php /* Opens the new-campaign form filled in, rather than queueing a send behind
                      a single click — the audience and timing deserve a second look. */ ?>
            <?php if (($c['channel'] ?? 'whatsapp') === 'whatsapp'): ?>
            <a class="btn btn-ghost btn-sm" href="campaign_new.php?copy=<?= (int) $c['id'] ?>"
               title="Send this again — opens a copy you can check first">Duplicate</a>
            <?php endif; ?>
            <?php if (in_array($c['status'], ['sending', 'scheduled'], true)): ?>
              <button class="btn-link" onclick="act('pause',<?= (int) $c['id'] ?>)">Pause</button>
            <?php elseif ($c['status'] === 'paused'): ?>
              <button class="btn-link" onclick="act('resume',<?= (int) $c['id'] ?>)">Resume</button>
            <?php endif; ?>
            <?php if (in_array($c['status'], ['sending', 'scheduled', 'paused'], true)): ?>
              <button class="btn-link" style="color:var(--danger)" onclick="if(confirm('Cancel this campaign? Unsent messages will not go out.'))act('cancel',<?= (int) $c['id'] ?>)">Cancel</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
async function act(action, id){
  const fd=new FormData(); fd.append('ajax','1'); fd.append('csrf_token',CSRF); fd.append('action',action); fd.append('id',id);
  const r=await fetch('',{method:'POST',body:fd}); const d=await r.json();
  if(d.ok){ showToast('Done.'); setTimeout(()=>location.reload(),500);} else showToast(d.error||'Could not update.',true);
}
</script>

<?php layout_footer(); ?>
