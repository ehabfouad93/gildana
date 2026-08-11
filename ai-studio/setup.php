<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// Run migrations (idempotent).
$ran = [];
try { $ran = migrate(); } catch (Throwable $e) {
    http_response_code(500);
    exit('Migration failed: ' . e($e->getMessage()) . ' — check your database settings in config.php.');
}

// If an admin already exists, setup is done.
if (admin_exists()) { flash('Setup already completed. Please sign in.'); redirect('index.php'); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name  = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pass  = (string) ($_POST['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
        $error = 'Enter a name, a valid email, and a password of at least 8 characters.';
    } else {
        db_insert(
            "INSERT INTO users (name, email, password_hash, role, status, created_at) VALUES (?,?,?, 'admin','active', NOW())",
            [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]
        );
        flash('Admin account created. Sign in to continue.');
        redirect('index.php');
    }
}
$appName = (string) config('app_name', 'AI Studio');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup — <?= e($appName) ?></title>
<link rel="stylesheet" href="assets/studio.css?v=<?= @filemtime(__DIR__ . '/assets/studio.css') ?: '1' ?>">
</head>
<body class="auth-body">
<div class="auth-card">
  <div class="auth-brand"><?= e($appName) ?></div>
  <p class="auth-tag">Create your admin account</p>
  <?php if ($ran): ?><div class="alert success">Database ready (<?= count($ran) ?> migration<?= count($ran) === 1 ? '' : 's' ?> applied).</div><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="auth-form">
    <?= csrf_field() ?>
    <label>Your name<input type="text" name="name" required autofocus value="<?= old('name') ?>"></label>
    <label>Email<input type="email" name="email" required value="<?= old('email') ?>"></label>
    <label>Password (min 8)<input type="password" name="password" required></label>
    <button class="btn primary block" type="submit">Create admin & continue</button>
  </form>
</div>
</body>
</html>
