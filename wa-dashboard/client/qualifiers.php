<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/ai.php';
require __DIR__ . '/../includes/notify.php';
require __DIR__ . '/../includes/automation.php';

$cid = (int) $CLIENT['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [$id, $cid]);
    if (!$flow) json_out(['ok' => false]);
    if (($_POST['action'] ?? '') === 'toggle') {
        $new = $flow['status'] === 'active' ? 'paused' : 'active';
        db_run("UPDATE flows SET status=? WHERE id=?", [$new, $id]);
        if ($new === 'paused') {
            // Deactivating cancels the pending outreach backlog so it stops sending.
            $cancelled = (int) db_val("SELECT COUNT(*) FROM flow_runs WHERE flow_id=? AND status='queued'", [$id]);
            db_run("UPDATE flow_runs SET status='stopped', updated_at=NOW() WHERE flow_id=? AND status='queued'", [$id]);
            json_out(['ok' => true, 'status' => $new, 'cancelled' => $cancelled]);
        }
        trigger_worker(); // reactivated → resume sending immediately
        json_out(['ok' => true, 'status' => $new]);
    }
    json_out(['ok' => false]);
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') $err = 'Enter a name.';
    else {
        $newId = db_insert("INSERT INTO flows (client_id,name,kind,status,trigger_type,created_at) VALUES (?,?, 'qualifier','draft','google_sheet', NOW())", [$cid, $name]);
        redirect('qualifier_edit.php?id=' . $newId);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    db_run("DELETE FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [(int) ($_POST['id'] ?? 0), $cid]);
    flash('Qualifier deleted.');
    redirect('qualifiers.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_now') {
    verify_csrf();
    $f = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='qualifier'", [(int) ($_POST['id'] ?? 0), $cid]);
    if (!$f) { flash('Qualifier not found.', 'error'); redirect('qualifiers.php'); }
    $r = automation_send_now($CLIENT, $f);
    if ($r['error'] !== '') flash('Send failed: ' . $r['error'], 'error');
    else flash("Sent now: {$r['imported']} new · {$r['retried']} retried · {$r['skipped']} already in this qualifier · {$r['invalid']} invalid of {$r['rows']} rows.");
    redirect('qualifiers.php');
}

$flows = db_all(
    "SELECT f.*,
            (SELECT COUNT(*) FROM flow_runs r WHERE r.flow_id=f.id) AS leads,
            (SELECT COUNT(*) FROM flow_runs r WHERE r.flow_id=f.id AND r.grade='hot') AS hot
       FROM flows f WHERE f.client_id=? AND f.kind='qualifier' ORDER BY f.id DESC", [$cid]
);

$actions = '<a class="btn btn-ghost btn-sm" href="diagnostics.php">🩺 Health check</a>'
         . '<button class="btn btn-primary btn-sm" onclick="document.getElementById(\'m-new\').classList.add(\'open\')">+ New Qualifier</button>';
client_header('Lead Qualifier', 'qualifier', $CLIENT);
page_head('Lead Qualifier', $actions);
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="alert info" style="font-size:12.5px">
  A qualifier pulls leads from a <strong>Google Sheet</strong>, sends an approved outreach template, then an <strong>AI</strong> asks your questions and scores each lead <strong>hot / warm / cold</strong>.
  <?php if (!($CLIENT['ai_provider'] ?? '')): ?> Add your AI key in <a href="settings.php#ai">Settings</a> first.<?php endif; ?>
</div>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Qualifier</th><th>Sheet</th><th>Leads</th><th>Hot</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php if (!$flows): ?><tr><td colspan="6"><div class="empty">No qualifiers yet.</div></td></tr><?php endif; ?>
      <?php foreach ($flows as $f):
        $sc = json_decode((string) $f['source_config'], true) ?: [];
      ?>
        <tr>
          <td><strong><?= e((string) $f['name']) ?></strong> <?= $f['status'] === 'draft' ? '<span class="pill gray">draft</span>' : '' ?></td>
          <td><?= !empty($sc['csv_url']) ? '<span class="pill green">connected</span>' : '<span class="pill gray">not set</span>' ?></td>
          <td><?= (int) $f['leads'] ?></td>
          <td><?= (int) $f['hot'] ? '<span class="pill red">' . (int) $f['hot'] . '</span>' : '0' ?></td>
          <td><label class="switch"><input type="checkbox" <?= $f['status'] === 'active' ? 'checked' : '' ?> onchange="toggleQ(<?= (int) $f['id'] ?>,this)"><span class="slider"></span></label></td>
          <td style="text-align:right;white-space:nowrap">
            <form method="post" style="display:inline" onsubmit="return confirm('Import new leads from the sheet and send outreach now?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="send_now"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="btn btn-primary btn-sm" title="Import new + send outreach + retry stuck">&#9658; Send now</button>
            </form>
            <a class="btn btn-ghost btn-sm" href="qualifier_edit.php?id=<?= (int) $f['id'] ?>">Edit</a>
            <a class="btn btn-ghost btn-sm" href="leads.php?flow=<?= (int) $f['id'] ?>">Leads</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this qualifier and its leads?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <button class="icon-btn" title="Delete">&#x2715;</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="m-new">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h2>New Lead Qualifier</h2>
    <div class="field"><span class="lbl">Name</span><input type="text" name="name" placeholder="e.g. Property leads Q3" required></div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-new').classList.remove('open')">Cancel</button><button class="btn btn-primary">Create &amp; Configure</button></div>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
async function toggleQ(id, el){
  const fd=new FormData(); fd.append('ajax','1'); fd.append('csrf_token',CSRF); fd.append('action','toggle'); fd.append('id',id);
  const r=await fetch('',{method:'POST',body:fd}); const d=await r.json();
  if(d.ok) showToast(d.status==='active'?'Qualifier activated.':('Qualifier paused.'+(d.cancelled?' '+d.cancelled+' pending outreach cancelled.':''))); else { el.checked=!el.checked; showToast('Could not update.',true); }
}
</script>

<?php layout_footer(); ?>
