<?php
declare(strict_types=1);

/**
 * Single entry point for every web page: loads config, helpers, db, auth,
 * i18n and the domain rules, then starts an isolated session.
 */
require __DIR__ . '/config_loader.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/i18n.php';
require __DIR__ . '/domain.php';
require __DIR__ . '/caps.php';
require __DIR__ . '/whatsapp.php';

date_default_timezone_set((string) config('timezone', 'Africa/Cairo'));

if (session_status() !== PHP_SESSION_ACTIVE) {
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
    session_name((string) config('session_name', 'jv_session'));
    session_start();
}
