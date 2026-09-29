<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/sending.php';

/**
 * A salesperson links their own WhatsApp, so the leads they message see them — not a company
 * number — and replies come back to them.
 *
 * The same QR / pairing-code flow as the company number in Settings, run against this person's
 * own session. Every call goes through a client row carrying their session (user_channel_client),
 * and pw_store() sends its writes to their row — so nothing here can touch the company's link.
 */
$uid = (int) ($PERM_USER['id'] ?? 0);
$cid = (int) $CLIENT['id'];
$wantsOwn = ($PERM_USER['send_via'] ?? '') === 'own';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['pw_connect', 'pw_qr', 'pw_status', 'pw_pair', 'pw_logout'], true)) {
    verify_csrf();
    if (!$wantsOwn) json_out(['ok' => false, 'error' => 'Your admin has not set you up to send from your own phone.']);
    if (!pw_configured()) json_out(['ok' => false, 'error' => 'The WhatsApp gateway is not set up yet — contact support.']);

    $row = fn() => user_channel_client($CLIENT, user_channel_ensure($cid, $uid));
    $action = (string) $_POST['action'];
    if ($action === 'pw_connect') {
        $r = pw_instance_create($row());
        if (empty($r['ok'])) json_out(['ok' => false, 'error' => $r['error']]);
        $q = pw_qr($row());
        json_out(['ok' => $q['ok'], 'qr' => $q['qr'], 'error' => $q['error']]);
    }
    if ($action === 'pw_qr')   { $q = pw_qr($row()); json_out(['ok' => $q['ok'], 'qr' => $q['qr'], 'error' => $q['error']]); }
    if ($action === 'pw_pair') { $r = pw_pair_code($row(), (string) ($_POST['msisdn'] ?? '')); json_out(['ok' => $r['ok'], 'code' => $r['code'], 'error' => $r['error']]); }
    if ($action === 'pw_logout') { pw_logout($row()); json_out(['ok' => true]); }
    $st = pw_status($row());
    json_out(['ok' => true, 'state' => $st['state'], 'msisdn' => $st['msisdn'], 'error' => $st['error']]);
}

$uc    = user_channel($uid);
$state = (string) ($uc['status'] ?? 'disconnected');

client_header('My WhatsApp', 'profile', $CLIENT);
page_head('My WhatsApp');
?>
<?php if (!$wantsOwn): ?>
  <div class="card" style="max-width:620px">
    <h2 style="margin-top:0">You send from <?= e(mb_strtolower(send_via_labels()[(string) ($PERM_USER['send_via'] ?? '')] ?? 'the account\'s number')) ?></h2>
    <p class="text-muted">Linking your own phone is only for people their admin has set to send from their own number.
      If you should be, ask your account admin to change it on the Team page.</p>
  </div>
<?php else: ?>
  <div class="card" style="max-width:720px">
    <h2 style="margin-top:0">Link your phone</h2>
    <p class="text-muted" style="font-size:13px">Messages you send to your leads from this app will go out from your own
      WhatsApp, and their replies come back to you here. Automated replies never run on your number.</p>
    <div class="alert error" style="font-size:12.5px">
      <strong>Your number can be banned.</strong> This works like WhatsApp Web on your phone, which is not WhatsApp's
      business service. Sending many messages to people who have not saved your number, or the same text to many people,
      can get the number blocked by WhatsApp — and it is your personal number. Message people who asked to hear from you.
    </div>

    <div id="pw-connected" style="display:<?= $state === 'connected' ? 'block' : 'none' ?>">
      <p><span class="pill green">Linked</span> <span id="pw-number" class="text-muted"><?= !empty($uc['msisdn']) ? '+' . e((string) $uc['msisdn']) : '' ?></span></p>
      <button type="button" class="btn btn-ghost" onclick="pw('pw_logout').then(()=>location.reload())">Unlink this phone</button>
    </div>

    <div id="pw-idle" style="display:<?= $state === 'connected' ? 'none' : 'block' ?>">
      <button type="button" class="btn btn-primary" id="pw-start" onclick="pwConnect()">Link my WhatsApp</button>
      <span id="pw-msg" class="text-muted" style="margin-inline-start:10px;font-size:12.5px"></span>
    </div>

    <div id="pw-linking" style="display:none;margin-top:14px">
      <p style="font-size:13px">On your phone: WhatsApp → <strong>Settings → Linked devices → Link a device</strong>, then scan:</p>
      <img id="pw-qr" alt="WhatsApp QR code" style="width:230px;height:230px;border:1px solid var(--line);border-radius:10px;background:#fff">
      <details style="margin-top:10px"><summary style="font-size:13px">Can't scan? Link with your phone number instead</summary>
        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
          <input type="text" id="pw-msisdn" placeholder="201012345678" inputmode="numeric" style="flex:1;min-width:170px">
          <button type="button" class="btn btn-ghost" onclick="pwPair()">Get a code</button></div>
        <div id="pw-code" style="font-size:26px;letter-spacing:.22em;font-weight:700;margin-top:8px"></div>
      </details>
    </div>
  </div>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const $ = id => document.getElementById(id);
async function pw(action, extra){
  const fd = new FormData(); fd.append('action', action); fd.append('csrf_token', CSRF);
  for (const [k,v] of Object.entries(extra || {})) fd.append(k, v);
  return (await fetch('my_whatsapp.php', {method:'POST', body:fd})).json().catch(() => ({ok:false, error:'Something went wrong.'}));
}
let pollT = null;
async function pwConnect(){
  $('pw-start').disabled = true; $('pw-msg').textContent = 'Starting…';
  const d = await pw('pw_connect');
  $('pw-start').disabled = false;
  if (!d.ok) { $('pw-msg').textContent = d.error || 'Could not start.'; return; }
  $('pw-msg').textContent = ''; $('pw-linking').style.display = 'block';
  if (d.qr) $('pw-qr').src = d.qr;
  clearInterval(pollT);
  /* QR codes rotate every 20–60 seconds, so keep a fresh one on screen, and watch for the scan. */
  pollT = setInterval(async () => {
    const s = await pw('pw_status');
    if (s.state === 'connected') { clearInterval(pollT); location.reload(); return; }
    const q = await pw('pw_qr'); if (q.qr) $('pw-qr').src = q.qr;
  }, 5000);
}
async function pwPair(){
  const d = await pw('pw_pair', {msisdn: $('pw-msisdn').value});
  $('pw-code').textContent = d.ok ? d.code : (d.error || 'Could not get a code.');
}
</script>
<?php endif; ?>
<?php layout_footer();
