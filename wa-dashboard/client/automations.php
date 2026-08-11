<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];

/* ── AJAX status toggle ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $a  = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=?", [$id, $cid]);
    if (!$flow) json_out(['ok' => false]);
    if ($a === 'toggle') {
        $new = $flow['status'] === 'active' ? 'paused' : 'active';
        db_run("UPDATE flows SET status=? WHERE id=?", [$new, $id]);
        json_out(['ok' => true, 'status' => $new]);
    }
    json_out(['ok' => false]);
}

/* ── create / delete ── */
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();
    $name    = trim((string) ($_POST['name'] ?? ''));
    $trigger = (string) ($_POST['trigger_type'] ?? 'keyword');
    if (!in_array($trigger, ['keyword', 'welcome'], true)) $trigger = 'keyword';
    if ($name === '') $err = 'Enter an automation name.';
    else {
        $newId = db_insert(
            "INSERT INTO flows (client_id,name,kind,status,trigger_type,created_at) VALUES (?,?, 'bot','draft', ?, NOW())",
            [$cid, $name, $trigger]
        );
        redirect('automation_edit.php?id=' . $newId);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    db_run("DELETE FROM flows WHERE id=? AND client_id=?", [(int) ($_POST['id'] ?? 0), $cid]);
    flash('Automation deleted.');
    redirect('automations.php');
}

$flows = db_all(
    "SELECT f.*,
            (SELECT COUNT(*) FROM flow_messages m WHERE m.flow_id=f.id) AS sends
       FROM flows f WHERE f.client_id=? AND f.kind='bot' ORDER BY f.id DESC", [$cid]
);

$triggerLabel = ['keyword' => 'Keyword', 'welcome' => 'Welcome', 'google_sheet' => 'Google Sheet (AI leads)', 'button' => 'Button'];

$actions = '<a class="btn btn-ghost btn-sm" href="diagnostics.php">🩺 Health check</a>'
         . '<button class="btn btn-primary btn-sm" onclick="document.getElementById(\'m-new\').classList.add(\'open\')">+ New Automation</button>';
client_header('Automations', 'automations', $CLIENT);
page_head('Automations', $actions);
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="alert info" style="font-size:12.5px">
  Automations reply to inbound WhatsApp messages (chatbot) or pull leads from a Google Sheet and qualify them with AI.
  Free-form replies only work within 24h of the contact's last message; each sent message costs 1 credit.
  <?php if (!($CLIENT['ai_provider'] ?? '')): ?><br><strong>Tip:</strong> to use AI steps, add your AI key in <a href="settings.php#ai">Settings</a>.<?php endif; ?>
</div>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Automation</th><th>Trigger</th><th>Runs</th><th>Sends</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php if (!$flows): ?>
        <tr><td colspan="6"><div class="empty">No automations yet. Create one to get started.</div></td></tr>
      <?php endif; ?>
      <?php foreach ($flows as $f): ?>
        <tr>
          <td><strong><?= e((string) $f['name']) ?></strong> <?= $f['status'] === 'draft' ? '<span class="pill gray">draft</span>' : '' ?></td>
          <td class="text-muted"><?= e($triggerLabel[$f['trigger_type']] ?? $f['trigger_type']) ?></td>
          <td><?= (int) $f['runs_count'] ?></td>
          <td><?= (int) $f['sends'] ?></td>
          <td>
            <label class="switch">
              <input type="checkbox" <?= $f['status'] === 'active' ? 'checked' : '' ?> onchange="toggleFlow(<?= (int) $f['id'] ?>,this)">
              <span class="slider"></span>
            </label>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn-ghost btn-sm" href="automation_edit.php?id=<?= (int) $f['id'] ?>">Edit</a>
            <a class="btn btn-ghost btn-sm" href="automation_report.php?id=<?= (int) $f['id'] ?>">Report</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this automation and its runs?')">
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
  <form class="modal" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h2>New Automation</h2>
    <div class="field"><span class="lbl">Name</span><input type="text" name="name" placeholder="e.g. Price enquiry bot" required></div>
    <div class="field">
      <span class="lbl">Type</span>
      <select name="trigger_type">
        <option value="keyword">Keyword reply</option>
        <option value="welcome">Welcome (first message)</option>
      </select>
      <div class="hint">For AI lead scoring from a Google Sheet, use the <strong>Lead Qualifier</strong> section instead.</div>
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('m-new').classList.remove('open')">Cancel</button>
      <button type="submit" class="btn btn-primary">Create &amp; Edit</button>
    </div>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
async function toggleFlow(id, el){
  const fd=new FormData(); fd.append('ajax','1'); fd.append('csrf_token',CSRF); fd.append('action','toggle'); fd.append('id',id);
  const r=await fetch('',{method:'POST',body:fd}); const d=await r.json();
  if(d.ok){ showToast(d.status==='active'?'Automation activated.':'Automation paused.'); }
  else { el.checked=!el.checked; showToast('Could not update.',true); }
}
</script>

<?php layout_footer(); ?>
