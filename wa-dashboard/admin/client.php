<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$id = (int) ($_GET['id'] ?? 0);
$client = db_row("SELECT * FROM clients WHERE id = ?", [$id]);
if (!$client) { http_response_code(404); exit('Client not found.'); }

/* ── AJAX: test connection ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_connection') {
    verify_csrf();
    $res = wa_fetch_templates($client);
    if ($res['ok']) {
        $approved = count(array_filter($res['templates'], fn($t) => strtoupper((string) ($t['status'] ?? '')) === 'APPROVED'));
        json_out(['ok' => true, 'count' => count($res['templates']), 'approved' => $approved]);
    }
    json_out(['ok' => false, 'error' => $res['error']]);
}

/* ── AJAX: enable/disable ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    verify_csrf();
    $new = $client['status'] === 'active' ? 'disabled' : 'active';
    db_run("UPDATE clients SET status=? WHERE id=?", [$new, $id]);
    json_out(['ok' => true, 'status' => $new]);
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_profile') {
        db_run(
            "UPDATE clients SET name=?, sender_display=?, company=?, contact_person=?, contact_phone=?,
                    contact_email=?, category=?, timezone=?, default_country=?, notes=? WHERE id=?",
            [
                trim((string) ($_POST['name'] ?? $client['name'])),
                trim((string) ($_POST['sender_display'] ?? '')),
                trim((string) ($_POST['company'] ?? '')),
                trim((string) ($_POST['contact_person'] ?? '')),
                trim((string) ($_POST['contact_phone'] ?? '')),
                trim((string) ($_POST['contact_email'] ?? '')),
                trim((string) ($_POST['category'] ?? '')),
                trim((string) ($_POST['timezone'] ?? '')),
                trim((string) ($_POST['default_country'] ?? '')),
                trim((string) ($_POST['notes'] ?? '')) ?: null,
                $id,
            ]
        );
        flash('Profile updated.');
        redirect('client.php?id=' . $id);
    }

    if ($action === 'save_credentials') {
        $token = (string) ($_POST['access_token'] ?? '');
        $secret= (string) ($_POST['app_secret'] ?? '');
        $tokenEnc  = trim($token)  !== '' ? encrypt_secret(trim($token))  : $client['access_token_enc'];
        $secretEnc = trim($secret) !== '' ? encrypt_secret(trim($secret)) : $client['app_secret_enc'];
        try {
            $pnid = trim((string) ($_POST['phone_number_id'] ?? ''));
            db_run(
                "UPDATE clients SET app_id=?, phone_number_id=?, waba_id=?, access_token_enc=?, app_secret_enc=?, token_updated_at=NOW() WHERE id=?",
                [
                    trim((string) ($_POST['app_id'] ?? '')),
                    $pnid !== '' ? $pnid : null,   // NULL when empty so the unique index allows many
                    trim((string) ($_POST['waba_id'] ?? '')),
                    $tokenEnc, $secretEnc, $id,
                ]
            );
            flash('WhatsApp credentials saved. Use “Test connection” to verify.');
            redirect('client.php?id=' . $id . '#credentials');
        } catch (PDOException $ex) {
            if (str_contains($ex->getMessage(), 'uq_phone_number_id')) {
                $err = 'That Phone Number ID is already assigned to another client.';
            } else {
                error_log('save credentials failed: ' . $ex->getMessage());
                $err = 'Could not save credentials. Please try again.';
            }
        }
    }

    if ($action === 'add_credits') {
        $delta  = (int) ($_POST['delta'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? 'manual')) ?: 'manual';
        if ($delta !== 0) {
            $new = credits_adjust($id, $delta, $reason);
            if ($new === null) $err = 'Adjustment rejected (balance cannot go negative).';
            else { flash('Credits updated. New balance: ' . number_format($new) . '.'); redirect('client.php?id=' . $id . '#credits'); }
        }
    }

    if ($action === 'set_threshold') {
        db_run("UPDATE clients SET low_credit_threshold=? WHERE id=?", [max(0, (int) ($_POST['low_credit_threshold'] ?? 100)), $id]);
        flash('Low-credit threshold updated.');
        redirect('client.php?id=' . $id . '#credits');
    }

    if ($action === 'add_login_user') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass  = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
        elseif (strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
        elseif (db_val("SELECT COUNT(*) FROM users WHERE email=?", [$email])) $err = 'That email is already in use.';
        else {
            db_insert("INSERT INTO users (client_id,email,password_hash,role,status,created_at) VALUES (?,?,?, 'client','active',NOW())",
                [$id, $email, password_hash($pass, PASSWORD_DEFAULT)]);
            flash('Login user added.');
            redirect('client.php?id=' . $id . '#logins');
        }
    }

    if ($action === 'update_user') {
        $uid   = (int) ($_POST['user_id'] ?? 0);
        $u = db_row("SELECT * FROM users WHERE id=? AND client_id=?", [$uid, $id]);
        if ($u) {
            $email = strtolower(trim((string) ($_POST['email'] ?? $u['email'])));
            $pass  = (string) ($_POST['new_password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
            elseif ($email !== $u['email'] && db_val("SELECT COUNT(*) FROM users WHERE email=? AND id<>?", [$email, $uid])) $err = 'That email is already in use.';
            elseif ($pass !== '' && strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
            else {
                db_run("UPDATE users SET email=? WHERE id=?", [$email, $uid]);
                if ($pass !== '') db_run("UPDATE users SET password_hash=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $uid]);
                flash('Login updated.');
                redirect('client.php?id=' . $id . '#logins');
            }
        }
    }

    if ($action === 'delete_user') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $count = (int) db_val("SELECT COUNT(*) FROM users WHERE client_id=?", [$id]);
        if ($count <= 1) $err = 'Cannot delete the only login for this account.';
        else { db_run("DELETE FROM users WHERE id=? AND client_id=?", [$uid, $id]); flash('Login removed.'); redirect('client.php?id=' . $id . '#logins'); }
    }

    if ($action === 'delete_client') {
        if (strtolower(trim((string) ($_POST['confirm_name'] ?? ''))) === strtolower((string) $client['name'])) {
            db_run("DELETE FROM clients WHERE id=?", [$id]);   // cascades to users/contacts/campaigns/messages
            flash('Account "' . $client['name'] . '" deleted.');
            redirect('clients.php');
        }
        $err = 'Type the exact client name to confirm deletion.';
    }

    $client = db_row("SELECT * FROM clients WHERE id = ?", [$id]);
}

/* ── data for view ── */
$users   = db_all("SELECT * FROM users WHERE client_id=? ORDER BY id", [$id]);
$ledger  = credits_ledger($id, 40);
$contactCount = (int) db_val("SELECT COUNT(*) FROM contacts WHERE client_id=?", [$id]);
$tmplCount    = (int) db_val("SELECT COUNT(*) FROM templates WHERE client_id=?", [$id]);
$sentMonth    = (int) db_val("SELECT COUNT(*) FROM campaign_messages WHERE client_id=? AND status IN ('sent','delivered','read') AND sent_at >= DATE_FORMAT(NOW(),'%Y-%m-01')", [$id]);
$deliv = db_row("SELECT SUM(status IN ('sent','delivered','read')) s, SUM(status IN ('delivered','read')) d FROM campaign_messages WHERE client_id=?", [$id]);
$delivRate = ((int) ($deliv['s'] ?? 0)) > 0 ? round(100 * (int) $deliv['d'] / (int) $deliv['s']) : 0;
$lastCampaign = db_row("SELECT name, created_at FROM campaigns WHERE client_id=? ORDER BY id DESC LIMIT 1", [$id]);
$lastLogin    = db_val("SELECT MAX(last_login_at) FROM users WHERE client_id=?", [$id]);
$low = (int) $client['credits_balance'] < (int) $client['low_credit_threshold'];

