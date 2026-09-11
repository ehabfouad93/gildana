<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

/**
 * "Check now" on the Sources page.
 *
 * Runs the source inline rather than waiting up to five minutes for the cron —
 * this is the one place a client expects an immediate answer, and it is also
 * how they verify a newly added feed URL actually works.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
if (!verify_csrf_soft())                   json_out(['ok' => false, 'error' => 'Invalid security token'], 403);

$cid    = (int) $CLIENT['id'];
$id     = (int) ($_POST['id'] ?? 0);
$source = db_row("SELECT * FROM sources WHERE id = ? AND client_id = ?", [$id, $cid]);
if (!$source) json_out(['ok' => false, 'error' => 'Source not found'], 404);

@set_time_limit(120);

$result = source_run($source, $CLIENT);

// Classify what just arrived so the feed is not full of "unclassified" rows
// for the next few minutes.
if ($result['new'] > 0) {
    sent_classify_lexicon(min(100, $result['new'] * 2));
}

$fresh = db_row("SELECT last_status, last_error FROM sources WHERE id = ?", [$id]);

if ($result['errors'] > 0 && $result['fetched'] === 0) {
    json_out(['ok' => false, 'error' => (string) ($fresh['last_error'] ?? 'Fetch failed')]);
}

json_out([
    'ok'      => true,
    'reload'  => true,
    'message' => sprintf('%d found, %d new', $result['fetched'], $result['new']),
]);
