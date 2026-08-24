<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/channel.php';

$cid = (int) $CLIENT['id'];

/* ── AJAX: connect / inspect this client's own WhatsApp number ──
   Only meaningful on the personal channel. The client never sees a gateway URL or key —
   they scan a QR (or use the pairing code), exactly like WhatsApp Web. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['pw_connect', 'pw_qr', 'pw_status', 'pw_pair', 'pw_logout', 'pw_resync'], true)) {
    verify_csrf();
    $fresh = db_row("SELECT * FROM clients WHERE id=?", [$cid]) ?: [];
    if (!channel_is_personal($fresh)) json_out(['ok' => false, 'error' => 'This account sends through the WhatsApp Cloud API.']);
    if (!pw_configured())            json_out(['ok' => false, 'error' => 'The WhatsApp gateway is not set up yet — contact support.']);

    $action = (string) $_POST['action'];
    if ($action === 'pw_connect') {
        $r = pw_instance_create($fresh);
        if (empty($r['ok'])) json_out(['ok' => false, 'error' => $r['error']]);
        $fresh = db_row("SELECT * FROM clients WHERE id=?", [$cid]) ?: [];
        $q = pw_qr($fresh);
        json_out(['ok' => $q['ok'], 'qr' => $q['qr'], 'error' => $q['error']]);
    }
    if ($action === 'pw_resync') {
        // Re-registers the inbound webhook without touching the linked session — for a
        // number that's already connected but whose replies never showed up in the Inbox.
        $r = pw_set_webhook($fresh);
        json_out(['ok' => $r['ok'], 'error' => $r['error']]);
    }
    if ($action === 'pw_qr') {
        // QR codes rotate every ~20-60s, so the page re-fetches instead of showing a dead one.
        $q = pw_qr($fresh);
        json_out(['ok' => $q['ok'], 'qr' => $q['qr'], 'error' => $q['error']]);
    }
    if ($action === 'pw_pair') {
        $r = pw_pair_code($fresh, (string) ($_POST['msisdn'] ?? ''));
        json_out(['ok' => $r['ok'], 'code' => $r['code'], 'error' => $r['error']]);
    }
    if ($action === 'pw_logout') {
        pw_logout($fresh);
        json_out(['ok' => true]);
    }
    $st = pw_status($fresh);
    json_out(['ok' => true, 'state' => $st['state'], 'msisdn' => $st['msisdn'], 'error' => $st['error']]);
}

/* ── AJAX: test the AI key ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_ai') {
    verify_csrf();
    $fresh = db_row("SELECT * FROM clients WHERE id=?", [$cid]);
    $res = ai_test_key($fresh ?: []);
    json_out(['ok' => $res['ok'], 'error' => $res['error']]);
}

$err = '';

/* ── Switch sending channel ──
   The client owns this choice: they may have both a Cloud API number and their own phone and
   want to move between them without waiting on support. Each direction is only offered when
   it can actually send, so switching can't leave the account unable to send at all. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_channel') {
    verify_csrf();
    $want = ($_POST['channel'] ?? 'cloud') === 'personal' ? 'personal' : 'cloud';
    if ($want === channel_of($CLIENT)) {
        redirect('settings.php#channel');
    } elseif ($want === 'personal' && !pw_configured()) {
        $err = 'Sending from your own number isn\'t available on your account yet — contact ' . BRAND_PARENT . '.';
    } elseif ($want === 'cloud' && !($CLIENT['access_token_enc'] && $CLIENT['phone_number_id'])) {
        $err = 'Your WhatsApp Cloud API number isn\'t set up, so there is nothing to switch to. Contact ' . BRAND_PARENT . '.';
    } else {
        db_run("UPDATE clients SET channel=? WHERE id=?", [$want, $cid]);
        flash($want === 'personal'
            ? 'Now sending from your own number — link it below to start.'
            : 'Now sending through the WhatsApp Cloud API.');
        redirect('settings.php#' . ($want === 'personal' ? 'mynumber' : 'channel'));
    }
}

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
      <?php if (channel_is_personal($CLIENT)): ?>
        <input type="text" value="<?= $CLIENT['personal_status'] === 'connected'
            ? 'Your own number' . ($CLIENT['personal_msisdn'] ? ' (+' . e((string) $CLIENT['personal_msisdn']) . ')' : '')
            : 'Your own number — not linked yet' ?>" disabled>
      <?php else: ?>
        <input type="text" value="<?= client_ready($CLIENT) ? 'Connected' : 'Not connected — contact support' ?>" disabled>
      <?php endif; ?>
    </div>
  </div>
  <p class="text-muted" style="font-size:12.5px">Need more credits or a credential change? Contact <?= e(BRAND_PARENT) ?> — these are managed for you.</p>
</div>

<?php
$onPersonal  = channel_is_personal($CLIENT);
$hasCloud    = (bool) ($CLIENT['access_token_enc'] && $CLIENT['phone_number_id']);
$hasPersonal = pw_configured();
// The card always shows where messages currently go out from; the option they can't use yet
// is disabled with the reason on it, rather than hidden with no explanation.
?>
<div class="card" id="channel">
  <h2>Sending Channel</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    Where your messages go out from. Campaigns, Automations, the Lead Qualifier and the Inbox all
    follow this one setting — switch it and every module follows immediately.
  </p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_channel">
    <div class="field" style="max-width:420px"><span class="lbl">Send from</span>
      <select name="channel" id="ch-sel" onchange="chNote()">
        <option value="cloud"    <?= $onPersonal ? '' : 'selected' ?> <?= $hasCloud    ? '' : 'disabled' ?>>Business number (WhatsApp Cloud API)<?= $hasCloud ? '' : ' — not set up' ?></option>
        <option value="personal" <?= $onPersonal ? 'selected' : '' ?> <?= $hasPersonal ? '' : 'disabled' ?>>My own WhatsApp number<?= $hasPersonal ? '' : ' — not available' ?></option>
      </select>
    </div>
    <div id="ch-note-personal" style="display:<?= $onPersonal ? 'block' : 'none' ?>">
      <div class="alert error" style="font-size:12.5px">
        <strong>Read this before switching.</strong> Sending from your own number uses a WhatsApp Web
        session, which is against WhatsApp's Terms — the number can be banned, and the risk rises the
        faster it sends. That's why messages go out
        <strong><?= (int) ($CLIENT['slot_size'] ?: 15) ?> at a time</strong> with a
        <strong><?= round((int) ($CLIENT['slot_pause_sec'] ?: 180) / 60, 1) ?>-minute</strong> pause between
        batches, shared across every module. There are no approved templates and no 24-hour window
        here — you write your messages directly.
      </div>
    </div>
    <div id="ch-note-cloud" style="display:<?= $onPersonal ? 'none' : 'block' ?>">
      <div class="alert info" style="font-size:12.5px">
        The official channel: full speed, no ban risk, but the first message to a customer must use a
        Meta-<strong>approved template</strong> and free-form replies only work inside the 24-hour window.
      </div>
    </div>
    <?php if ($onPersonal ? $hasCloud : $hasPersonal): ?>
      <button type="submit" class="btn btn-primary">Save channel</button>
    <?php else: ?>
      <p class="text-muted" style="font-size:12.5px;margin:0">
        There is only one channel set up on your account. To add the other one, contact <?= e(BRAND_PARENT) ?>.
      </p>
    <?php endif; ?>
  </form>
</div>
<script>
function chNote(){
  var p = document.getElementById('ch-sel').value === 'personal';
  document.getElementById('ch-note-personal').style.display = p ? 'block' : 'none';
  document.getElementById('ch-note-cloud').style.display    = p ? 'none' : 'block';
}
</script>

<?php if (channel_is_personal($CLIENT)): $pwState = (string) $CLIENT['personal_status']; ?>
<div class="card" id="mynumber">
  <h2>My WhatsApp Number</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    Link your own WhatsApp so campaigns, automations and the Inbox send from your number.
    Messages are sent in small batches with a pause between them to keep your number safe.
  </p>

  <!-- connected -->
  <div id="pw-connected" style="display:<?= $pwState === 'connected' ? 'block' : 'none' ?>">
    <div class="alert success" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <strong>Connected</strong>
      <span id="pw-number" class="text-muted"><?= $CLIENT['personal_msisdn'] ? '+' . e((string) $CLIENT['personal_msisdn']) : '' ?></span>
      <button type="button" class="btn btn-ghost btn-sm" style="margin-left:auto" onclick="pwLogout()">Disconnect</button>
    </div>
    <div style="margin-top:10px;font-size:12.5px" class="text-muted">
      Not seeing customer replies come in? <button type="button" class="btn-link" onclick="pwResync()">Resync connection</button>
      <span id="pw-resync-msg"></span>
    </div>
  </div>

  <!-- not connected -->
  <div id="pw-idle" style="display:<?= $pwState === 'connected' ? 'none' : 'block' ?>">
    <button type="button" class="btn btn-primary" id="pw-start" onclick="pwConnect()">Connect my WhatsApp</button>
    <span id="pw-msg" class="text-muted" style="margin-left:10px;font-size:12.5px"></span>
  </div>

  <!-- linking -->
  <div id="pw-linking" style="display:none;margin-top:14px">
    <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start">
      <div style="text-align:center">
        <img id="pw-qr" alt="WhatsApp QR code" style="width:230px;height:230px;border:1px solid var(--line);border-radius:10px;background:#fff">
        <div class="text-muted" style="font-size:11.5px;margin-top:6px">The code refreshes automatically</div>
      </div>
      <div style="flex:1;min-width:230px">
        <ol style="padding-left:18px;line-height:1.9;font-size:13.5px;margin:0">
          <li>Open <strong>WhatsApp</strong> on your phone</li>
          <li>Tap <strong>Settings</strong> → <strong>Linked devices</strong></li>
          <li>Tap <strong>Link a device</strong> and scan this code</li>
        </ol>
        <div style="margin-top:14px">
          <button type="button" class="btn-link" onclick="pwTogglePair()">Can't scan? Link with your phone number instead</button>
        </div>
        <div id="pw-pair" style="display:none;margin-top:10px">
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <input type="text" id="pw-msisdn" placeholder="201012345678" style="flex:1;min-width:170px">
            <button type="button" class="btn btn-ghost btn-sm" onclick="pwPair()">Get code</button>
          </div>
          <div id="pw-code" style="display:none;margin-top:10px">
            <div class="text-muted" style="font-size:12px">On your phone: Linked devices → Link with phone number, then enter:</div>
            <div style="font-size:26px;letter-spacing:.22em;font-weight:700;margin-top:4px" id="pw-code-val"></div>
          </div>
        </div>
        <div style="margin-top:14px"><button type="button" class="btn btn-ghost btn-sm" onclick="pwCancel()">Cancel</button></div>
      </div>
    </div>
  </div>
</div>

<script>
const PW_CSRF = <?= json_encode(csrf_token()) ?>;
let pwQrTimer = null, pwStatusTimer = null;
const pwEl = id => document.getElementById(id);

async function pwPost(action, extra) {
  const fd = new FormData();
  fd.append('csrf_token', PW_CSRF); fd.append('action', action);
  for (const k in (extra || {})) fd.append(k, extra[k]);
  const r = await fetch('settings.php', { method: 'POST', body: fd });
  return r.json();
}
function pwShow(which) {
  pwEl('pw-idle').style.display      = which === 'idle'      ? 'block' : 'none';
  pwEl('pw-linking').style.display   = which === 'linking'   ? 'block' : 'none';
  pwEl('pw-connected').style.display = which === 'connected' ? 'block' : 'none';
}
function pwStop() { clearInterval(pwQrTimer); clearInterval(pwStatusTimer); pwQrTimer = pwStatusTimer = null; }

async function pwConnect() {
  const b = pwEl('pw-start'); b.disabled = true; pwEl('pw-msg').textContent = 'Preparing…';
  const d = await pwPost('pw_connect');
  b.disabled = false; pwEl('pw-msg').textContent = '';
  if (!d.ok) { pwEl('pw-msg').textContent = d.error || 'Could not start.'; return; }
  if (d.qr) pwEl('pw-qr').src = d.qr;
  pwShow('linking');
  // A stale QR silently stops working, so refresh it and watch for the link to complete.
  pwQrTimer     = setInterval(pwRefreshQr, 20000);
  pwStatusTimer = setInterval(pwPollStatus, 3000);
}
async function pwRefreshQr() {
  const d = await pwPost('pw_qr');
  if (d.ok && d.qr) pwEl('pw-qr').src = d.qr;
}
async function pwPollStatus() {
  const d = await pwPost('pw_status');
  if (d.ok && d.state === 'connected') {
    pwStop();
    pwEl('pw-number').textContent = d.msisdn ? '+' + d.msisdn : '';
    pwShow('connected');
  }
}
function pwTogglePair() { const p = pwEl('pw-pair'); p.style.display = p.style.display === 'none' ? 'block' : 'none'; }
async function pwPair() {
  const d = await pwPost('pw_pair', { msisdn: pwEl('pw-msisdn').value });
  if (!d.ok) { alert(d.error || 'Could not get a code.'); return; }
  pwEl('pw-code-val').textContent = d.code;
  pwEl('pw-code').style.display = 'block';
}
function pwCancel() { pwStop(); pwShow('idle'); }
async function pwLogout() {
  if (!confirm('Disconnect your WhatsApp number? Sending will stop until you link it again.')) return;
  await pwPost('pw_logout');
  pwShow('idle');
}
async function pwResync() {
  const m = pwEl('pw-resync-msg'); m.textContent = ' Resyncing…';
  const d = await pwPost('pw_resync');
  m.textContent = d.ok ? ' Done — replies should arrive now.' : ' Could not resync: ' + (d.error || 'unknown error');
}
</script>
<?php endif; ?>

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
