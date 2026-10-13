<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is environment-specific and must NOT be committed or served.
 */
declare(strict_types=1);

return [
    'app_name' => 'Gildana Listening',

    // ── Database (MySQL / MariaDB) ──
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'gildana_listening',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ── Encryption key for stored API keys and tokens ──
    // Generate once with:  php -r "echo base64_encode(random_bytes(32));"
    // Back this up. Losing it means every stored key must be re-entered.
    'encryption_key' => 'CHANGE_ME_base64_32_bytes',

    // Isolated session cookie name — keeps this app's login separate from
    // wa-dashboard and ai-studio when they share a parent domain.
    'session_name' => 'gild_listen',

    // Public base URL, no trailing slash, e.g. https://listen.gildana.net
    'base_url' => '',

    // Default UI language for users who have not chosen one: 'en' or 'ar'.
    'default_locale' => 'en',

    // Token guarding cron/worker.php when it is hit over HTTP instead of CLI.
    'worker_token' => 'CHANGE_ME_pick_any_random_string',

    // Where alert and digest emails come from, and where agency-level
    // notifications (a source failing, the worker stalling) are sent.
    'admin_email' => 'info@gildana.net',
    'mail_from'   => 'noreply@gildana.net',

    // Meta Graph API version used by the Facebook / Instagram connectors.
    'graph_version' => 'v21.0',

    // Identifies this app to the sites we poll. Several feeds (Reddit above all)
    // reject requests that arrive with a default or absent User-Agent.
    'user_agent' => 'GildanaListening/1.0 (+https://listen.gildana.net)',

    // Per-worker-run budgets. The host's cron may only fire every 5 minutes,
    // so each pass is bounded to keep a tick well inside the window.
    'worker' => [
        'deadline_seconds'  => 200,  // total wall-clock budget for one tick
        'sources_per_run'   => 12,
        'lexicon_per_run'   => 300,
        'ai_per_run'        => 40,
        'ai_batch_size'     => 10,   // mentions per AI request
    ],

    // Offline connector testing: point this at a directory of recorded API
    // responses (see tests/fixtures) and every outbound HTTP call is served
    // from disk instead of the network. Leave '' in production.
    'fixtures_dir' => '',
];
