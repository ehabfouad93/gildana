<?php
/**
 * Generate config.php inside the container, then run the migrations.
 *
 * Reads its values from the environment so a password containing shell-special
 * characters (+, /, =, spaces) can't be mangled on the way in — which is exactly what
 * happens when the same values are pasted into a long sed/php one-liner.
 *
 *   docker exec -e DB_PASS='...' -e APP_DOMAIN='...' revenect php deploy/docker/make-config.php
 *
 * Never overwrites an existing config.php: it holds the encryption key that decrypts
 * every stored WhatsApp and AI token.
 */
declare(strict_types=1);

// Operator tooling. deploy/ is inside the document root in the Docker image, so this
// must refuse to run over HTTP the way preflight.php and rotate-hook-secrets.php do.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

$root = dirname(__DIR__, 2);          // /var/www/html
chdir($root);

$dbHost = getenv('DB_HOST') ?: 'revenect-db';
$dbName = getenv('DB_NAME') ?: 'revenect';
$dbUser = getenv('DB_USER') ?: 'revenect';
$dbPass = getenv('DB_PASS') ?: '';
$domain = getenv('APP_DOMAIN') ?: '';

if ($dbPass === '') exit("❌ DB_PASS is empty. Pass it with:  docker exec -e DB_PASS='...' …\n");
if ($domain === '') exit("❌ APP_DOMAIN is empty. Pass it with:  docker exec -e APP_DOMAIN='...' …\n");

if (file_exists("$root/config.php")) {
    echo "ℹ config.php already exists — left untouched.\n";
} else {
    $s = file_get_contents("$root/config.sample.php");
    $q = fn(string $v): string => "'" . str_replace("'", "\\'", $v) . "'";

    // Replace only the first occurrence of each db key, inside the db block.
    foreach ([['host', $dbHost], ['name', $dbName], ['user', $dbUser], ['pass', $dbPass]] as [$k, $v]) {
        $s = preg_replace("/('" . $k . "'\s*=>\s*)'[^']*'/", '${1}' . $q($v), $s, 1);
    }
    $s = str_replace('CHANGE_ME_base64_32_bytes', base64_encode(random_bytes(32)), $s);
    $s = str_replace('CHANGE_ME_pick_any_random_string', bin2hex(random_bytes(12)), $s);
    $s = preg_replace("/('base_url'\s*=>\s*)''/", '${1}' . $q("https://$domain"), $s, 1);

    file_put_contents("$root/config.php", $s);
    echo "✓ config.php written (fresh encryption key)\n";
}

// docker exec runs as root, so anything created here is root-owned. Apache serves as
// www-data, and a root-owned 0640 config.php gives it "Permission denied" on every
// request — so hand ownership over explicitly rather than relying on the default.
$own = function (string $path, int $mode): void {
    if (!file_exists($path)) return;
    @chown($path, 'www-data');
    @chgrp($path, 'www-data');
    @chmod($path, $mode);
};
$own("$root/config.php", 0640);                 // secrets: owner-readable only
foreach (['uploads', 'assets/brand', 'cron'] as $dir) {
    if (!is_dir("$root/$dir")) @mkdir("$root/$dir", 0775, true);
    $own("$root/$dir", 0775);                   // the app writes here at runtime
}
echo "✓ permissions set for www-data\n";

require "$root/includes/config_loader.php";
require "$root/includes/helpers.php";
require "$root/includes/crypto.php";
require "$root/includes/db.php";

$c = config('db');
echo "  db: {$c['user']}@{$c['host']}/{$c['name']}\n";

$ran = migrate();
echo '✓ migrations: ' . ($ran ? count($ran) . ' applied' : 'already up to date') . "\n";
echo '✓ tables: ' . count(db_all('SHOW TABLES')) . "\n";
echo decrypt_secret(encrypt_secret('ok')) === 'ok' ? "✓ encryption works\n" : "❌ encryption broken\n";
echo "\nDone. Open https://$domain\n";
