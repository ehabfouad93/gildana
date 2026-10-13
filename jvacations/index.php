<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// First run: no admin yet → go create one.
if (!admin_exists()) {
    redirect('setup.php');
}

if (current_user()) {
    redirect('app/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = (string) ($_POST['email'] ?? '');
    $pass  = (string) ($_POST['password'] ?? '');
    $ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $key   = strtolower(trim($email));

    $wait = login_lock_seconds($ip, $key);
    if ($wait > 0) {
        $error = t('auth.throttled', ['n' => (string) (int) ceil($wait / 60)]);
    } elseif (attempt_login($email, $pass)) {
        login_clear($ip, $key);
        redirect('app/dashboard.php');
    } else {
        login_record_fail($ip, $key);
        $error = t('auth.wrong');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(locale()) ?>" dir="<?= e(locale_dir()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e(t('auth.sign_in')) ?> — <?= e(setting('company_name')) ?></title>
<?php require_once __DIR__ . '/includes/view.php'; echo brand_head(); ?>
<link rel="stylesheet" href="assets/jv.css?v=<?= @filemtime(__DIR__ . '/assets/jv.css') ?: '1' ?>">
</head>
<body class="auth-body">
<div class="auth-visual" aria-hidden="true"></div>
<div class="auth-side">
<div class="auth-card">
  <img class="auth-logo" src="assets/brand/logo-mark.png" alt="">
  <div class="auth-brand"><?= e(setting('company_name')) ?></div>
  <div class="auth-title"><?= e(t('auth.sign_in')) ?></div>
  <div class="auth-sub"><?= e(setting('company_name') . ' — ' . t('app.tagline')) ?></div>
  <div class="auth-tag"><?= e(t('app.tagline')) ?></div>

  <?php if ($error !== ''): ?>
    <div class="alert error"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <div class="field">
      <span class="lbl"><?= e(t('auth.email')) ?></span>
      <input type="email" name="email" value="<?= old('email') ?>" required autofocus>
    </div>
    <div class="field">
      <span class="lbl"><?= e(t('auth.password')) ?></span>
      <input type="password" name="password" required>
    </div>
    <button class="btn btn-primary" type="submit"><?= e(t('auth.sign_in')) ?></button>
  </form>
  <div class="auth-foot">
    <a href="set_lang.php?to=en&amp;return=index.php">English</a> ·
    <a href="set_lang.php?to=ar&amp;return=index.php">العربية</a>
  </div>
</div>
</div>
</body>
</html>
