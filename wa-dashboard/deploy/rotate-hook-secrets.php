<?php
declare(strict_types=1);

/**
 * Replace the inbound webhook secret of every connected personal-number client.
 *
 *     php deploy/rotate-hook-secrets.php            # all personal clients
 *     php deploy/rotate-hook-secrets.php 12 15      # only these client ids
 *
 * Nobody is disconnected and nobody rescans a QR. Rotating the secret only changes where the
 * gateway posts inbound messages; the linked WhatsApp session is untouched, so clients keep
 * sending and receiving right through it and never know it happened.
 *
 * Run this after a secret has been exposed — in a screenshot, a log, a support thread.
 * Secrets are never printed, so this script's own output is safe to paste anywhere.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

require __DIR__ . '/../includes/config_loader.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/crypto.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/personal_wa.php';

if (!pw_configured()) {
    fwrite(STDERR, "The WhatsApp gateway is not configured — nothing to rotate.\n");
    exit(1);
}

$only = array_values(array_filter(array_map('intval', array_slice($argv, 1))));
$sql  = "SELECT * FROM clients WHERE channel='personal' AND status='active'";
$args = [];
if ($only) {
    $sql .= ' AND id IN (' . implode(',', array_fill(0, count($only), '?')) . ')';
    $args = $only;
}
$clients = db_all($sql . ' ORDER BY id', $args);

if (!$clients) { echo "No active personal-number clients to rotate.\n"; exit(0); }

echo "\n Rotating webhook secrets for " . count($clients) . " client(s).\n";
echo " Nobody is disconnected; sending and receiving continue throughout.\n";
echo str_repeat('─', 68), "\n";

$done = 0; $failed = 0;
foreach ($clients as $c) {
    $label = sprintf('#%-4d %s', (int) $c['id'], mb_strimwidth((string) $c['name'], 0, 28, '…'));
    $wasConnected = ($c['personal_status'] ?? '') === 'connected';

    $r = pw_rotate_hook_secret($c);
    if (!empty($r['ok'])) {
        $done++;
        // Prove the session survived — that is the whole promise of doing it this way.
        $still = (string) db_val("SELECT personal_status FROM clients WHERE id=?", [(int) $c['id']]);
        $note  = $wasConnected && $still === 'connected' ? 'still connected' : 'status: ' . ($still ?: 'unknown');
        echo "  ok    {$label}  ({$note})\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}  {$r['error']}\n";
        echo "        Its previous secret is still in place, so inbound keeps working.\n";
    }
}

echo str_repeat('─', 68), "\n";
if ($failed) {
    echo " {$done} rotated, {$failed} failed. The failures kept their old secret and are\n";
    echo " still receiving — fix the gateway error above and run this again.\n\n";
    exit(1);
}
echo " {$done} rotated. The exposed secrets no longer work.\n\n";
exit(0);
