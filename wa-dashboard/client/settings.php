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

<div class="card" id="notif-card" style="display:none">
  <h2>Notifications</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    Get an alert on this device when a customer replies, so you can jump into the Inbox and take over.
  </p>
  <div id="notif-body"></div>
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

/* ── push notifications ──
   Permission must be requested from a user gesture (iOS refuses otherwise), so this is
   always behind a button. On iOS push only works once the app is on the Home Screen. */
(function(){
  var card=document.getElementById('notif-card'), box=document.getElementById('notif-body');
  if(!card||!box) return;
  var supported = ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window);
  var iOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform==='MacIntel' && navigator.maxTouchPoints>1);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

  if(!supported){
    if(iOS && !standalone){
      card.style.display='';
      box.innerHTML='<div class="alert info">On iPhone, add this app to your Home Screen first '
        +'(<strong>Share</strong> &#8593; &rarr; <strong>Add to Home Screen</strong>), open it from there, then come back to turn notifications on.</div>';
    }
    return;   // desktop browser without push support: nothing to offer
  }
  card.style.display='';

  function b64ToU8(b64){
    var pad='='.repeat((4 - b64.length % 4) % 4);
    var raw=atob((b64+pad).replace(/-/g,'+').replace(/_/g,'/'));
    return Uint8Array.from([...raw].map(c=>c.charCodeAt(0)));
  }
  function render(on, note){
    box.innerHTML = (note?('<div class="alert '+(note.err?'error':'info')+'">'+note.msg+'</div>'):'')
      + '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">'
      + '<span class="pill '+(on?'green':'gray')+' dot">'+(on?'Enabled on this device':'Off')+'</span>'
      + '<button type="button" class="btn '+(on?'btn-ghost':'btn-primary')+' btn-sm" id="notif-btn">'
      + (on?'Turn off':'Enable notifications on this device')+'</button></div>';
    document.getElementById('notif-btn').addEventListener('click', on?disable:enable);
  }
  async function current(){
    var reg = await navigator.serviceWorker.ready;
    return await reg.pushManager.getSubscription();
  }
  async function enable(){
    var btn=document.getElementById('notif-btn'); btn.disabled=true; btn.textContent='Enabling…';
    try{
      var perm = await Notification.requestPermission();
      if(perm!=='granted'){ render(false,{err:true,msg:'Notifications are blocked for this site. Allow them in your browser settings, then try again.'}); return; }
      var kr = await (await fetch('../push_subscribe.php?key=1')).json();
      if(!kr.ok || !kr.key){ render(false,{err:true,msg:'Notifications are not set up on the server yet — ask Gildana to generate the keys.'}); return; }
      var reg = await navigator.serviceWorker.ready;
      var sub = await reg.pushManager.subscribe({userVisibleOnly:true, applicationServerKey:b64ToU8(kr.key)});
      var fd=new FormData(); fd.append('action','subscribe'); fd.append('csrf_token',CSRF); fd.append('sub',JSON.stringify(sub));
      var res = await (await fetch('../push_subscribe.php',{method:'POST',body:fd})).json();
      if(!res.ok){ render(false,{err:true,msg:res.error||'Could not save the subscription.'}); return; }
      render(true,{msg:'Done — this device will be notified when a customer replies.'});
    }catch(e){ render(false,{err:true,msg:'Could not enable notifications: '+e.message}); }
  }
  async function disable(){
    var btn=document.getElementById('notif-btn'); btn.disabled=true; btn.textContent='Turning off…';
    try{
      var sub = await current();
      if(sub){
        var fd=new FormData(); fd.append('action','unsubscribe'); fd.append('csrf_token',CSRF); fd.append('sub',JSON.stringify(sub));
        await fetch('../push_subscribe.php',{method:'POST',body:fd});
        await sub.unsubscribe();
      }
      render(false,{msg:'Notifications turned off on this device.'});
    }catch(e){ render(false,{err:true,msg:'Could not turn them off: '+e.message}); }
  }
  current().then(function(sub){ render(!!sub, null); }).catch(function(){ render(false,null); });
})();
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
