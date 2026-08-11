<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// First run: no admin yet → go create one.
if (!admin_exists()) {
    redirect('setup.php');
}

// Already logged in → route to the right area.
$u = current_user();
if ($u) {
    redirect($u['role'] === 'admin' ? 'admin/index.php' : 'client/index.php');
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
        $error = 'Too many failed attempts. Try again in ' . (int) ceil($wait / 60) . ' minute(s).';
    } elseif (captcha_enabled() && !check_captcha((string) ($_POST['captcha'] ?? ''))) {
        $error = 'Incorrect code. Please try again.';
    } elseif (attempt_login($email, $pass)) {
        login_clear($ip, $key);
        $u = current_user();
        redirect($u['role'] === 'admin' ? 'admin/index.php' : 'client/index.php');
    } else {
        login_record_fail($ip, $key);
        $error = 'Wrong email or password.';
    }
}

$appName = (string) config('app_name', 'WhatsApp Dashboard');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign in — <?= e($appName) ?></title>
<link rel="stylesheet" href="assets/dashboard.css?v=<?= @filemtime(__DIR__ . '/assets/dashboard.css') ?: '7' ?>">
</head>
<body class="login-screen">
  <form class="login-card" method="post">
    <?= csrf_field() ?>
    <div class="login-brand">GILDANA</div>
    <h1>WhatsApp Campaign Dashboard</h1>
    <?php if (isset($_GET['disabled'])): ?>
      <div class="alert error">This account is disabled. Contact Gildana.</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
    <div class="field">
      <span class="lbl">Email</span>
      <input type="email" name="email" value="<?= old('email') ?>" required autofocus>
    </div>
    <div class="field">
      <span class="lbl">Password</span>
      <input type="password" name="password" required>
    </div>
    <?php if (captcha_enabled()): ?>
    <div class="field">
      <span class="lbl">Security Code</span>
      <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
        <img src="captcha.php" alt="CAPTCHA" id="captcha-img" width="200" height="70" style="border-radius:8px;border:1px solid var(--line-strong)">
        <button type="button" class="btn-link" title="New code" onclick="document.getElementById('captcha-img').src='captcha.php?r='+Date.now()">&#8635; New</button>
      </div>
      <input type="text" name="captcha" autocomplete="off" required maxlength="6" placeholder="Enter the code above">
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary">Sign In</button>
  </form>
</body>
</html>
