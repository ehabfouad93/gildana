<?php
/**
 * Copy the gateway API key from deploy/docker/.env into the dashboard, and report whether it
 * actually authenticates.
 *
 * Rotating the key means changing it in two places — the container's environment and the
 * dashboard's stored setting — and doing only one leaves the gateway answering "Unauthorized"
 * with nothing to say which half is stale. Worse, the manual route means displaying the key on
 * a terminal to copy it, which is how it ends up in a screenshot.
 *
 * The app container already has deploy/docker/.env mounted, so it can read the key itself.
 * Nothing here prints it.
 *
 *   docker exec revenect php /var/www/html/deploy/docker/sync-gateway-key.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);                     // …/wa-dashboard
chdir($root);
require $root . '/includes/config_loader.php';
require $root . '/includes/helpers.php';
require $root . '/includes/crypto.php';
require $root . '/includes/db.php';
require_once $root . '/includes/push.php';       // setting_get / setting_set
require_once $root . '/includes/whatsapp.php';
require_once $root . '/includes/personal_wa.php';

function line(string $s): void { echo $s . "\n"; }

$envPath = $root . '/deploy/docker/.env';
if (!is_readable($envPath)) {
    line("✗ Cannot read {$envPath}");
    line("  Run this inside the app container, e.g.:");
    line("  docker exec revenect php /var/www/html/deploy/docker/sync-gateway-key.php");
    exit(1);
}

$key = '';
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    if (preg_match('/^\s*EVOLUTION_API_KEY\s*=\s*(.*)$/', $l, $m)) {
        $key = trim($m[1], " \t\"'");            // tolerate quoting and stray spaces
    }
}
if ($key === '') {
    line("✗ EVOLUTION_API_KEY is not set in deploy/docker/.env");
    exit(1);
}
line("• Key found in .env (" . strlen($key) . " characters)");

$stored = decrypt_secret((string) (setting_get('pw_api_key', '') ?? ''));
if ($stored === $key) {
    line("• The dashboard already has this exact key — nothing to change");
} else {
    setting_set('pw_api_key', encrypt_secret($key));
    line($stored === '' ? "• Stored the key in the dashboard (none was set)"
                        : "• Updated the dashboard: it was holding a DIFFERENT key");
}

// The base URL and header only get filled in when blank, so a deliberate override is kept.
if (trim((string) (setting_get('pw_base_url', '') ?? '')) === '') {
    setting_set('pw_base_url', 'http://evolution-api:8080');
    line("• Base URL was empty → set to http://evolution-api:8080");
}
if (trim((string) (setting_get('pw_auth_header', '') ?? '')) === '') {
    setting_set('pw_auth_header', 'apikey');
    line("• Auth header was empty → set to apikey");
}

// Prove it end to end rather than declaring success on a database write.
line("");
line("Testing the gateway…");
$res = pw_request('GET', '/instance/fetchInstances', null, 10);
if ($res['error'] === '' && $res['http'] >= 200 && $res['http'] < 300) {
    $n = is_array($res['json']) ? count($res['json']) : 0;
    line("✓ Gateway accepted the key. {$n} instance(s) on it.");
    line("  Go to Settings → My WhatsApp Number and press Connect my WhatsApp.");
    exit(0);
}
line("✗ Gateway said: " . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['http']));
line("");
if (stripos($res['error'], 'unauthor') !== false || $res['http'] === 401 || $res['http'] === 403) {
    line("  The dashboard and .env now match, so the CONTAINER is the stale one —");
    line("  it is still running with the previous key. Recreate it:");
    line("");
    line("  cd /opt/gildana/wa-dashboard/deploy/docker && \\");
    line("    docker compose -f docker-compose.revenect.yml -f docker-compose.gateway.yml \\");
    line("    up -d --force-recreate evolution-api");
} else {
    line("  That is not an auth failure — the gateway is unreachable or still starting.");
    line("  Check:  docker ps  ·  docker logs --tail 40 evolution-api");
}
exit(1);
