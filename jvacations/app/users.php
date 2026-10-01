<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/** Team accounts. The role decides every screen and action the person gets. */
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid   = (int) ($_POST['id'] ?? 0);
    $name  = mb_substr(post_str('name'), 0, 120);
    $email = strtolower(post_str('email'));
    $role  = (string) ($_POST['role'] ?? '');
    $pass  = (string) ($_POST['password'] ?? '');
    $status = ($_POST['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';
    $err = '';

    if ($name === '')                                  $err = t('setup.need_name');
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = t('err.bad_email');
    elseif (!in_array($role, ROLES, true))             $err = t('err.bad_role');
    elseif (!$uid && strlen($pass) < 8)                $err = t('setup.short_pass');
    elseif ($uid && $pass !== '' && strlen($pass) < 8) $err = t('setup.short_pass');
    elseif (db_val("SELECT 1 FROM users WHERE email = ? AND id <> ?", [$email, $uid])) $err = t('users.email_taken');
    elseif ($uid === $ME['id'] && ($role !== 'admin' || $status !== 'active')) $err = t('users.self_lock');

    if ($err !== '') {
        flash($err, 'error');
        redirect('users.php' . ($uid ? '?edit=' . $uid : ''));
    }
    if ($uid) {
        db_run("UPDATE users SET name=?, email=?, phone=?, role=?, status=? WHERE id=?", [$name, $email, post_str('phone') ?: null, $role, $status, $uid]);
        if ($pass !== '') db_run("UPDATE users SET password_hash=? WHERE id=?", [password_hash($pass, PASSWORD_DEFAULT), $uid]);
    } else {
        db_insert("INSERT INTO users (name, email, phone, password_hash, role, locale, status, created_at) VALUES (?,?,?,?,?,?, 'active', NOW())",
            [$name, $email, post_str('phone') ?: null, password_hash($pass, PASSWORD_DEFAULT), $role, locale()]);
    }
    flash(t('ui.saved'));
    redirect('users.php');
}

$rows = db_all("SELECT * FROM users ORDER BY FIELD(role,'admin','advisor','booker','communicator','sales','accountant','owner_services'), name");
$edit = isset($_GET['edit']) ? db_row("SELECT * FROM users WHERE id = ?", [(int) $_GET['edit']]) : null;
$roleOpts = [];
foreach (ROLES as $r) $roleOpts[$r] = t('role.' . $r);

layout_header(t('nav.users'), 'users');
page_head(t('nav.users'), t('users.sub'));
?>
<div class="grid-main">
<div class="card card-flush">
  <div class="table-wrap"><table class="data">
    <thead><tr><th><?= e(t('setup.name')) ?></th><th><?= e(t('auth.email')) ?></th><th><?= e(t('users.role')) ?></th><th><?= e(t('users.last_login')) ?></th><th></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
      <tr>
        <td><strong><?= e($u['name']) ?></strong><?= $u['phone'] ? '<div class="small text-muted" dir="ltr">' . e($u['phone']) . '</div>' : '' ?></td>
        <td dir="ltr"><?= e($u['email']) ?></td>
        <td><span class="pill role-<?= e($u['role']) ?>"><?= e(t('role.' . $u['role'])) ?></span></td>
        <td class="text-muted small"><?= e(fmt_dt($u['last_login_at'])) ?></td>
        <td><?= $u['status'] === 'active' ? '<span class="pill green dot">' . e(t('ui.active')) . '</span>' : '<span class="pill gray dot">' . e(t('ui.inactive')) . '</span>' ?></td>
        <td><a class="btn btn-sm" href="?edit=<?= (int) $u['id'] ?>"><?= e(t('ui.edit')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<div>
<form method="post" class="card action-card">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <h2><?= e($edit ? t('users.edit') : t('users.add')) ?></h2>
  <div class="field"><span class="lbl"><?= e(t('setup.name')) ?> *</span><input type="text" name="name" value="<?= e($edit['name'] ?? '') ?>" required></div>
  <div class="field"><span class="lbl"><?= e(t('auth.email')) ?> *</span><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>" required dir="ltr"></div>
  <div class="field"><span class="lbl"><?= e(t('client.phone')) ?></span><input type="tel" name="phone" value="<?= e($edit['phone'] ?? '') ?>" dir="ltr"></div>
  <div class="field"><span class="lbl"><?= e(t('users.role')) ?> *</span><select name="role" required><?= options($roleOpts, $edit['role'] ?? '') ?></select></div>
  <div class="field"><span class="lbl"><?= e(t('auth.password')) ?><?= $edit ? '' : ' *' ?></span><input type="password" name="password" minlength="8" <?= $edit ? '' : 'required' ?> autocomplete="new-password">
    <span class="hint"><?= e($edit ? t('users.pass_keep') : t('setup.short_pass')) ?></span></div>
  <?php if ($edit): ?>
  <div class="field"><span class="lbl"><?= e(t('pay.status')) ?></span><select name="status"><?= options(['active' => t('ui.active'), 'disabled' => t('ui.inactive')], $edit['status'], false) ?></select></div>
  <?php endif; ?>
  <div class="form-actions">
    <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    <?php if ($edit): ?><a class="btn btn-ghost" href="users.php"><?= e(t('ui.cancel')) ?></a><?php endif; ?>
  </div>
</form>
<div class="card">
  <h2><?= e(t('users.roles_title')) ?></h2>
  <ul class="role-help">
    <?php foreach (ROLES as $r): ?><li><span class="pill role-<?= e($r) ?>"><?= e(t('role.' . $r)) ?></span> <?= e(t('role_help.' . $r)) ?></li><?php endforeach; ?>
  </ul>
</div>
</div>
</div>
<?php layout_footer();
