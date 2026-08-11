<?php
declare(strict_types=1);
/**
 * Background worker: finalizes async video jobs (Kling).
 * Run once a minute:
 *   * * * * * php /path/to/ai-studio/cron/worker.php >/dev/null 2>&1
 * Or hit over HTTP with the worker token:
 *   https://YOUR-DOMAIN/ai-studio/cron/worker.php?token=<worker_token>
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $token = (string) ($_GET['token'] ?? '');
    if (!hash_equals((string) config('worker_token', ''), $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    ignore_user_abort(true);
    header('Content-Type: text/plain');
}

@set_time_limit(300);
$done = studio_process_open_jobs(30);
echo "Processed jobs: {$done}\n";
