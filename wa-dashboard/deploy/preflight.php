<?php
declare(strict_types=1);

/**
 * Launch readiness check. Run before putting the dashboard in front of real customers:
 *
 *     php deploy/preflight.php
 *
 * Exits non-zero if anything would be unsafe or broken in production, and says exactly
 * what to do about each item. Reports only — it changes nothing.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

require __DIR__ . '/../includes/config_loader.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/crypto.php';
require __DIR__ . '/../includes/db.php';

$checks = [];
$add = function (string $state, string $title, string $detail, string $fix = '') use (&$checks) {
    $checks[] = compact('state', 'title', 'detail', 'fix');
};

/* ── 1. Encryption key ── */
$rawKey = (string) config('encryption_key');
$decoded = base64_decode($rawKey, true);
if ($rawKey === '' || $rawKey === 'CHANGE_ME_base64_32_bytes' || $decoded === false || strlen($decoded) !== 32) {
    $add('fail', 'Encryption key is not a real key',
        'Client WhatsApp tokens are encrypted with this. The app now refuses to start without a valid one.',
        'php -r \'echo base64_encode(random_bytes(32)), PHP_EOL;\' then put it in config.php as encryption_key.');
} else {
    $add('ok', 'Encryption key is set', 'Stored credentials are encrypted with a 32-byte key.');
}

/* ── 2. base_url ── */
$base = rtrim((string) config('base_url', ''), '/');
if ($base === '') {
    $add('fail', 'base_url is not set',
        'Without it the app falls back to whatever Host header a request carries. The instant '
      . '"send now" kick is skipped entirely rather than post the cron token to a forged host.',
        'Set base_url in config.php to your real address, e.g. https://revenect.gildana.net');
} elseif (!str_starts_with($base, 'https://')) {
    $add('fail', 'base_url is not https', 'Sessions and webhook traffic would travel in the clear.',
        'Change base_url in config.php to the https:// address.');
} else {
    $add('ok', 'base_url is set', $base);
}

/* ── 3. Cron token ── */
$tok = (string) config('webhook_verify_token');
if ($tok === '' || $tok === 'CHANGE_ME_pick_any_random_string') {
    $add('fail', 'Cron/verify token is still the sample value',
        'Anyone who guesses it can start the worker and complete Meta webhook verification.',
        'php -r \'echo bin2hex(random_bytes(16)), PHP_EOL;\' then set webhook_verify_token in config.php.');
} else {
    $add('ok', 'Cron/verify token is set', 'Send it as the X-Cron-Token header, not in the URL.');
}

/* ── 4. Per-client webhook signing ── */
try {
    $clients = db_all("SELECT id, name, channel, app_secret_enc, require_signed_webhook FROM clients WHERE status='active'");
} catch (Throwable $e) {
    $clients = [];
    $add('fail', 'Cannot read the clients table', $e->getMessage(), 'Check the database settings in config.php.');
}
$cloud = array_values(array_filter($clients, fn($c) => ($c['channel'] ?? 'cloud') !== 'personal'));
$noSecret   = array_values(array_filter($cloud, fn($c) => empty($c['app_secret_enc'])));
$notEnforced = array_values(array_filter($cloud, fn($c) => !empty($c['app_secret_enc']) && empty($c['require_signed_webhook'])));

if ($noSecret) {
    $names = implode(', ', array_map(fn($c) => $c['name'] . ' (#' . $c['id'] . ')', $noSecret));
    $add('fail', count($noSecret) . ' client(s) have no App Secret',
        'Their callbacks cannot be verified, so anyone who finds the webhook URL can forge delivery '
      . "reports and trigger their automations: {$names}",
        'For each: Admin → client → Credentials → App Secret. Take it from THAT client\'s Meta app '
      . '(Settings → Basic) — every client has their own app, so there is no single shared value.');
} elseif ($cloud) {
    $add('ok', 'Every Cloud API client has an App Secret', 'Callbacks are verified per client.');
}
if ($notEnforced) {
    $names = implode(', ', array_map(fn($c) => $c['name'] . ' (#' . $c['id'] . ')', $notEnforced));
    $add('warn', count($notEnforced) . ' client(s) still accept unsigned callbacks',
        "Their secret is saved and checked, but unsigned requests are not rejected yet: {$names}",
        'Admin → client → Credentials → tick "Reject callbacks that aren\'t correctly signed".');
}

