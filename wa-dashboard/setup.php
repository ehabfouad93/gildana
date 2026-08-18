<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// Run migrations (safe to call repeatedly).
$ran = [];
try {
    $ran = migrate();
} catch (Throwable $ex) {
    error_log('migration failed: ' . $ex->getMessage());
    http_response_code(500);
    exit('Database setup failed. Check the server error log and your database settings in config.php.');
}

// If an admin already exists, setup is done.
if (admin_exists()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');
    $conf  = (string) ($_POST['confirm'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email.';
    } elseif (strlen($pass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($pass !== $conf) {
        $error = 'Passwords do not match.';
    } else {
        db_insert(
            "INSERT INTO users (client_id, email, password_hash, role, status, created_at)
             VALUES (NULL, ?, ?, 'admin', 'active', NOW())",
            [$email, password_hash($pass, PASSWORD_DEFAULT)]
        );
        flash('Admin account created. Please sign in.');
        redirect('index.php');
    }
}

$appName = (string) config('app_name', 'WhatsApp Dashboard');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?= pwa_head('./') ?>
<title>Setup — <?= e($appName) ?></title>
<link rel="stylesheet" href="assets/dashboard.css?v=<?= @filemtime(__DIR__ . '/assets/dashboard.css') ?: '7' ?>">
</head>
<body class="login-screen">
  <form class="login-card" method="post">
    <?= csrf_field() ?>
    <div class="login-brand">GILDANA</div>
    <h1>First-run Setup — Create Admin</h1>
    <?php if ($ran): ?>
      <div class="alert success">Database ready (<?= count($ran) ?> migration<?= count($ran) === 1 ? '' : 's' ?> applied).</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>
    <div class="field">
      <span class="lbl">Admin Email</span>
      <input type="email" name="email" value="<?= old('email') ?>" required autofocus>
    </div>
    <div class="field">
      <span class="lbl">Password (min 8 chars)</span>
      <input type="password" name="password" required>
    </div>
    <div class="field">
      <span class="lbl">Confirm Password</span>
      <input type="password" name="confirm" required>
    </div>
    <button type="submit" class="btn btn-primary">Create Admin &amp; Continue</button>
  </form>
<?= pwa_script('./') ?>
</body>
</html>
