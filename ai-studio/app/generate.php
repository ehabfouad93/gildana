<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_soft()) {
    json_out(['ok' => false, 'error' => 'Bad request'], 400);
}

$cid   = (int) ($_POST['campaign_id'] ?? 0);
$stage = (string) ($_POST['stage'] ?? '');

$camp = db_row("SELECT * FROM campaigns WHERE id = ?", [$cid]);
if (!$camp) json_out(['ok' => false, 'error' => 'Campaign not found'], 404);
if ((int) $camp['user_id'] !== $USER['id'] && !is_admin()) json_out(['ok' => false, 'error' => 'Forbidden'], 403);

$picks = json_decode((string) ($camp['picks_json'] ?? '[]'), true) ?: [];
if (!isset($picks[$stage])) json_out(['ok' => false, 'error' => 'No module chosen for this step'], 400);

$pick = $picks[$stage];
$brief = [
    'product' => $camp['product'], 'audience' => $camp['audience'], 'goal' => $camp['goal'],
    'key_message' => $camp['key_message'], 'tone' => $camp['tone'], 'cta' => $camp['cta'], 'language' => $camp['language'],
];
$inputs = ['model' => (string) ($pick['model'] ?? ''), 'note' => (string) ($pick['note'] ?? '')];

@set_time_limit(240);
$res = studio_run_stage($cid, $stage, (string) $pick['module_id'], $brief, $inputs);

db_run("UPDATE campaigns SET status = IF(status='draft','generating',status), updated_at=NOW() WHERE id=?", [$cid]);

$assetId = (int) ($res['asset_id'] ?? 0);
$asset = $assetId ? db_row("SELECT * FROM assets WHERE id=?", [$assetId]) : null;
json_out([
    'ok'       => $res['ok'],
    'async'    => $res['async'] ?? false,
    'status'   => $res['status'] ?? ($res['ok'] ? 'ready' : 'failed'),
    'error'    => $res['error'] ?? '',
    'asset_id' => $assetId,
    'card'     => $asset ? asset_card($asset) : '',
]);
