<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/profile.php';

/**
 * Your own name, picture and password.
 *
 * These used to live on Settings, next to the company's WhatsApp connection and API credentials.
 * Once there are roles that made an impossible choice: deny Settings to a salesperson and they
 * cannot change their own password; grant it and they can relink the company's number. A
 * person's own profile belongs to that person, so it is its own page, open to every role.
 */

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['save_profile', 'clear_avatar'], true)) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'clear_avatar') { profile_clear_avatar((int) $ME['id']); flash('Picture removed.'); redirect('profile.php'); }
    $r = profile_save((int) $ME['id'], $_POST, $_FILES);
    if (!$r['ok']) $err = $r['error'];
    else { flash('Profile saved.'); redirect('profile.php'); }
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
        redirect('profile.php');
    }
}

client_header('My profile', 'profile', $CLIENT);
page_head('My profile');
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<?= profile_card_html(db_row("SELECT * FROM users WHERE id=?", [(int) $ME['id']]) ?: $ME, '../') ?>

<?php if (($PERM_USER['send_via'] ?? '') === 'own'):
  require_once __DIR__ . '/../includes/sending.php';
  $myUc = user_channel((int) $PERM_USER['id']); $linked = ($myUc['status'] ?? '') === 'connected'; ?>
<div class="card" id="my-wa-card">
  <h2>My WhatsApp</h2>
  <p class="text-muted" style="font-size:13px">Your admin set you up to message leads from your own phone.
    <?= $linked ? 'It is linked' . (!empty($myUc['msisdn']) ? ' (+' . e((string) $myUc['msisdn']) . ')' : '') . '.' : 'It is not linked yet, so you cannot send.' ?></p>
  <a class="btn <?= $linked ? 'btn-ghost' : 'btn-primary' ?> btn-sm" href="my_whatsapp.php"><?= $linked ? 'Manage' : 'Link my WhatsApp' ?></a>
</div>
<?php endif; ?>

<div class="card">
  <h2>Change Password</h2>
  <form method="post" style="max-width:420px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="field"><span class="lbl">Current Password</span><input type="password" name="current_password" required autocomplete="current-password"></div>
    <div class="field"><span class="lbl">New Password (min 8)</span><input type="password" name="new_password" required minlength="8" autocomplete="new-password"></div>
    <div class="field"><span class="lbl">Confirm New Password</span><input type="password" name="confirm_password" required autocomplete="new-password"></div>
    <button type="submit" class="btn btn-primary">Change Password</button>
  </form>
</div>
<?php layout_footer();
