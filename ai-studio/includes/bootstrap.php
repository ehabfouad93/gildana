<?php
declare(strict_types=1);

/**
 * Single entry point for every page: loads config, helpers, crypto, db, auth,
 * the provider registry + adapters, and starts an isolated session.
 */
require __DIR__ . '/config_loader.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/crypto.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/providers.php';
require __DIR__ . '/adapters.php';
require __DIR__ . '/generate.php';

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
    session_name((string) config('session_name', 'ai_studio'));
    session_start();
}
