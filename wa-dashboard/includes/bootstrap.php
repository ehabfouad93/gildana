<?php
declare(strict_types=1);

/**
 * Single entry point for every page: loads config, helpers, db, crypto, auth,
 * and starts the session under this app's own name (isolated from the Gildana CMS).
 */
require __DIR__ . '/config_loader.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/brand.php';
require __DIR__ . '/crypto.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

/* Security headers, emitted here so every page gets them regardless of which web server
   is in front — the Docker/Traefik deployment and the nginx template alike. */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: DENY');                    // no embedding: clickjacking on the send controls
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header_remove('X-Powered-By');                      // don't advertise the PHP version

    // Only meaningful over TLS, and harmful if sent while still testing on plain http.
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

    /* Report-only to start with. The pages still carry inline <script> and style attributes,
       so enforcing this today would break the flow canvas and the charts; run it in
       report-only, clear the reports, then switch to Content-Security-Policy. */
    header("Content-Security-Policy-Report-Only: default-src 'self'; "
         . "img-src 'self' data: blob: https:; "
         . "script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com data:; "
         . "connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

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