layout_header('Client · ' . $client['name'], 'admin', 'clients');
?>
<div class="page-head">
  <h1><?= e($client['name']) ?></h1>
  <div class="page-actions" style="align-items:center">
    <span class="text-muted" style="font-size:12.5px">Account</span>
    <label class="switch"><input type="checkbox" id="status-switch" <?= $client['status'] === 'active' ? 'checked' : '' ?> onchange="toggleStatus(this)"><span class="slider"></span></label>
    <span id="status-text" class="pill <?= $client['status'] === 'active' ? 'green' : 'gray' ?> dot"><?= $client['status'] === 'active' ? 'Enabled' : 'Disabled' ?></span>
    <a class="btn btn-dark btn-sm" href="open_workspace.php?id=<?= $id ?>">Open Workspace ↗</a>
    <a class="btn btn-ghost btn-sm" href="clients.php">← All clients</a>
  </div>
</div>

<?php if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="stats-row">
  <div class="stat-tile"><span class="lbl">Credits</span><span class="val <?= $low ? 'danger' : 'accent' ?>"><?= number_format((int) $client['credits_balance']) ?></span><span class="sub"><?= $low ? 'below threshold' : 'available' ?></span></div>
  <div class="stat-tile"><span class="lbl">Sent (this month)</span><span class="val"><?= number_format($sentMonth) ?></span></div>
  <div class="stat-tile"><span class="lbl">Delivery Rate</span><span class="val"><?= $delivRate ?>%</span></div>
  <div class="stat-tile"><span class="lbl">Contacts</span><span class="val"><?= number_format($contactCount) ?></span><span class="sub"><?= $tmplCount ?> templates</span></div>
  <div class="stat-tile"><span class="lbl">Last Login</span><span class="val" style="font-size:15px;padding-top:8px"><?= $lastLogin ? e(date('d M, H:i', strtotime((string) $lastLogin))) : 'Never' ?></span></div>
  <div class="stat-tile"><span class="lbl">Last Campaign</span><span class="val" style="font-size:14px;padding-top:8px"><?= $lastCampaign ? e($lastCampaign['name']) : '—' ?></span><span class="sub"><?= $lastCampaign ? e(date('d M Y', strtotime((string) $lastCampaign['created_at']))) : '' ?></span></div>
