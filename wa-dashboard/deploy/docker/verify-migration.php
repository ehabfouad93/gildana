<?php
/**
 * Post-migration check: did the data arrive, and can the encrypted secrets still be read?
 *
 *   docker exec revenect php deploy/docker/verify-migration.php
 *
 * The decryption check is the one that matters. Tokens in the database are encrypted with
 * the encryption_key from the OLD config.php; if the new install generated a fresh key,
 * every client's WhatsApp and AI credentials are unreadable and must be re-entered.
 */
declare(strict_types=1);
chdir(dirname(__DIR__, 2));

require 'includes/config_loader.php';
require 'includes/helpers.php';
require 'includes/crypto.php';
require 'includes/db.php';

function line(string $l, $v): void { printf("  %-26s %s\n", $l, $v); }

echo "\n── Data ──\n";
$counts = [];
foreach (['clients', 'users', 'contacts', 'contact_lists', 'templates', 'campaigns',
          'campaign_messages', 'messages', 'flows', 'flow_runs', 'flow_collected'] as $t) {
    try { $counts[$t] = (int) db_val("SELECT COUNT(*) FROM `$t`"); }
    catch (Throwable $e) { $counts[$t] = 'MISSING'; }
}
foreach ($counts as $t => $n) line($t, $n);

echo "\n── Schema ──\n";
try {
    $applied = array_column(db_all("SELECT filename FROM schema_migrations ORDER BY filename"), 'filename');
    line('migrations applied', count($applied));
    line('latest', $applied ? end($applied) : '(none)');
    $onDisk = array_map('basename', glob(__DIR__ . '/../../migrations/*.sql') ?: []);
    $missing = array_diff($onDisk, $applied);
    line('pending', $missing ? implode(', ', $missing) . '  ← run make-config.php again' : 'none ✓');
} catch (Throwable $e) { line('migrations', 'table missing'); }

echo "\n── Encrypted secrets (the important one) ──\n";
$ok = $bad = 0; $badNames = [];
foreach (db_all("SELECT id, name, access_token_enc, ai_api_key_enc FROM clients") as $c) {
    foreach (['access_token_enc' => 'WhatsApp token', 'ai_api_key_enc' => 'AI key'] as $col => $label) {
        if (empty($c[$col])) continue;
        $plain = decrypt_secret((string) $c[$col]);
        if ($plain === '') { $bad++; $badNames[] = "{$c['name']} ({$label})"; }
        else               { $ok++; }
    }
}
line('decrypt OK', $ok);
line('decrypt FAILED', $bad);
if ($bad > 0) {
    echo "\n  ❌ " . implode("\n     ", array_slice($badNames, 0, 10)) . "\n";
    echo "\n  The encryption_key in config.php does not match the one these were encrypted\n";
    echo "  with. Copy 'encryption_key' from the OLD config.php into the new one, then\n";
    echo "  re-run this check. Nothing is lost until then — the data is intact.\n";
} elseif ($ok > 0) {
    echo "\n  ✓ Every stored secret decrypts — the encryption key matches.\n";
} else {
    echo "\n  (no stored secrets yet — nothing to check)\n";
}
echo "\n";
