<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

/**
 * First-run installer: applies migrations, then creates the agency admin.
 * Self-disabling — once an admin exists it just redirects to the login.
 */

try {
    $ran = migrate();
} catch (Throwable $ex) {
    http_response_code(500);
    exit('Migration failed: ' . e($ex->getMessage()) . ' — check your database settings in config.php.');
}

if (admin_exists()) {
    flash(t('setup.done'));
    redirect('index.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name  = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');

    if ($name === '') {
        $err = t('setup.need_name');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = t('setup.bad_email');
    } elseif (strlen($pass) < 8) {
        $err = t('setup.short_pass');
    } else {
        db_run(
            "INSERT INTO users (client_id, name, email, password_hash, role, locale, status, created_at)
             VALUES (NULL, ?, ?, ?, 'admin', ?, 'active', NOW())",
            [$name, $email, password_hash($pass, PASSWORD_DEFAULT), locale()]
        );
        flash(t('ui.saved'));
        redirect('index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" dir="<?= e(locale_dir()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('setup.title')) ?> — <?= e(t('app.name')) ?></title>
<link rel="stylesheet" href="assets/listen.css?v=<?= @filemtime(__DIR__ . '/assets/listen.css') ?: '1' ?>">
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand">GILDANA</div>
  <div class="auth-tag"><?= e(t('setup.sub')) ?></div>

  <div class="alert success"><?= e(t('setup.db_ready', ['n' => (string) count($ran)])) ?></div>
  <?php if ($err !== ''): ?><div class="alert error"><?= e($err) ?></div><?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <div class="field">
      <span class="lbl"><?= e(t('setup.name')) ?></span>
      <input type="text" name="name" value="<?= old('name') ?>" required autofocus>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('auth.email')) ?></span>
      <input type="email" name="email" value="<?= old('email') ?>" required>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('auth.password')) ?></span>
      <input type="password" name="password" required minlength="8">
      <span class="hint"><?= e(t('setup.short_pass')) ?></span>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('setup.create')) ?></button>
  </form>
</div>
</body>
</html>
