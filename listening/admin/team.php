<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/** Agency admin accounts. */

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass  = (string) ($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = t('setup.bad_email');
        } elseif (strlen($pass) < 8) {
            $err = t('setup.short_pass');
        } elseif (db_val("SELECT id FROM users WHERE email = ?", [$email])) {
            $err = t('st.email_taken');
        } else {
            db_run(
                "INSERT INTO users (client_id, name, email, password_hash, role, locale, status, created_at)
                 VALUES (NULL, ?, ?, ?, 'admin', ?, 'active', NOW())",
                [trim((string) ($_POST['name'] ?? '')), $email,
                 password_hash($pass, PASSWORD_DEFAULT), locale()]
            );
            flash(t('ui.saved'));
            redirect('team.php');
        }
    }

    if ($action === 'disable') {
        $uid = (int) ($_POST['id'] ?? 0);
        // Never let the last admin lock everyone out of the app.
        $active = (int) db_val("SELECT COUNT(*) FROM users WHERE role='admin' AND status='active'");
        if ($uid === (int) $ME['id']) {
            $err = 'You cannot disable your own account.';
        } elseif ($active <= 1) {
            $err = 'At least one active administrator is required.';
        } else {
            db_run("UPDATE users SET status='disabled' WHERE id=? AND role='admin'", [$uid]);
            flash(t('ui.saved'));
            redirect('team.php');
        }
    }

    if ($action === 'enable') {
        db_run("UPDATE users SET status='active' WHERE id=? AND role='admin'", [(int) ($_POST['id'] ?? 0)]);
        flash(t('ui.saved'));
        redirect('team.php');
    }

    if ($action === 'reset_password') {
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($pass) < 8) {
            $err = t('setup.short_pass');
        } else {
            db_run("UPDATE users SET password_hash=? WHERE id=? AND role='admin'",
                [password_hash($pass, PASSWORD_DEFAULT), (int) ($_POST['id'] ?? 0)]);
            flash(t('st.pass_changed'));
            redirect('team.php');
        }
    }
}

$admins = db_all("SELECT * FROM users WHERE role='admin' ORDER BY id");

$addBtn = '<button class="btn btn-primary" data-modal="m-admin">+ ' . e(t('ui.add')) . '</button>';

layout_header(t('nav.team'), 'admin', 'team');
page_head(t('nav.team'), '', $addBtn);

if ($err !== '') echo '<div class="alert error">' . e($err) . '</div>';
?>

<div class="card card-flush">
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th><?= e(t('auth.email')) ?></th><th><?= e(t('ui.name')) ?></th>
                 <th>Last login</th><th><?= e(t('ui.status')) ?></th><th></th></tr></thead>
      <tbody>
      <?php foreach ($admins as $u): ?>
        <tr>
          <td><?= e((string) $u['email']) ?>
            <?= (int) $u['id'] === (int) $ME['id'] ? '<span class="pill gold">you</span>' : '' ?></td>
          <td><?= e((string) $u['name']) ?></td>
          <td class="text-muted"><?= e(time_ago((string) $u['last_login_at'])) ?></td>
          <td><?= status_pill((string) $u['status'] === 'active' ? 'active' : 'paused') ?></td>
          <td class="nowrap">
            <form method="post" style="display:inline-flex;gap:6px;align-items:center">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <input type="password" name="password" placeholder="<?= e(t('st.new_pass')) ?>"
                     minlength="8" style="width:150px">
              <button class="btn btn-sm" type="submit"><?= e(t('ui.save')) ?></button>
            </form>
            <?php if ((int) $u['id'] !== (int) $ME['id']): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= (string) $u['status'] === 'active' ? 'disable' : 'enable' ?>">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn btn-sm btn-ghost" type="submit">
                  <?= e((string) $u['status'] === 'active' ? t('ui.paused') : t('ui.active')) ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal-back" id="m-admin">
  <form class="modal" method="post">
    <button type="button" class="modal-x">&times;</button>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <h2><?= e(t('ui.add')) ?></h2>
    <div class="field">
      <span class="lbl"><?= e(t('ui.name')) ?></span>
      <input type="text" name="name">
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('auth.email')) ?></span>
      <input type="email" name="email" required>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('auth.password')) ?></span>
      <input type="password" name="password" required minlength="8">
    </div>
    <div class="modal-actions">
      <button type="button" class="btn btn-ghost modal-x" style="position:static"><?= e(t('ui.cancel')) ?></button>
      <button class="btn btn-primary" type="submit"><?= e(t('ui.save')) ?></button>
    </div>
  </form>
</div>

<?php layout_footer(); ?>