/* ── 5. Personal-channel webhook secrets ── */
try {
    $personal = db_all("SELECT id, name, personal_hook_secret FROM clients WHERE channel='personal' AND status='active'");
    $noHook = array_values(array_filter($personal, fn($c) => empty($c['personal_hook_secret'])));
    if ($personal && !$noHook) {
        $never = (int) db_val("SELECT COUNT(*) FROM clients WHERE channel='personal' AND status='active' AND personal_hook_rotated_at IS NULL");
        if ($never > 0) {
            $add('warn', "{$never} personal client(s) have never had their webhook secret rotated",
                'If a secret was ever exposed — a screenshot, a log, a support thread — it is still live.',
                'php deploy/rotate-hook-secrets.php — clients are not interrupted and nothing is rescanned.');
        } else {
            $add('ok', 'Personal-channel webhook secrets have been rotated', count($personal) . ' connected.');
        }
    } elseif ($noHook) {
        $add('warn', count($noHook) . ' personal client(s) have no webhook secret',
            'Their inbound messages will not arrive until they reconnect.',
            'Ask them to disconnect and reconnect in Settings → My WhatsApp Number.');
    }
} catch (Throwable $e) { /* column may not exist on an old schema */ }

/* ── 6. Retention — state the guarantee, then show the growth ── */
try {
    $days = (int) (db_val("SELECT v FROM app_settings WHERE k='retention_days'") ?: 90);
    $window = $days > 0 ? "{$days} days" : 'kept indefinitely';

    // Size of the only two tables the sweep can reach, so growth is visible early.
    $sizes = [];
    foreach (['webhook_events' => 'received_at', 'inbound_log' => 'created_at'] as $t => $_) {
        try {
            $rows = (int) db_val("SELECT COUNT(*) FROM `{$t}`");
            $mb   = (float) db_val(
                "SELECT ROUND((data_length + index_length)/1048576, 1) FROM information_schema.TABLES
                  WHERE table_schema = DATABASE() AND table_name = ?", [$t]);
            $sizes[] = "{$t}: " . number_format($rows) . " rows, {$mb} MB";
        } catch (Throwable $e) { /* table may not exist yet */ }
    }

    $add('ok', 'Client data is never deleted',
        'Messages, campaigns, contacts, flows, credits and payments are kept permanently and no '
      . 'setting can prune them. Only two raw debug logs are swept (' . $window . '), and both '
      . 'duplicate data that is kept forever. ' . ($sizes ? implode(' · ', $sizes) : ''));
} catch (Throwable $e) { /* app_settings may be missing on a fresh install */ }

/* ── 7. Migrations ── */
try {
    $applied = array_column(db_all("SELECT filename FROM schema_migrations"), 'filename');
    $onDisk  = array_map('basename', glob(dirname(__DIR__) . '/migrations/*.sql') ?: []);
    $pending = array_values(array_diff($onDisk, $applied));
    if ($pending) {
        $add('fail', count($pending) . ' migration(s) not applied', implode(', ', $pending),
            'Open the dashboard once (setup.php runs them), or run migrate() from a CLI script.');
    } else {
        $add('ok', 'Database is up to date', count($applied) . ' migrations applied.');
    }
} catch (Throwable $e) {
    $add('fail', 'Cannot read schema_migrations', $e->getMessage(), 'Run setup.php once to create it.');
}

/* ── 8. Setup lock ── */
if (is_file(dirname(__DIR__) . '/.setup_complete')) {
    $add('ok', 'First-run setup is locked', 'setup.php will not create another admin.');
} else {
    $add('warn', 'First-run setup is not locked yet',
        'setup.php creates the first administrator whenever no admin exists.',
        'Sign in as an admin once — the lock file is written automatically.');
}

/* ── report ── */
$icon = ['ok' => '  ok  ', 'warn' => ' warn ', 'fail' => ' FAIL '];
$fails = 0; $warns = 0;
echo "\n Revenect — launch readiness\n", str_repeat('─', 72), "\n";
foreach ($checks as $c) {
    if ($c['state'] === 'fail') $fails++;
    if ($c['state'] === 'warn') $warns++;
    echo '[', $icon[$c['state']], '] ', $c['title'], "\n";
    if ($c['detail'] !== '') echo '          ', $c['detail'], "\n";
    if ($c['fix'] !== '' && $c['state'] !== 'ok') echo '          → ', $c['fix'], "\n";
}
echo str_repeat('─', 72), "\n";
if ($fails)      echo " NOT READY — {$fails} blocking issue(s), {$warns} warning(s).\n\n";
elseif ($warns)  echo " Ready, with {$warns} warning(s) worth clearing.\n\n";
else             echo " Ready to go live.\n\n";
exit($fails > 0 ? 1 : 0);
