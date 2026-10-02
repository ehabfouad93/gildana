<?php
/**
 * Re-register the gateway webhook for every linked personal number — the company phones and each
 * salesperson's own — so they also send delivery and read receipts (MESSAGES_UPDATE). Linked
 * sessions are not touched: nobody needs to rescan a QR. Prints names and results, never secrets.
 *
 *   docker exec revenect php /var/www/html/deploy/docker/resync-personal-webhooks.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

$root = dirname(__DIR__, 2);
chdir($root);
require $root . '/includes/config_loader.php';
require $root . '/includes/helpers.php';
require $root . '/includes/crypto.php';
require $root . '/includes/db.php';
require_once $root . '/includes/personal_wa.php';
require_once $root . '/includes/sending.php';

$ok = 0; $bad = 0;
foreach (db_all("SELECT * FROM clients WHERE personal_instance IS NOT NULL AND personal_instance<>'' AND COALESCE(personal_hook_secret,'')<>''") as $c) {
    $r = pw_set_webhook($c);
    echo ($r['ok'] ? '✓ ' : '✗ ') . $c['name'] . ' (company phone)' . ($r['ok'] ? '' : ' — ' . $r['error']) . "\n";
    $r['ok'] ? $ok++ : $bad++;
}
try {
    foreach (db_all("SELECT uc.*, u.email FROM user_channels uc JOIN users u ON u.id=uc.user_id WHERE uc.instance IS NOT NULL AND uc.instance<>''") as $uc) {
        $base = db_row("SELECT * FROM clients WHERE id=?", [(int) $uc['client_id']]);
        if (!$base) continue;
        $r = pw_set_webhook(user_channel_client($base, $uc));
        echo ($r['ok'] ? '✓ ' : '✗ ') . $base['name'] . ' — ' . $uc['email'] . ($r['ok'] ? '' : ' — ' . $r['error']) . "\n";
        $r['ok'] ? $ok++ : $bad++;
    }
} catch (Throwable $e) { echo "(salesperson phones skipped: " . $e->getMessage() . ")\n"; }
echo "\nDone: $ok updated, $bad failed.\n";
exit($bad ? 1 : 0);
