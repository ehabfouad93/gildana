<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $name  = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass  = (string) ($_POST['password'] ?? '');
        $role  = ($_POST['role'] ?? 'member') === 'admin' ? 'admin' : 'member';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            flash('Enter a name, valid email, and 8+ char password.', 'error');
        } elseif (db_val("SELECT id FROM users WHERE email=?", [$email])) {
            flash('That email is already in use.', 'error');
        } else {
            db_insert("INSERT INTO users (name,email,password_hash,role,status,created_at) VALUES (?,?,?,?, 'active', NOW())",
                [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $role]);
            flash('Team member added.');
        }
    } elseif ($action === 'toggle') {
        $uid = (int) ($_POST['uid'] ?? 0);
        if ($uid !== $USER['id']) {
            db_run("UPDATE users SET status = IF(status='active','disabled','active') WHERE id=?", [$uid]);
            flash('Member updated.');
        } else { flash("You can't disable yourself.", 'error'); }
    }
    redirect('team.php');
}

$users = db_all("SELECT * FROM users ORDER BY id ASC");
layout_header('Team', 'team');
page_head('Team', 'People who can build campaigns and generate assets.');
?>
<section class="panel">
  <h2 class="sec-title">Add member</h2>
  <form method="post" class="grid2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <label class="fld"><span>Name</span><input name="name" required></label>
    <label class="fld"><span>Email</span><input type="email" name="email" required></label>
    <label class="fld"><span>Password (min 8)</span><input type="password" name="password" required></label>
    <label class="fld"><span>Role</span><select name="role"><option value="member">Member</option><option value="admin">Admin</option></select></label>
    <div><button class="btn primary" type="submit">Add member</button></div>
  </form>
</section>

<table class="tbl">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['name']) ?></td>
      <td><?= e($u['email']) ?></td>
      <td><span class="pill <?= $u['role'] === 'admin' ? 'gold' : 'gray' ?>"><?= e(ucfirst($u['role'])) ?></span></td>
      <td><span class="pill <?= $u['status'] === 'active' ? 'green' : 'red' ?>"><?= e(ucfirst($u['status'])) ?></span></td>
      <td><?= $u['last_login_at'] ? e(date('M j, H:i', strtotime((string) $u['last_login_at']))) : '—' ?></td>
      <td><?php if ((int) $u['id'] !== $USER['id']): ?>
        <form method="post" style="display:inline"><?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle"><input type="hidden" name="uid" value="<?= (int) $u['id'] ?>">
          <button class="btn tiny ghost"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
        </form>
      <?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php layout_footer();
