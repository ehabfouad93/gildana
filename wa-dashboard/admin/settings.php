<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require __DIR__ . '/../includes/push.php';

$me  = current_user();
$err = ''; $ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $user = db_row("SELECT * FROM users WHERE id=?", [(int) $me['id']]);

    // Generate the VAPID keypair from the browser — the client deploys by ZIP and never
    // edits PHP, so there is no practical way to run a CLI script.
    if ($action === 'gen_vapid') {
        $keys = push_vapid_keys(true);
        if ($keys) { $ok = 'Push notification keys generated. Clients can now enable notifications.'; }
        else { $err = 'Could not generate keys — this server\'s PHP is missing EC support in OpenSSL.'; }
    }

    if ($action === 'update_email') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
        elseif ($email !== $user['email'] && db_val("SELECT COUNT(*) FROM users WHERE email=? AND id<>?", [$email, (int) $me['id']])) $err = 'That email is already in use.';
        else { db_run("UPDATE users SET email=? WHERE id=?", [$email, (int) $me['id']]); $_SESSION['email'] = $email; flash('Email updated.'); redirect('settings.php'); }
    }

    if ($action === 'change_password') {
        $cur  = (string) ($_POST['current_password'] ?? '');
        $new  = (string) ($_POST['new_password'] ?? '');
        $conf = (string) ($_POST['confirm_password'] ?? '');
        if (!password_verify($cur, $user['password_hash'])) $err = 'Current password is incorrect.';
        elseif (strlen($new) < 8) $err = 'New password must be at least 8 characters.';
        elseif ($new !== $conf) $err = 'New passwords do not match.';
        else { db_run("UPDATE users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_DEFAULT), (int) $me['id']]); flash('Password changed.'); redirect('settings.php'); }
    }
}

$user = db_row("SELECT * FROM users WHERE id=?", [(int) $me['id']]);

layout_header('Settings', 'admin', 'settings');
page_head('My Settings');
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="card">
  <h2>Profile</h2>
  <form method="post" style="max-width:420px">
    <?= csrf_field() ?><input type="hidden" name="action" value="update_email">
    <div class="field"><span class="lbl">Admin Email</span><input type="email" name="email" value="<?= e((string) $user['email']) ?>" required></div>
    <button class="btn btn-primary">Save Email</button>
  </form>
</div>

<div class="card">
  <h2>Push Notifications</h2>
  <?php
    $vk = push_vapid_keys(false);
    $subCount = 0;
    try { $subCount = (int) db_val("SELECT COUNT(*) FROM push_subscriptions"); } catch (Throwable $e) {}
  ?>
  <p class="text-muted" style="font-size:12.5px;margin:-6px 0 14px">
    One keypair per installation. Clients then turn notifications on per device in their own Settings.
  </p>
  <?php if ($vk): ?>
    <div class="alert success" style="margin-bottom:12px">
      Keys are set up · <strong><?= $subCount ?></strong> device<?= $subCount === 1 ? '' : 's' ?> subscribed.
    </div>
    <div class="field"><span class="lbl">Public key</span>
      <input type="text" class="mono" value="<?= e($vk['public']) ?>" readonly onclick="this.select()"></div>
    <p class="text-muted" style="font-size:12px">The private key is stored encrypted and never shown.</p>
  <?php else: ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="gen_vapid">
      <button type="submit" class="btn btn-primary">Generate keys</button>
    </form>
    <p class="text-muted" style="font-size:12px;margin-top:8px">Run this once. Regenerating later would silently break every device already subscribed.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Change Password</h2>
  <form method="post" style="max-width:420px">
    <?= csrf_field() ?><input type="hidden" name="action" value="change_password">
    <div class="field"><span class="lbl">Current Password</span><input type="password" name="current_password" required></div>
    <div class="field"><span class="lbl">New Password (min 8)</span><input type="password" name="new_password" required minlength="8"></div>
    <div class="field"><span class="lbl">Confirm New Password</span><input type="password" name="confirm_password" required></div>
    <button class="btn btn-primary">Change Password</button>
  </form>
</div>

<?php layout_footer(); ?>
