<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$me  = current_user();
$err = ''; $ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $user = db_row("SELECT * FROM users WHERE id=?", [(int) $me['id']]);

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
