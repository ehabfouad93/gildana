<?php
declare(strict_types=1);

/**
 * The background worker — the only scheduled entry point.
 *
 *   * / 5 * * * *  php /home/USER/listen.gildana.net/cron/worker.php >/dev/null 2>&1
 *
 * or, on a host without CLI cron, ping it over HTTPS with the worker token:
 *   https://listen.gildana.net/cron/worker.php?token=<worker_token>
 *
 * Deliberately does NOT include bootstrap.php: crons need no session and no auth.
 *
 * The host only guarantees a 5-minute tick, so every pass is bounded by both a
 * batch cap and a shared wall-clock deadline. A tick that runs out of budget
 * simply picks up where it left off next time — all five passes are idempotent.
 */

require dirname(__DIR__) . '/includes/config_loader.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/i18n.php';
require dirname(__DIR__) . '/includes/crypto.php';
require dirname(__DIR__) . '/includes/db.php';
require dirname(__DIR__) . '/includes/http.php';
require dirname(__DIR__) . '/includes/connectors.php';
require dirname(__DIR__) . '/includes/keywords.php';
require dirname(__DIR__) . '/includes/sentiment.php';
require dirname(__DIR__) . '/includes/ingest.php';
require dirname(__DIR__) . '/includes/ai.php';
require dirname(__DIR__) . '/includes/metrics.php';
require dirname(__DIR__) . '/includes/notify.php';
require dirname(__DIR__) . '/includes/alerts.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $token = (string) ($_GET['token'] ?? '');
    if ($token === '' || !hash_equals((string) config('worker_token', ''), $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=UTF-8');
    ignore_user_abort(true);
}
@set_time_limit(0);

function out(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
    if (function_exists('flush')) { @flush(); }
}

$cfg      = (array) config('worker', []);
$deadline = time() + max(30, (int) ($cfg['deadline_seconds'] ?? 200));

// One worker at a time. A MySQL advisory lock beats a lockfile here: it is
// released automatically if the process dies, so a crash cannot wedge the cron.
$pdo = db();
if ((int) $pdo->query("SELECT GET_LOCK('listen_worker', 0)")->fetchColumn() !== 1) {
    out('Another worker is running — exiting.');
    exit;
}

// Only the passes named in ?only= / --only= run. Used by the "Check now" button
// and for debugging one stage in isolation.
$only = '';
if (!$isCli) {
    $only = (string) ($_GET['only'] ?? '');
} else {
    foreach (array_slice($argv ?? [], 1) as $arg) {
        if (str_starts_with($arg, '--only=')) $only = substr($arg, 7);
    }
}
$runPass = function (string $name) use ($only): bool {
    return $only === '' || $only === $name;
};

$sourceId = (int) ($_GET['source'] ?? 0);

try {
    /* ── Pass A: fetch due sources ───────────────────────────────────────── */
    if ($runPass('fetch')) {
        $limit   = (int) ($cfg['sources_per_run'] ?? 12);
        $sources = $sourceId > 0
            ? array_filter([db_row("SELECT * FROM sources WHERE id = ?", [$sourceId])])
            : sources_due($limit);

        $fetched = 0; $stored = 0; $failed = 0;
        foreach ($sources as $source) {
            if (time() >= $deadline) { out('Fetch budget spent — stopping early.'); break; }
            $r = source_run($source);
            $fetched += $r['fetched'];
            $stored  += $r['new'];
            $failed  += $r['errors'];
        }
        out(sprintf('fetch: %d source(s), %d item(s) seen, %d new, %d error(s)',
            count($sources), $fetched, $stored, $failed));
    }

    /* ── Pass A2: backfill search_text for pre-migration-002 rows ────────── */
    if ($runPass('classify') && time() < $deadline) {
        $n = ingest_backfill_search(200);
        if ($n > 0) out("backfill: {$n} mention(s) indexed for search");
    }

    /* ── Pass B: lexicon classification (no network, cheap) ──────────────── */
    if ($runPass('classify') && time() < $deadline) {
        $n = sent_classify_lexicon((int) ($cfg['lexicon_per_run'] ?? 300));
        out("lexicon: {$n} mention(s) classified");
    }

    /* ── Pass C: AI escalation for the low-confidence ones ───────────────── */
    if ($runPass('classify') && time() < $deadline) {
        $n = sent_classify_ai(
            (int) ($cfg['ai_per_run'] ?? 40),
            (int) ($cfg['ai_batch_size'] ?? 10),
            $deadline
        );
        out("ai: {$n} mention(s) reclassified");
    }

    /* ── Pass D: alerts ──────────────────────────────────────────────────── */
    if ($runPass('alerts') && time() < $deadline) {
        $fired = alerts_run();
        $sent  = alerts_deliver(50);
        out("alerts: {$fired} fired, {$sent} email(s) sent");
    }

    /* ── Pass E: scheduled digests ───────────────────────────────────────── */
    if ($runPass('digests') && time() < $deadline) {
        $n = digests_run(5);
        out("digests: {$n} sent");
    }

    /* ── Pass F: housekeeping, roughly hourly ────────────────────────────── */
    if ($runPass('housekeeping') && (int) date('i') < 5) {
        $pruned = db_run("DELETE FROM fetch_runs WHERE started_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $old    = db_run("DELETE FROM alerts WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)");
        out("housekeeping: pruned {$pruned} fetch run(s), {$old} old alert(s)");

        // Tell the agency when a source has been failing long enough to need a look.
        $broken = db_all(
            "SELECT s.id, s.connector, s.last_error, c.name
               FROM sources s JOIN clients c ON c.id = s.client_id
              WHERE s.status = 'error' AND s.consecutive_failures >= 5
              LIMIT 20"
        );
        if ($broken) {
            $lines = [];
            foreach ($broken as $b) {
                $lines[] = sprintf('%s — %s: %s', $b['name'], $b['connector'], $b['last_error']);
            }
            notify_admin('Gildana Listening — sources need attention', implode("\n", $lines));
        }
    }
} catch (Throwable $ex) {
    out('ERROR: ' . $ex->getMessage());
    error_log('listening worker: ' . $ex->getMessage());
} finally {
    $pdo->query("SELECT RELEASE_LOCK('listen_worker')");
    @touch(__DIR__ . '/.heartbeat');
}

out('done');
