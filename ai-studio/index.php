<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// First run → setup.
if (!admin_exists()) redirect('setup.php');

// Already logged in → app.
if (current_user()) redirect('app/index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');
    $ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $lock  = login_lock_seconds($ip, $email);
    if ($lock > 0) {
        $error = 'Too many attempts. Try again in ' . ceil($lock / 60) . ' min.';
    } elseif (attempt_login($email, $pass)) {
        login_clear($ip, $email);
        redirect('app/index.php');
    } else {
        login_record_fail($ip, $email);
        $error = 'Invalid email or password.';
    }
}
$appName = (string) config('app_name', 'AI Studio');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — <?= e($appName) ?></title>
<link rel="stylesheet" href="assets/studio.css?v=<?= @filemtime(__DIR__ . '/assets/studio.css') ?: '1' ?>">
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><?= e($appName) ?></div>
  <p class="auth-tag">AI Creative Orchestrator</p>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="auth-form">
    <?= csrf_field() ?>
    <label>Email<input type="email" name="email" required autofocus value="<?= old('email') ?>"></label>
    <label>Password<input type="password" name="password" required></label>
    <button class="btn primary block" type="submit">Sign in</button>
  </form>
</div>
</body>
</html>