</div>

<!-- ── Credentials ── -->
<div class="card" id="credentials">
  <h2>WhatsApp API Credentials</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 16px">Lets the dashboard send campaigns from this client's own number. Secrets are stored encrypted.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_credentials">
    <div class="grid2">
      <div class="field"><span class="lbl">App ID</span><input type="text" name="app_id" value="<?= e((string) $client['app_id']) ?>"></div>
      <div class="field"><span class="lbl">Phone Number ID</span><input type="text" name="phone_number_id" value="<?= e((string) $client['phone_number_id']) ?>"></div>
      <div class="field"><span class="lbl">WhatsApp Business Account ID</span><input type="text" name="waba_id" value="<?= e((string) $client['waba_id']) ?>"></div>
      <div class="field"><span class="lbl">Access Token <?= $client['access_token_enc'] ? '<span class="pill green" style="margin-left:6px">••• set</span>' : '' ?></span><input type="text" name="access_token" placeholder="<?= $client['access_token_enc'] ? 'Leave blank to keep current' : 'Permanent / system-user token' ?>" autocomplete="off"></div>
      <div class="field"><span class="lbl">App Secret <span class="text-muted">(optional)</span> <?= $client['app_secret_enc'] ? '<span class="pill green" style="margin-left:6px">••• set</span>' : '' ?></span><input type="text" name="app_secret" placeholder="<?= $client['app_secret_enc'] ? 'Leave blank to keep current' : 'For webhook verification' ?>" autocomplete="off"></div>
    </div>
    <div class="row-between mt10">
      <button type="button" class="btn btn-ghost" id="btn-test">Test connection</button>
      <button type="submit" class="btn btn-primary">Save Credentials</button>
    </div>
    <div id="test-result" class="mt10"></div>
  </form>
</div>

