<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$cid = (int) ($_GET['campaign_id'] ?? 0);
$camp = db_row("SELECT * FROM campaigns WHERE id = ?", [$cid]);
if (!$camp) json_out(['ok' => false, 'error' => 'Not found'], 404);
if ((int) $camp['user_id'] !== $USER['id'] && !is_admin()) json_out(['ok' => false, 'error' => 'Forbidden'], 403);

// Finalize any of this campaign's outstanding video jobs.
foreach (db_all("SELECT j.* FROM jobs j JOIN assets a ON a.id=j.asset_id WHERE j.status='processing' AND a.campaign_id=? LIMIT 10", [$cid]) as $j) {
    studio_process_job($j);
}

$updates = [];
$pending = 0;
foreach (db_all("SELECT * FROM assets WHERE campaign_id=? ORDER BY id ASC", [$cid]) as $a) {
    if ($a['status'] === 'pending') $pending++;
    $updates[] = ['asset_id' => (int) $a['id'], 'status' => $a['status'], 'card' => asset_card($a)];
}
json_out(['ok' => true, 'pending' => $pending, 'assets' => $updates]);
