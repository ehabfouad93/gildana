<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_soft()) {
    json_out(['ok' => false, 'error' => 'Bad request'], 400);
}

$assetId = (int) ($_POST['asset_id'] ?? 0);
$module  = (string) ($_POST['module'] ?? '');
$caption = trim((string) ($_POST['caption'] ?? ''));

if (!in_array($module, ['meta_facebook', 'meta_instagram'], true)) {
    json_out(['ok' => false, 'error' => 'Unknown channel'], 400);
}

$asset = db_row("SELECT a.*, c.user_id, c.key_message, c.goal, c.cta
                 FROM assets a JOIN campaigns c ON c.id = a.campaign_id WHERE a.id = ?", [$assetId]);
if (!$asset) json_out(['ok' => false, 'error' => 'Asset not found'], 404);
if ((int) $asset['user_id'] !== $USER['id'] && !is_admin()) json_out(['ok' => false, 'error' => 'Forbidden'], 403);
if ($asset['status'] !== 'ready' || !in_array($asset['kind'], ['image', 'video'], true) || empty($asset['url'])) {
    json_out(['ok' => false, 'error' => 'Only a finished image or video can be published'], 400);
}

// Caption: explicit → generated copy → brief fallback.
if ($caption === '') {
    $copy = db_val("SELECT text_content FROM assets WHERE campaign_id=? AND kind='text' AND status='ready' ORDER BY id DESC LIMIT 1", [(int) $asset['campaign_id']]);
    $caption = trim((string) ($copy ?: ($asset['key_message'] ?: $asset['goal'])));
}

@set_time_limit(180);
$res = adapter_publish_meta($module, ['url' => (string) $asset['url'], 'mime' => (string) $asset['mime']], $caption);

db_insert(
    "INSERT INTO publications (asset_id, campaign_id, module_id, user_id, caption, status, post_id, permalink, error, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,NOW())",
    [$assetId, (int) $asset['campaign_id'], $module, $USER['id'], $caption,
     $res['ok'] ? 'published' : 'failed', $res['post_id'], $res['permalink'], $res['ok'] ? '' : $res['error']]
);

json_out(['ok' => $res['ok'], 'error' => $res['error'], 'permalink' => $res['permalink']]);