<!-- ── Credits ── -->
<div class="card" id="credits">
  <h2>Credits &amp; Billing</h2>
  <div class="row-between" style="align-items:flex-start;gap:24px;flex-wrap:wrap">
    <div style="min-width:280px">
      <div class="lbl" style="font-size:12px;font-weight:600;color:var(--brown);margin-bottom:8px">Quick top-up (balance: <?= number_format((int) $client['credits_balance']) ?>)</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        <?php foreach ([1000, 5000, 10000, 25000] as $pkg): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="add_credits"><input type="hidden" name="delta" value="<?= $pkg ?>"><input type="hidden" name="reason" value="package_<?= $pkg ?>"><button class="btn btn-dark btn-sm">+<?= number_format($pkg) ?></button></form>
        <?php endforeach; ?>
      </div>
      <form method="post" style="margin-bottom:16px">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_credits">
        <div class="field"><span class="lbl">Custom adjustment</span><input type="number" name="delta" placeholder="e.g. 1500 or -50" required><div class="hint">Positive adds, negative deducts.</div></div>
        <input type="text" name="reason" placeholder="Reason (optional)" style="margin-bottom:10px">
        <button class="btn btn-primary btn-sm">Apply</button>
      </form>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="set_threshold">
        <div class="field"><span class="lbl">Low-credit alert below</span><input type="number" name="low_credit_threshold" value="<?= (int) $client['low_credit_threshold'] ?>" min="0"></div>
        <button class="btn btn-ghost btn-sm">Save Threshold</button>
      </form>
    </div>
    <div style="flex:1;min-width:300px">
      <div class="lbl" style="font-size:12px;font-weight:600;color:var(--brown);margin-bottom:8px">Transaction history</div>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th>When</th><th>Change</th><th>Balance</th><th>Reason</th></tr></thead>
          <tbody>
          <?php if (!$ledger): ?><tr><td colspan="4"><div class="empty">No transactions yet.</div></td></tr><?php endif; ?>
          <?php foreach ($ledger as $t): ?>
            <tr>
              <td class="text-muted"><?= e(date('d M, H:i', strtotime((string) $t['created_at']))) ?></td>
              <td style="color:<?= (int) $t['delta'] >= 0 ? 'var(--success)' : 'var(--danger)' ?>"><?= (int) $t['delta'] >= 0 ? '+' : '' ?><?= number_format((int) $t['delta']) ?></td>
              <td><?= number_format((int) $t['balance_after']) ?></td>
              <td class="text-muted"><?= e((string) $t['reason']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ── Profile ── -->
<div class="card" id="profile">
  <h2>Account Profile</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_profile">
    <div class="grid2">
      <div class="field"><span class="lbl">Client / Brand Name</span><input type="text" name="name" value="<?= e((string) $client['name']) ?>"></div>
      <div class="field"><span class="lbl">Sender Display Name</span><input type="text" name="sender_display" value="<?= e((string) $client['sender_display']) ?>"></div>
      <div class="field"><span class="lbl">Company</span><input type="text" name="company" value="<?= e((string) $client['company']) ?>"></div>
      <div class="field"><span class="lbl">Business Category</span><input type="text" name="category" value="<?= e((string) $client['category']) ?>"></div>
      <div class="field"><span class="lbl">Contact Person</span><input type="text" name="contact_person" value="<?= e((string) $client['contact_person']) ?>"></div>
      <div class="field"><span class="lbl">Contact Phone</span><input type="text" name="contact_phone" value="<?= e((string) $client['contact_phone']) ?>"></div>
      <div class="field"><span class="lbl">Contact Email</span><input type="email" name="contact_email" value="<?= e((string) $client['contact_email']) ?>"></div>
      <div class="field"><span class="lbl">Timezone</span><input type="text" name="timezone" value="<?= e((string) $client['timezone']) ?>" placeholder="e.g. Africa/Cairo"></div>
      <div class="field"><span class="lbl">Default Country Code</span><input type="text" name="default_country" value="<?= e((string) $client['default_country']) ?>"></div>
    </div>
    <div class="field"><span class="lbl">Internal Notes</span><textarea name="notes" rows="2"><?= e((string) $client['notes']) ?></textarea></div>
    <button type="submit" class="btn btn-primary">Save Profile</button>
  </form>
</div>

<!-- ── Login users ── -->
<div class="card" id="logins">
  <div class="row-between" style="margin-bottom:14px">
    <h2 style="border:0;padding:0;margin:0">Login Users</h2>
    <button class="btn btn-ghost btn-sm" onclick="document.getElementById('m-adduser').classList.add('open')">+ Add Login</button>
  </div>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Email</th><th>Last Login</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= e((string) $u['email']) ?></td>
          <td class="text-muted"><?= $u['last_login_at'] ? e(date('d M Y, H:i', strtotime((string) $u['last_login_at']))) : 'Never' ?></td>
          <td class="text-muted"><?= e(date('d M Y', strtotime((string) $u['created_at']))) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <button class="btn-link" onclick='openEdit(<?= (int) $u['id'] ?>, <?= json_encode($u['email']) ?>)'>Edit</button>
            <?php if (count($users) > 1): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Remove this login?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><button class="icon-btn" title="Remove">&#x2715;</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Danger zone ── -->
<div class="card" style="border-color:rgba(192,57,43,.35)">
  <h2 style="color:var(--danger)">Delete Account</h2>
  <p class="text-muted" style="font-size:12.5px;margin-bottom:14px">Permanently deletes this client and <strong>all</strong> their contacts, lists, templates, campaigns, and message history. This cannot be undone.</p>
  <button class="btn btn-danger btn-sm" onclick="document.getElementById('m-delete').classList.add('open')">Delete this account</button>
</div>

<!-- Add login modal -->
<div class="modal-back" id="m-adduser">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_login_user">
    <h2>Add Login User</h2>
    <div class="field"><span class="lbl">Email</span><input type="email" name="email" required></div>
    <div class="field"><span class="lbl">Password (min 8)</span><input type="text" name="password" required minlength="8"></div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-adduser').classList.remove('open')">Cancel</button><button class="btn btn-primary">Add</button></div>
  </form>
</div>

<!-- Edit login modal -->
<div class="modal-back" id="m-edituser">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" id="eu-id">
    <h2>Edit Login</h2>
    <div class="field"><span class="lbl">Email</span><input type="email" name="email" id="eu-email" required></div>
    <div class="field"><span class="lbl">New Password <span class="text-muted">(leave blank to keep)</span></span><input type="text" name="new_password" minlength="8"></div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-edituser').classList.remove('open')">Cancel</button><button class="btn btn-primary">Save</button></div>
  </form>
</div>

<!-- Delete confirm modal -->
<div class="modal-back" id="m-delete">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_client">
    <h2 style="color:var(--danger)">Delete “<?= e($client['name']) ?>”?</h2>
    <p class="text-muted" style="font-size:13px;margin-bottom:14px">Type the client name <strong><?= e($client['name']) ?></strong> to confirm.</p>
    <div class="field"><input type="text" name="confirm_name" placeholder="<?= e($client['name']) ?>" autocomplete="off" required></div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-delete').classList.remove('open')">Cancel</button><button class="btn btn-danger">Delete permanently</button></div>
  </form>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
document.getElementById('btn-test').addEventListener('click', async function(){
  const box=document.getElementById('test-result'); this.disabled=true; this.textContent='Testing…'; box.innerHTML='';
  try{
    const fd=new FormData(); fd.append('action','test_connection'); fd.append('csrf_token',CSRF);
    const r=await fetch('',{method:'POST',body:fd}); const d=await r.json();
    box.innerHTML = d.ok
      ? '<div class="alert success">Connection OK — '+d.count+' template(s), '+d.approved+' approved.</div>'
      : '<div class="alert error">Connection failed: '+(d.error||'unknown error')+'</div>';
  }catch(e){ box.innerHTML='<div class="alert error">Network error.</div>'; }
  this.disabled=false; this.textContent='Test connection';
});
async function toggleStatus(el){
  const fd=new FormData(); fd.append('action','toggle_status'); fd.append('csrf_token',CSRF);
  const r=await fetch('',{method:'POST',body:fd}); const d=await r.json();
  if(d.ok){ const t=document.getElementById('status-text');
    t.textContent = d.status==='active'?'Enabled':'Disabled';
    t.className = 'pill dot '+(d.status==='active'?'green':'gray');
    showToast(d.status==='active'?'Account enabled.':'Account disabled.');
  } else { el.checked=!el.checked; showToast('Could not update.',true); }
}
function openEdit(uid, email){
  document.getElementById('eu-id').value=uid;
  document.getElementById('eu-email').value=email;
  document.getElementById('m-edituser').classList.add('open');
}
</script>

<?php layout_footer(); ?>
