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
client_header('Campaigns', 'campaigns', $CLIENT);
page_head('Campaigns', $actions);
?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Campaign</th><th>Template</th><th>Status</th><th>Progress</th><th>Failed</th><th>When</th><th></th></tr></thead>
      <tbody>
      <?php if (!$campaigns): ?>
        <tr><td colspan="7"><div class="empty">No campaigns yet.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($campaigns as $c):
        $total = max(1, (int) $c['total_count']);
        $sentPct = (int) round(100 * (int) $c['sent_count'] / $total);
        $delPct  = (int) round(100 * (int) $c['delivered_count'] / $total);
        $failPct = (int) round(100 * (int) $c['failed_count'] / $total);
      ?>
        <tr>
          <td><strong><?= e((string) $c['name']) ?></strong></td>
          <td class="text-muted"><?= e((string) ($c['template_name'] ?? '—')) ?></td>
          <td><?= status_pill((string) $c['status']) ?></td>
          <td style="min-width:150px">
            <div class="progress" title="<?= (int) $c['sent_count'] ?> sent / <?= (int) $c['total_count'] ?>">
              <span class="p-delivered" style="width:<?= $delPct ?>%"></span>
              <span class="p-sent" style="width:<?= max(0, $sentPct - $delPct) ?>%"></span>
              <span class="p-failed" style="width:<?= $failPct ?>%"></span>
            </div>
            <small class="text-muted"><?= (int) $c['sent_count'] ?>/<?= (int) $c['total_count'] ?> sent · <?= (int) $c['delivered_count'] ?> delivered</small>
          </td>
          <td><?= (int) $c['failed_count'] ? '<span class="pill red">' . (int) $c['failed_count'] . '</span>' : '0' ?></td>
          <td class="text-muted">
            <?= $c['status'] === 'scheduled' && $c['scheduled_at']
                ? e(date('d M, H:i', strtotime((string) $c['scheduled_at'])))
                : e(date('d M, H:i', strtotime((string) $c['created_at']))) ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn-ghost btn-sm" href="report.php?id=<?= (int) $c['id'] ?>">Report</a>
            <?php /* Opens the new-campaign form filled in, rather than queueing a send behind
                      a single click — the audience and timing deserve a second look. */ ?>
            <a class="btn btn-ghost btn-sm" href="campaign_new.php?copy=<?= (int) $c['id'] ?>"
               title="Send this again — opens a copy you can check first">Duplicate</a>
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
