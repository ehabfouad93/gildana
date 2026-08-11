<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/ai.php';

$cid = (int) $CLIENT['id'];

/* ── AJAX: test the AI key ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_ai') {
    verify_csrf();
    $fresh = db_row("SELECT * FROM clients WHERE id=?", [$cid]);
    $res = ai_test_key($fresh ?: []);
    json_out(['ok' => $res['ok'], 'error' => $res['error']]);
}

$err = '';

/* ── Save AI settings ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_ai') {
    verify_csrf();
    $provider = strtolower(trim((string) ($_POST['ai_provider'] ?? '')));
    if (!in_array($provider, ['', 'claude', 'openai'], true)) $provider = '';
    $model = trim((string) ($_POST['ai_model'] ?? ''));
    $key   = (string) ($_POST['ai_api_key'] ?? '');
    $keyEnc = trim($key) !== '' ? encrypt_secret(trim($key)) : $CLIENT['ai_api_key_enc'];
    db_run("UPDATE clients SET ai_provider=?, ai_model=?, ai_api_key_enc=? WHERE id=?",
        [$provider, $model, $keyEnc, $cid]);
    flash('AI settings saved.');
    redirect('settings.php#ai');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    verify_csrf();
    $cur  = (string) ($_POST['current_password'] ?? '');
    $new  = (string) ($_POST['new_password'] ?? '');
    $conf = (string) ($_POST['confirm_password'] ?? '');
    $user = db_row("SELECT * FROM users WHERE id=?", [(int) $ME['id']]);

    if (!$user || !password_verify($cur, $user['password_hash'])) {
        $err = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $err = 'New password must be at least 8 characters.';
    } elseif ($new !== $conf) {
        $err = 'New passwords do not match.';
    } else {
        db_run("UPDATE users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_DEFAULT), (int) $ME['id']]);
        flash('Password changed.');
        redirect('settings.php');
    }
}

client_header('Settings', 'settings', $CLIENT);
page_head('Settings');
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="card">
  <h2>Account</h2>
  <div class="grid2">
    <div class="field"><span class="lbl">Account Name</span><input type="text" value="<?= e((string) $CLIENT['name']) ?>" disabled></div>
    <div class="field"><span class="lbl">Login Email</span><input type="text" value="<?= e((string) $ME['email']) ?>" disabled></div>
    <div class="field"><span class="lbl">Credit Balance</span><input type="text" value="<?= number_format((int) $CLIENT['credits_balance']) ?> credits" disabled></div>
    <div class="field"><span class="lbl">WhatsApp Connection</span>
      <input type="text" value="<?= client_ready($CLIENT) ? 'Connected' : 'Not connected — contact Gildana' ?>" disabled>
    </div>
  </div>
  <p class="text-muted" style="font-size:12.5px">Need more credits or a credential change? Contact Gildana — these are managed for you.</p>
</div>

<div class="card" id="ai">
  <h2>AI Engine (for AI-powered automations)</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 16px">
    Used by AI branch / AI score steps in your automations. You bring your own API key — AI usage is billed by your provider, not by credits.
  </p>
  <form method="post" style="max-width:520px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_ai">
    <div class="grid2">
      <div class="field">
        <span class="lbl">Provider</span>
        <select name="ai_provider">
          <option value="" <?= ($CLIENT['ai_provider'] ?? '') === '' ? 'selected' : '' ?>>— none —</option>
          <option value="claude" <?= ($CLIENT['ai_provider'] ?? '') === 'claude' ? 'selected' : '' ?>>Claude (Anthropic)</option>
          <option value="openai" <?= ($CLIENT['ai_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>
        </select>
      </div>
      <div class="field">
        <span class="lbl">Model <span class="text-muted">(optional)</span></span>
        <input type="text" name="ai_model" value="<?= e((string) ($CLIENT['ai_model'] ?? '')) ?>" placeholder="e.g. claude-haiku-4-5 or gpt-4o-mini">
        <div class="hint">Leave blank for the default. A cheaper model (Claude Haiku / gpt-4o-mini) lowers per-lead cost.</div>
      </div>
    </div>
    <div class="field">
      <span class="lbl">API Key <?= $CLIENT['ai_api_key_enc'] ? '<span class="pill green" style="margin-left:6px">••• set</span>' : '' ?></span>
      <input type="text" name="ai_api_key" autocomplete="off" placeholder="<?= $CLIENT['ai_api_key_enc'] ? 'Leave blank to keep current key' : 'Paste your provider API key' ?>">
      <div class="hint">Stored encrypted. Never shown again after saving.</div>
    </div>
    <div class="row-between mt10">
      <button type="button" class="btn btn-ghost" id="btn-test-ai">Test AI key</button>
      <button type="submit" class="btn btn-primary">Save AI Settings</button>
    </div>
    <div id="ai-test-result" class="mt10"></div>
  </form>
</div>

<div class="card">
  <h2>Change Password</h2>
  <form method="post" style="max-width:420px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="field"><span class="lbl">Current Password</span><input type="password" name="current_password" required></div>
    <div class="field"><span class="lbl">New Password (min 8)</span><input type="password" name="new_password" required minlength="8"></div>
    <div class="field"><span class="lbl">Confirm New Password</span><input type="password" name="confirm_password" required></div>
    <button type="submit" class="btn btn-primary">Change Password</button>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
document.getElementById('btn-test-ai').addEventListener('click', async function(){
  const box = document.getElementById('ai-test-result');
  this.disabled = true; this.textContent = 'Testing…'; box.innerHTML = '';
  try {
    const fd = new FormData(); fd.append('action','test_ai'); fd.append('csrf_token', CSRF);
    const r = await fetch('', { method:'POST', body: fd }); const d = await r.json();
    box.innerHTML = d.ok
      ? '<div class="alert success">AI key works.</div>'
      : '<div class="alert error">AI test failed: '+(d.error||'unknown error')+'. Save your key first, then test.</div>';
  } catch(e){ box.innerHTML = '<div class="alert error">Network error.</div>'; }
  this.disabled = false; this.textContent = 'Test AI key';
});
</script>

<?php layout_footer(); ?>
