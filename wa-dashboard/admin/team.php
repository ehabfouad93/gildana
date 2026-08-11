<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$me  = current_user();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_admin') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass  = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
        elseif (strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
        elseif (db_val("SELECT COUNT(*) FROM users WHERE email=?", [$email])) $err = 'That email is already in use.';
        else {
            db_insert("INSERT INTO users (client_id,email,password_hash,role,status,created_at) VALUES (NULL,?,?, 'admin','active',NOW())",
                [$email, password_hash($pass, PASSWORD_DEFAULT)]);
            flash('Admin user added.');
            redirect('team.php');
        }
    }

    if ($action === 'toggle_admin') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $u = db_row("SELECT * FROM users WHERE id=? AND role='admin'", [$uid]);
        if ($u && $uid !== (int) $me['id']) {
            $new = $u['status'] === 'active' ? 'disabled' : 'active';
            // never disable the last active admin
            if ($new === 'disabled' && (int) db_val("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'") <= 1) {
                $err = 'Cannot disable the last active admin.';
            } else {
                db_run("UPDATE users SET status=? WHERE id=?", [$new, $uid]);
                flash('Admin ' . ($new === 'active' ? 'enabled' : 'disabled') . '.');
                redirect('team.php');
            }
        } else $err = 'You cannot change your own status here.';
    }

    if ($action === 'reset_admin_pw') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $new = (string) ($_POST['new_password'] ?? '');
        $u = db_row("SELECT id FROM users WHERE id=? AND role='admin'", [$uid]);
        if (!$u) $err = 'Admin not found.';
        elseif (strlen($new) < 8) $err = 'Password must be at least 8 characters.';
        else { db_run("UPDATE users SET password_hash=? WHERE id=?", [password_hash($new, PASSWORD_DEFAULT), $uid]); flash('Password reset.'); redirect('team.php'); }
    }

    if ($action === 'delete_admin') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        if ($uid === (int) $me['id']) $err = 'You cannot delete your own account.';
        elseif ((int) db_val("SELECT COUNT(*) FROM users WHERE role='admin'") <= 1) $err = 'Cannot delete the last admin.';
        else { db_run("DELETE FROM users WHERE id=? AND role='admin'", [$uid]); flash('Admin removed.'); redirect('team.php'); }
    }
}

$admins = db_all("SELECT * FROM users WHERE role='admin' ORDER BY id");

$addBtn = '<button class="btn btn-primary" onclick="document.getElementById(\'m-add\').classList.add(\'open\')">+ Add Admin</button>';
layout_header('Team', 'admin', 'team');
page_head('Team — Admin Users', $addBtn);
if ($err): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Email</th><th>Status</th><th>Last Login</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($admins as $u): $self = (int) $u['id'] === (int) $me['id']; ?>
        <tr>
          <td><?= e((string) $u['email']) ?><?php if ($self): ?> <span class="pill gold">you</span><?php endif; ?></td>
          <td><?= $u['status'] === 'active' ? '<span class="pill green dot">Active</span>' : '<span class="pill gray dot">Disabled</span>' ?></td>
          <td class="text-muted"><?= $u['last_login_at'] ? e(date('d M Y, H:i', strtotime((string) $u['last_login_at']))) : 'Never' ?></td>
          <td class="text-muted"><?= e(date('d M Y', strtotime((string) $u['created_at']))) ?></td>
          <td style="text-align:right;white-space:nowrap">
            <button class="btn-link" onclick='openReset(<?= (int) $u['id'] ?>, <?= json_encode($u['email']) ?>)'>Reset password</button>
            <?php if (!$self): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_admin"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><button class="btn-link"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete this admin?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><button class="icon-btn" title="Delete">&#x2715;</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="m-add">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="add_admin">
    <h2>Add Admin User</h2>
    <div class="field"><span class="lbl">Email</span><input type="email" name="email" required></div>
    <div class="field"><span class="lbl">Password (min 8)</span><input type="text" name="password" required minlength="8"><div class="hint">Share with the new admin.</div></div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-add').classList.remove('open')">Cancel</button><button class="btn btn-primary">Add Admin</button></div>
  </form>
</div>

<div class="modal-back" id="m-reset">
  <form class="modal" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_admin_pw"><input type="hidden" name="user_id" id="r-id">
    <h2>Reset Password</h2>
    <p class="text-muted" style="font-size:12.5px;margin-bottom:12px" id="r-email"></p>
    <div class="field"><span class="lbl">New Password (min 8)</span><input type="text" name="new_password" required minlength="8"></div>
    <div class="modal-actions"><button type="button" class="btn btn-ghost" onclick="document.getElementById('m-reset').classList.remove('open')">Cancel</button><button class="btn btn-primary">Reset</button></div>
  </form>
</div>

<script>
function openReset(id, email){
  document.getElementById('r-id').value = id;
  document.getElementById('r-email').textContent = email;
  document.getElementById('m-reset').classList.add('open');
}
</script>

<?php layout_footer(); ?>
