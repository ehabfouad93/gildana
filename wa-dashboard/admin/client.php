<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/channel.php';

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

/* ── AJAX: resync the personal-channel gateway webhook ──
   For a number that's already linked but whose replies never reach the Inbox — the gateway
   silently drops the webhook config on any reconnect of an existing instance (see
   pw_set_webhook()). This re-registers it without disturbing the linked session. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pw_resync') {
    verify_csrf();
    if (!channel_is_personal($client)) json_out(['ok' => false, 'error' => 'This account sends through the WhatsApp Cloud API.']);
    $r = pw_set_webhook($client);
    json_out(['ok' => $r['ok'], 'error' => $r['error']]);
}

/* Replace this client's inbound webhook secret. Their WhatsApp session is untouched — they
   stay connected and keep sending and receiving throughout, so this is safe to do whenever a
   secret has been exposed, without asking them to do anything. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pw_rotate') {
    verify_csrf();
    if (!channel_is_personal($client)) json_out(['ok' => false, 'error' => 'This account sends through the WhatsApp Cloud API.']);
    $r = pw_rotate_hook_secret($client);
    json_out(['ok' => $r['ok'], 'error' => $r['error']]);
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

    if ($action === 'save_channel') {
        $ch = ($_POST['channel'] ?? 'cloud') === 'personal' ? 'personal' : 'cloud';
        // Clamp the pacing: a slot of 0 would stall sending, and an unbounded slot would
        // defeat the protection this channel exists to provide.
        $size  = max(1, min(50, (int) ($_POST['slot_size'] ?? 15)));
        $pause = max(30, min(3600, (int) ($_POST['slot_pause_sec'] ?? 180)));
        db_run("UPDATE clients SET channel=?, slot_size=?, slot_pause_sec=? WHERE id=?", [$ch, $size, $pause, $id]);
        flash($ch === 'personal'
            ? 'Switched to the client\'s own WhatsApp number. They now link it from their Settings.'
            : 'Switched to the WhatsApp Cloud API.');
        redirect('client.php?id=' . $id . '#channel');
    }

    if ($action === 'save_billing') {
        $planId = (int) ($_POST['plan_id'] ?? 0) ?: null;
        if ($planId !== null && !db_row("SELECT id FROM plans WHERE id=?", [$planId])) $planId = null;
        $mode = ($_POST['waba_mode'] ?? 'byo') === 'platform' ? 'platform' : 'byo';
        db_run("UPDATE clients SET plan_id=?, waba_mode=?, overage_allowed=?,
                       plan_started_at=COALESCE(plan_started_at, IF(? IS NULL, NULL, NOW()))
                 WHERE id=?",
            [$planId, $mode, !empty($_POST['overage_allowed']) ? 1 : 0, $planId, $id]);
        // A brand-new subscription gets its first month's credits straight away.
        $fresh = db_row("SELECT * FROM clients WHERE id=?", [$id]);
        if ($planId !== null && empty($fresh['plan_renews_at'])) billing_renew($fresh);
        flash('Billing updated.');
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
                "UPDATE clients SET app_id=?, phone_number_id=?, waba_id=?, access_token_enc=?, app_secret_enc=?,
                        require_signed_webhook=?, token_updated_at=NOW() WHERE id=?",
                [
                    trim((string) ($_POST['app_id'] ?? '')),
                    $pnid !== '' ? $pnid : null,   // NULL when empty so the unique index allows many
                    trim((string) ($_POST['waba_id'] ?? '')),
                    $tokenEnc, $secretEnc,
                    // Can only be enforced once there is a secret to check against.
                    ($secretEnc !== '' && $secretEnc !== null && !empty($_POST['require_signed_webhook'])) ? 1 : 0,
                    $id,
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
    <?= guide_button('clients') ?>
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

<!-- ── Plan & WhatsApp account ── -->
<?php
$allPlans = db_all("SELECT * FROM plans WHERE is_active=1 ORDER BY sort, price_month");
$onPlat   = ($client['waba_mode'] ?? 'byo') === 'platform';
$period   = billing_period_start();
$use      = db_row("SELECT * FROM usage_periods WHERE client_id=? AND period_start=?", [$id, $period]);
?>
<div class="card" id="billing">
  <h2>Plan &amp; WhatsApp account</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_billing">
    <div class="grid3">
      <div class="field"><span class="lbl">Plan</span>
        <select name="plan_id">
          <option value="0">— no plan —</option>
          <?php foreach ($allPlans as $pl): ?>
            <option value="<?= (int) $pl['id'] ?>" <?= (int) ($client['plan_id'] ?? 0) === (int) $pl['id'] ? 'selected' : '' ?>>
              <?= e((string) $pl['name']) ?> — <?= number_format((float) $pl['price_month'], 2) ?>/mo,
              <?= number_format((int) $pl['included_credits']) ?> credits
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!$allPlans): ?>
          <span class="text-muted" style="font-size:11.5px"><a href="plans.php">Create a plan first →</a></span>
        <?php endif; ?>
      </div>
      <div class="field"><span class="lbl">WhatsApp account</span>
        <select name="waba_mode">
          <option value="byo"      <?= $onPlat ? '' : 'selected' ?>>Their own — Meta bills them</option>
          <option value="platform" <?= $onPlat ? 'selected' : '' ?>>Yours — you pay Meta and rebill</option>
        </select>
      </div>
      <div class="field"><label style="display:flex;gap:8px;align-items:center;font-weight:normal;margin-top:22px">
        <input type="checkbox" name="overage_allowed" value="1" <?= !empty($client['overage_allowed']) ? 'checked' : '' ?> style="width:auto">
        Let them keep sending past their included credits</label></div>
    </div>

    <div class="note" style="font-size:12.5px">
      <?php if ($onPlat): ?>
        This client sends on <strong>your</strong> WhatsApp account, so every message is a cost you
        carry. They are charged from the <a href="rates.php">rate table</a> by destination country
        and message type, plus your markup. Replies within 24 hours are free from Meta and are
        charged as a single platform credit.
      <?php else: ?>
        This client uses <strong>their own</strong> WhatsApp Business Account, so Meta invoices them
        directly and you carry no message cost. Their credits are simply your platform fee —
        one per message, whatever it costs them.
      <?php endif; ?>
    </div>

    <?php if ($use): ?>
      <div class="text-muted" style="font-size:12.5px;margin-top:10px">
        This month: <?= number_format((int) $use['messages_sent']) ?> messages ·
        <?= number_format((int) $use['credits_used']) ?> credits<?php if ((float) $use['platform_cost'] > 0): ?> ·
        costing you <?= number_format((float) $use['platform_cost'], 4) ?><?php endif; ?>
      </div>
    <?php endif; ?>

    <button type="submit" class="btn btn-primary mt10">Save plan</button>
  </form>
</div>

<!-- ── Sending channel ── -->
<?php $isPersonal = ($client['channel'] ?? 'cloud') === 'personal'; ?>
<div class="card" id="channel">
  <h2>Sending Channel</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    How this account's messages leave the system. Campaigns, Automations, the Lead Qualifier
    and the Inbox all follow this setting.
  </p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_channel">
    <div class="field"><span class="lbl">Channel</span>
      <select name="channel" id="ch-sel" onchange="chToggle()">
        <option value="cloud" <?= $isPersonal ? '' : 'selected' ?>>WhatsApp Cloud API (official)</option>
        <option value="personal" <?= $isPersonal ? 'selected' : '' ?>>Client's own personal number</option>
      </select>
    </div>

    <div id="ch-personal" style="display:<?= $isPersonal ? 'block' : 'none' ?>">
      <div class="alert error" style="font-size:12.5px">
        <strong>Read this before switching.</strong> Sending from a personal number goes through a
        WhatsApp Web session, which is against WhatsApp's Terms — the number can be banned,
        and the risk rises the faster and more uniformly it sends. The limits below are the
        protection: messages go out in small batches with a pause between them, and one batch is
        shared across campaigns, automations and the qualifier. There are no approved templates
        and no 24-hour window on this channel, so templates are sent as plain text.
      </div>
      <div class="grid2">
        <div class="field"><span class="lbl">Messages per batch</span>
          <input type="number" name="slot_size" min="1" max="50" value="<?= (int) ($client['slot_size'] ?: 15) ?>">
          <span class="text-muted" style="font-size:11.5px">15 is a sensible default.</span></div>
        <div class="field"><span class="lbl">Pause between batches (seconds)</span>
          <input type="number" name="slot_pause_sec" min="30" max="3600" value="<?= (int) ($client['slot_pause_sec'] ?: 180) ?>">
          <span class="text-muted" style="font-size:11.5px">180 = 3 minutes.</span></div>
      </div>
      <div class="field"><span class="lbl">Connection</span>
        <?php
          $pwSt = (string) ($client['personal_status'] ?? 'disconnected');
          $pill = $pwSt === 'connected' ? 'green' : 'amber';
          $lbl  = $pwSt === 'connected'
                ? 'Linked' . ($client['personal_msisdn'] ? ' · +' . e((string) $client['personal_msisdn']) : '')
                : ($pwSt === 'qr_pending' ? 'Waiting for the QR to be scanned' : 'Not linked yet');
        ?>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span class="pill <?= $pill ?>"><?= $lbl ?></span>
          <?php if ($pwSt === 'connected'): ?>
            <button type="button" class="btn-link" onclick="pwAdminResync()">Resync webhook</button>
            <button type="button" class="btn-link" onclick="pwAdminRotate()">Rotate webhook secret</button>
            <span id="pw-admin-resync-msg" class="text-muted" style="font-size:11.5px"></span>
          <?php endif; ?>
        </div>
        <span class="text-muted" style="font-size:11.5px">The client links their phone themselves under
          <strong>Settings → My WhatsApp Number</strong>. You never handle their number.
          If they report replies not showing up in the Inbox although they're linked, use
          <strong>Resync webhook</strong> — it repairs the gateway's callback without disconnecting them.</span>
        <span class="text-muted" style="font-size:11.5px;display:block;margin-top:6px">
          <strong>Rotate webhook secret</strong> issues a new inbound address if the old one was
          exposed — in a screenshot, a log, a support thread. The client is not interrupted:
          they stay connected, nothing is rescanned, and no message is lost.
          <?php $rot = $client['personal_hook_rotated_at'] ?? null; ?>
          Last rotated: <?= $rot ? e(date('j M Y, H:i', strtotime((string) $rot))) : 'never' ?>.
          To do this for every client at once, run
          <code>php deploy/rotate-hook-secrets.php</code> on the server.</span>
      </div>
    </div>
    <button type="submit" class="btn btn-primary mt10">Save channel</button>
  </form>
</div>
<script>
function chToggle(){ document.getElementById('ch-personal').style.display =
  document.getElementById('ch-sel').value === 'personal' ? 'block' : 'none'; }
</script>

<!-- ── Credentials ── -->
<div class="card" id="credentials" style="<?= $isPersonal ? 'opacity:.55' : '' ?>">
  <?php if ($isPersonal): ?>
    <div class="alert info" style="font-size:12.5px">Not used while this account sends from its own number — kept in case you switch back.</div>
  <?php endif; ?>
  <h2>WhatsApp API Credentials</h2>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 16px">Lets the dashboard send campaigns from this client's own number. Secrets are stored encrypted.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_credentials">
    <div class="grid2">
      <div class="field"><span class="lbl">App ID</span><input type="text" name="app_id" value="<?= e((string) $client['app_id']) ?>"></div>
      <div class="field"><span class="lbl">Phone Number ID</span><input type="text" name="phone_number_id" value="<?= e((string) $client['phone_number_id']) ?>"></div>
      <div class="field"><span class="lbl">WhatsApp Business Account ID</span><input type="text" name="waba_id" value="<?= e((string) $client['waba_id']) ?>"></div>
      <div class="field"><span class="lbl">Access Token <?= $client['access_token_enc'] ? '<span class="pill green" style="margin-left:6px">••• set</span>' : '' ?></span><input type="text" name="access_token" placeholder="<?= $client['access_token_enc'] ? 'Leave blank to keep current' : 'Permanent / system-user token' ?>" autocomplete="off"></div>
      <div class="field"><span class="lbl">App Secret <?= $client['app_secret_enc'] ? '<span class="pill green" style="margin-left:6px">••• set</span>' : '<span class="pill red" style="margin-left:6px">not set</span>' ?></span><input type="text" name="app_secret" placeholder="<?= $client['app_secret_enc'] ? 'Leave blank to keep current' : 'From this client\'s Meta app → Settings → Basic' ?>" autocomplete="off"></div>
    </div>
    <?php /* Each client has their own Meta app, so each callback is signed with THAT app's
             secret — there is no single platform-wide value that could verify them all. */ ?>
    <div class="note mt10" style="font-size:13px">
      <?php if (!$client['app_secret_enc']): ?>
        <strong>Webhook callbacks from this client are not verified.</strong>
        Without their App Secret, anyone who finds the webhook URL can forge delivery reports,
        create contacts and trigger this client's automations. Paste the App Secret from
        <em>their</em> Meta app (Settings → Basic) above.
      <?php else: ?>
        <label class="row" style="gap:8px;align-items:center">
          <input type="checkbox" name="require_signed_webhook" value="1" <?= $client['require_signed_webhook'] ? 'checked' : '' ?>>
          <span>Reject callbacks that aren't correctly signed by this client's Meta app.</span>
        </label>
      <?php endif; ?>
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
async function pwAdminResync(){
  const m = document.getElementById('pw-admin-resync-msg'); if(!m) return;
  m.textContent = 'Resyncing…';
  const fd = new FormData(); fd.append('action','pw_resync'); fd.append('csrf_token',CSRF);
  try{
    const r = await fetch('', {method:'POST', body:fd}); const d = await r.json();
    m.textContent = d.ok ? 'Done — the gateway webhook was re-registered.' : 'Failed: '+(d.error||'unknown error');
  }catch(e){ m.textContent = 'Network error.'; }
}
async function pwAdminRotate(){
  const m = document.getElementById('pw-admin-resync-msg'); if(!m) return;
  if(!confirm('Issue a new webhook secret for this client?\n\nThey stay connected and will not notice — nothing is rescanned and no message is lost.')) return;
  m.textContent = 'Rotating…';
  const fd = new FormData(); fd.append('action','pw_rotate'); fd.append('csrf_token',CSRF);
  try{
    const r = await fetch('', {method:'POST', body:fd}); const d = await r.json();
    m.textContent = d.ok ? 'Done — new secret in use, the client was not interrupted.'
                         : 'Failed: '+(d.error||'unknown error')+' (the old secret still works).';
    if(d.ok) setTimeout(()=>location.reload(), 1200);
  }catch(e){ m.textContent = 'Network error.'; }
}
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
