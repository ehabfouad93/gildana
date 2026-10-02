<?php
/**
 * Apply pending database migrations, then stop. Safe to run repeatedly.
 *
 *   docker exec jvacations php deploy/docker/migrate.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

$root = dirname(__DIR__, 2);
chdir($root);

if (!file_exists("$root/config.php")) {
    exit("❌ No config.php yet — run deploy/docker/make-config.php first.\n");
}

require_once "$root/includes/config_loader.php";
require_once "$root/includes/helpers.php";
require_once "$root/includes/db.php";
date_default_timezone_set((string) config('timezone', 'Africa/Cairo'));

$c = config('db');
echo "  db: {$c['user']}@{$c['host']}/{$c['name']}\n";

try {
    $ran = migrate();
} catch (Throwable $e) {
    fwrite(STDERR, "\n❌ Migration failed: " . $e->getMessage() . "\n"
        . "   Fix the cause and run this again — files that already succeeded are skipped.\n");
    exit(1);
}

// Uploaded contract templates are written by Apache (www-data); `git pull` as root
// creates the folder root-owned, so hand it over on every run (no-op when already right).
if (!is_dir("$root/storage/templates")) @mkdir("$root/storage/templates", 0775, true);
foreach (["$root/storage", "$root/storage/templates"] as $dir) {
    @chown($dir, 'www-data'); @chgrp($dir, 'www-data'); @chmod($dir, 0775);
}

echo $ran ? '✓ applied ' . count($ran) . " migration(s):\n    " . implode("\n    ", $ran) . "\n" : "✓ already up to date\n";
echo '✓ tables: ' . count(db_all('SHOW TABLES')) . "\n";
