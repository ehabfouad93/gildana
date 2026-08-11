<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';

$assetId = (int) ($_GET['asset'] ?? 0);
$asset = db_row("SELECT a.*, c.user_id FROM assets a JOIN campaigns c ON c.id=a.campaign_id WHERE a.id=?", [$assetId]);
if (!$asset) { http_response_code(404); exit('Not found'); }
if ((int) $asset['user_id'] !== $USER['id'] && !is_admin()) { http_response_code(403); exit('Forbidden'); }

$slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', strtolower((string) $asset['title'])) ?: 'asset';

// Text assets: stream as .txt.
if ($asset['kind'] === 'text') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $slug . '-' . $assetId . '.txt"');
    echo (string) $asset['text_content'];
    exit;
}

$rel = (string) ($asset['rel_path'] ?? '');
$path = studio_storage_dir() . '/' . $rel;
if ($rel === '' || !is_file($path)) { http_response_code(404); exit('File not available'); }

$ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';
header('Content-Type: ' . ($asset['mime'] ?: mime_for_ext($ext)));
header('Content-Disposition: attachment; filename="' . $slug . '-' . $assetId . '.' . $ext . '"');
header('Content-Length: ' . (string) filesize($path));
readfile($path);
exit;
