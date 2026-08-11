<?php
declare(strict_types=1);

/**
 * Automation worker. Run once per minute:
 *   * * * * * php /path/wa-dashboard/cron/automation.php
 * Or over HTTP: /wa-dashboard/cron/automation.php?token=<webhook_verify_token>
 *
 * Resumes timer waits (wait steps) and polls Google-Sheet lead flows.
 * Single-runner via MySQL GET_LOCK, like cron/dispatch.php.
 */

require __DIR__ . '/../includes/config_loader.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/crypto.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/whatsapp.php';
require __DIR__ . '/../includes/credits.php';
require __DIR__ . '/../includes/ai.php';
require __DIR__ . '/../includes/notify.php';
require __DIR__ . '/../includes/campaign.php';
require __DIR__ . '/../includes/automation.php';

if (PHP_SAPI !== 'cli') {
    if (!hash_equals((string) config('webhook_verify_token'), (string) ($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

$pdo = db();
if ((int) $pdo->query("SELECT GET_LOCK('wa_automation', 0)")->fetchColumn() !== 1) {
    echo "Another automation run is active.\n";
    exit;
}

try {
    $resumed = automation_tick();
    $leads   = automation_ingest_sheets();
    echo '[' . date('H:i:s') . "] Automation: resumed={$resumed} sheet_leads_started={$leads}.\n";
} finally {
    $pdo->query("SELECT RELEASE_LOCK('wa_automation')");
}
