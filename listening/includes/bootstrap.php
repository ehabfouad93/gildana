<?php
declare(strict_types=1);

/**
 * Single entry point for every web page: loads config, helpers, i18n, crypto,
 * db and auth, then starts an isolated session.
 * Cron scripts do NOT use this file — they require the pieces they need directly,
 * so no session is ever started on the CLI.
 */
require __DIR__ . '/config_loader.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/crypto.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/i18n.php';

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
    session_name((string) config('session_name', 'gild_listen'));
    session_start();
}
