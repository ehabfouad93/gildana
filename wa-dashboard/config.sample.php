<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is environment-specific and must NOT be committed / served.
 */
declare(strict_types=1);

return [
    // ── Database (MySQL / MariaDB) ──
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'wa_dashboard',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // ── Encryption key for stored WhatsApp credentials ──
    // Generate once with:  php -r "echo base64_encode(random_bytes(32));"
    'encryption_key' => 'CHANGE_ME_base64_32_bytes',

    // ── Meta / WhatsApp Cloud API ──
    'graph_version' => 'v21.0',

    // Graph API host. Leave this out in production — it defaults to the real endpoint.
    // Only set it to point at a mock/staging Graph API when testing locally.
    // 'graph_host' => 'http://127.0.0.1:8790',

    // Token that Meta must echo back when you configure the webhook (you choose it).
    'webhook_verify_token' => 'CHANGE_ME_pick_any_random_string',

    // Meta App Secret of the app subscribed to the webhook (used to verify the
    // X-Hub-Signature-256 on incoming events). Leave '' to skip verification.
    'app_secret' => '',

    // ── Sending throughput (per cron run == per minute) ──
    // Messages are sent in parallel. Raise these once your number's quality tier is
    // healthy; lower them if a new number needs a slower warm-up.
    'send_batch_per_run'    => 300,  // max messages per client per run
    'send_batch_global'     => 1000, // hard cap across all clients per run
    'send_parallel'         => 30,   // messages sent concurrently per HTTP burst
    'send_parallel_media'   => 10,   // lower concurrency for templates with an image/video/document header
    'no_answer_hours'       => 24,   // qualifier leads with no reply after N hours → "No answer"

    // ── Login CAPTCHA (needs the GD extension; auto-disables if GD is absent) ──
    'captcha_enabled' => true,

    // ── App ──
    'app_name'      => '',   // blank = use the built-in brand name (Revenect)
    'session_name'  => 'wa_dash',
    'admin_email'   => 'info@gildana.net', // where low-credit / completion alerts go
    'base_url'      => '', // e.g. https://app.gildana.net (used in emails). Empty = auto-detect.
];
