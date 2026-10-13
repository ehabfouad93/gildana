<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is environment-specific and must NOT be committed or served.
 */
declare(strict_types=1);

return [
    // ── Database (MySQL / MariaDB) ──
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'jvacations',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // Business time zone: decides "today" for due dates and overdue instalments.
    'timezone' => 'Africa/Cairo',

    // Isolated session cookie name — keeps this app's login separate from the
    // other Gildana apps when they share a parent domain.
    'session_name' => 'jv_session',

    // Default UI language for users who have not chosen one: 'en' or 'ar'.
    'default_locale' => 'ar',
];
