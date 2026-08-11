<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) $CLIENT['id'];

/* ── AJAX status toggle ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
    verify_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $flow = db_row("SELECT * FROM flows WHERE id=? AND client_id=? AND kind='agent'", [$id, $cid]);
    if (!$flow) json_out(['ok' => false]);
    if (($_POST['action'] ?? '') === 'toggle') {
        $new = $flow['status'] === 'active' ? 'paused' : 'active';
        db_run("UPDATE flows SET status=? WHERE id=?", [$new, $id]);
        json_out(['ok' => true, 'status' => $new]);
    }
    json_out(['ok' => false]);
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    verify_csrf();
    $name    = trim((string) ($_POST['name'] ?? ''));
    $trigger = (string) ($_POST['trigger_type'] ?? 'welcome');
    if (!in_array($trigger, ['welcome', 'keyword'], true)) $trigger = 'welcome';
    if ($name === '') $err = 'Enter a name.';
    else {
        $newId = db_insert("INSERT INTO flows (client_id,name,kind,status,trigger_type,created_at) VALUES (?,?, 'agent','draft', ?, NOW())", [$cid, $name, $trigger]);
        redirect('agent_edit.php?id=' . $newId);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verify_csrf();
    db_run("DELETE FROM flows WHERE id=? AND client_id=? AND kind='agent'", [(int) ($_POST['id'] ?? 0), $cid]);
    flash('Agent deleted.');
    redirect('agents.php');
}

$flows = db_all(
    "SELECT f.*,
            (SELECT COUNT(*) FROM flow_runs r WHERE r.flow_id=f.id) AS chats,
            (SELECT COUNT(*) FROM flow_messages m WHERE m.flow_id=f.id) AS sends
       FROM flows f WHERE f.client_id=? AND f.kind='agent' ORDER BY f.id DESC", [$cid]
);
$triggerLabel = ['welcome' => 'First message', 'keyword' => 'Keyword'];

$actions = '<a class="btn btn-ghost btn-sm" href="diagnostics.php">🩺 Health check</a>'
         . '<button class="btn btn-primary btn-sm" onclick="document.getElementById(\'m-new\').classList.add(\'open\')">+ New Agent</button>';
client_header('AI Chat Agent', 'agents', $CLIENT);
page_head('AI Chat Agent', $actions);
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="alert info" style="font-size:12.5px">
  An AI Chat Agent answers customers who <strong>message your number</strong> — it chats like a human using only the
  <strong>knowledge you give it</strong>, pursues your goals, and captures details. No lead scoring.
  <?php if (!($CLIENT['ai_provider'] ?? '')): ?> Add your AI key in <a href="settings.php#ai">Settings</a> first.<?php endif; ?>
</div>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Agent</th><th>Trigger</th><th>Chats</th><th>Sends</th><th>Active</th><th></th></tr></thead>
      <tbody>
      <?php if (!$flows): ?><tr><td colspan="6"><div class="empty">No agents yet.</div></td></tr><?php endif; ?>
      <?php foreach ($flows as $f): ?>
        <tr>
          <td><strong><?= e((string) $f['name']) ?></strong> <?= $f['status'] === 'draft' ? '<span class="pill gray">draft</span>' : '' ?></td>
          <td class="text-muted"><?= e($triggerLabel[$f['trigger_type']] ?? $f['trigger_type']) ?></td>
          <td><?= (int) $f['chats'] ?></td>
          <td><?= (int) $f['sends'] ?></td>
          <td><label class="switch"><input type="checkbox" <?= $f['status'] === 'active' ? 'checked' : '' ?> onchange="toggleA(<?= (int) $f['id'] ?>,this)"><span class="slider"></span></label></td>
          <td style="text-align:right;white-space:nowrap">
            <a class="btn btn-ghost btn-sm" href="agent_edit.php?id=<?= (int) $f['id'] ?>">Edit</a>
            <a class="btn btn-ghost btn-sm" href="agent_chats.php?flow=<?= (int) $f['id'] ?>">Chats</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this agent and its chats?')">
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
    <h2>New AI Chat Agent</h2>
    <div class="field"><span class="lbl">Name</span><input type="text" name="name" placeholder="e.g. Sales assistant" required></div>
    <div class="field"><span class="lbl">Start when…</span>
      <select name="trigger_type">
        <option value="welcome">Any customer messages the number</option>
        <option value="keyword">A keyword is received</option>
      </select>
      <div class="hint">You'll set the knowledge, persona and (for keywords) the trigger words next.</div>
    </div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-new').classList.remove('open')">Cancel</button><button class="btn btn-primary">Create &amp; Configure</button></div>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
async function toggleA(id, el){
  const fd=new FormData(); fd.append('ajax','1'); fd.append('csrf_token',CSRF); fd.append('action','toggle'); fd.append('id',id);
  const r=await fetch('',{method:'POST',body:fd}); const d=await r.json();
  if(d.ok) showToast(d.status==='active'?'Agent activated.':'Agent paused.'); else { el.checked=!el.checked; showToast('Could not update.',true); }
}
</script>

<?php layout_footer(); ?>
