<?php
declare(strict_types=1);

/**
 * Single entry point for every page: loads config, helpers, db, crypto, auth,
 * and starts the session under this app's own name (isolated from the Gildana CMS).
 */
require __DIR__ . '/config_loader.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/crypto.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Harden the session cookie: not readable by JS, sent same-site, and
    // marked Secure automatically when the request arrives over HTTPS.
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $secure,
    ]);
    session_name((string) config('session_name', 'wa_dash'));
    session_start();
}
