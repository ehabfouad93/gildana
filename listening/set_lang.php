<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

/**
 * EN/AR toggle. Persists to the session and, when logged in, to users.locale.
 * The return path is validated as a same-app relative path so this cannot be
 * used as an open redirect.
 */
set_locale((string) ($_GET['to'] ?? ''));

$return = (string) ($_GET['return'] ?? '');
$return = urldecode($return);

// Reject anything absolute, protocol-relative, or containing a traversal.
if ($return === ''
    || preg_match('#^[a-z][a-z0-9+.-]*:#i', $return)
    || str_starts_with($return, '//')
    || str_contains($return, '..')) {
    $u = current_user();
    $return = $u ? ($u['role'] === 'admin' ? 'admin/index.php' : 'client/index.php') : 'index.php';
}

redirect($return);
