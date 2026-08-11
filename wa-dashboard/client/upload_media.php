<?php
declare(strict_types=1);
/**
 * Upload a header media file (image/video/document) for a WhatsApp template header.
 * Saves under /uploads/<client_id>/ and returns a public HTTPS URL that WhatsApp can fetch.
 */
require __DIR__ . '/_init.php';
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'POST only']); exit; }
verify_csrf();

if (empty($_FILES['media']['tmp_name']) || (int) ($_FILES['media']['error'] ?? 1) !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'No file was uploaded (it may exceed the server upload limit).']); exit;
}

$name = (string) $_FILES['media']['name'];
$size = (int) $_FILES['media']['size'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
$allowed = ['jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'webp' => 'image',
            'mp4' => 'video', '3gp' => 'video', 'pdf' => 'document'];
if (!isset($allowed[$ext])) { echo json_encode(['ok' => false, 'error' => 'Unsupported file type. Use JPG, PNG, MP4 or PDF.']); exit; }

$kind = $allowed[$ext];
$maxByKind = ['image' => 5 * 1024 * 1024, 'video' => 16 * 1024 * 1024, 'document' => 100 * 1024 * 1024];
if ($size > $maxByKind[$kind]) {
    echo json_encode(['ok' => false, 'error' => 'File too large for a ' . $kind . ' (max ' . (int) ($maxByKind[$kind] / 1048576) . ' MB).']); exit;
}

$dir = __DIR__ . '/../uploads/' . (int) $CLIENT['id'];
if (!is_dir($dir)) @mkdir($dir, 0755, true);
if (!is_dir($dir) || !is_writable($dir)) {
    echo json_encode(['ok' => false, 'error' => 'The uploads folder is not writable. Create /uploads on the server and chmod it to 755.']); exit;
}

$fn = bin2hex(random_bytes(8)) . '.' . $ext;
if (!move_uploaded_file((string) $_FILES['media']['tmp_name'], $dir . '/' . $fn)) {
    echo json_encode(['ok' => false, 'error' => 'Could not save the file.']); exit;
}

// Build the public URL WhatsApp will fetch.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443 ? 'https' : 'http';
$host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$appRoot = str_replace('\\', '/', dirname(dirname((string) $_SERVER['SCRIPT_NAME'])));   // /client/upload_media.php → app root
$appRoot = ($appRoot === '/' || $appRoot === '.') ? '' : rtrim($appRoot, '/');
$base = trim((string) config('media_base_url', '')) !== '' ? rtrim((string) config('media_base_url'), '/') : $scheme . '://' . $host . $appRoot;

echo json_encode(['ok' => true, 'kind' => $kind, 'url' => $base . '/uploads/' . (int) $CLIENT['id'] . '/' . $fn]);
