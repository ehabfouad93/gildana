<?php
/**
 * Apply any pending database migrations, then stop.
 *
 *   docker exec revenect php deploy/docker/migrate.php
 *
 * This is the routine step after a `git pull` that ships new .sql files. make-config.php also
 * migrates, but it exists to BUILD a config and refuses to start without DB_PASS and
 * APP_DOMAIN — which means re-running it just to migrate meant re-supplying secrets on the
 * command line, where they land in shell history. Migrating needs neither: config.php is
 * already on disk and already holds the credentials.
 *
 * Safe to run repeatedly. migrate() records each file in schema_migrations and skips anything
 * already applied, so running it on an up-to-date install does nothing and says so.
 */
declare(strict_types=1);

// Operator tooling. deploy/ sits inside the document root in the Docker image, so this must
// refuse to run over HTTP the way make-config.php, preflight.php and verify-migration.php do —
// otherwise the schema is one unauthenticated request away from anyone who guesses the path.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

$root = dirname(__DIR__, 2);          // /var/www/html
chdir($root);

if (!file_exists("$root/config.php")) {
    exit("❌ No config.php yet — run deploy/docker/make-config.php first.\n");
}

require "$root/includes/config_loader.php";
require "$root/includes/helpers.php";
require "$root/includes/crypto.php";
require "$root/includes/db.php";

$db = config('db');
echo "  db: {$db['user']}@{$db['host']}/{$db['name']}\n";

try {
    $ran = migrate();
} catch (Throwable $e) {
    // A half-applied migration is worth shouting about: the code is already live and expecting
    // the new columns, so a silent failure here looks like the app being broken at random.
    fwrite(STDERR, "\n❌ Migration failed: " . $e->getMessage() . "\n"
        . "   The schema may be partly applied. Fix the cause and run this again —\n"
        . "   files that already succeeded are recorded and will be skipped.\n");
    exit(1);
}

if (!$ran) {
    echo "✓ already up to date\n";
    exit(0);
}
echo '✓ applied ' . count($ran) . " migration(s):\n";
foreach ($ran as $f) echo "    $f\n";
