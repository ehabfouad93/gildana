<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is environment-specific and must NOT be committed / served.
 */
declare(strict_types=1);

return [
    'app_name' => 'AI Studio',

    // ── Database (MySQL / MariaDB) ──
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'ai_studio',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ── Encryption key for stored provider API keys ──
    // Generate once with:  php -r "echo base64_encode(random_bytes(32));"
    'encryption_key' => 'CHANGE_ME_base64_32_bytes',

    // Isolated session cookie name.
    'session_name' => 'ai_studio',

    // Public base URL, no trailing slash, e.g. https://studio.gildana.net
    // Required for Instagram publishing (Meta must fetch generated media by URL)
    // and for building download links in emails/reports.
    'base_url' => '',

    // Token guarding the cron worker endpoint when hit over HTTP.
    'worker_token' => 'CHANGE_ME_pick_any_random_string',

    // Optional global fallback keys. Leave blank to require per-workspace keys
    // entered through the Provider Keys screen (recommended). If set, these are
    // used when a workspace has not stored its own key for that provider.
    'fallback_keys' => [
        'openai'    => '',   // sk-...
        'anthropic' => '',   // sk-ant-...
        'stability' => '',   // sk-...
        // Kling and Meta always come from the Provider Keys screen (multi-field).
    ],

    // Max upload / generated file size to keep (bytes). Default 25 MB.
    'max_asset_bytes' => 26214400,
];
