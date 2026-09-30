<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/crm_materials.php';

/** One piece of material, to a signed-in person of the same account: shown when it can be, downloaded otherwise. */
$m = db_row("SELECT * FROM crm_materials WHERE id=? AND client_id=?", [(int) ($_GET['id'] ?? 0), (int) $CLIENT['id']]);
$path = $m && !empty($m['file_path']) ? realpath(dirname(__DIR__) . '/uploads/materials/' . $m['file_path']) : false;
$base = realpath(dirname(__DIR__) . '/uploads/materials');
if (!$m || !$path || !$base || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Not found.');
}
$mime = (string) $m['mime'];
$inline = empty($_GET['download']) && (preg_match('#^(image|video|audio)/#', $mime) || $mime === 'application/pdf');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', (string) $m['file_name']) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
