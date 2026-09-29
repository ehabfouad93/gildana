<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/inbox_media.php';

/**
 * A picture, voice note, video or file someone sent — played or shown in the Inbox and on the
 * lead page. Only for people signed in to this account who may see the conversation (a
 * salesperson: their own leads). Fetched from WhatsApp the first time, served from our copy after.
 *
 * Answers Range requests, because Safari will not play audio or video without them.
 */
$id  = (int) ($_GET['m'] ?? 0);
$msg = $id ? db_row("SELECT * FROM messages WHERE id=? AND client_id=?", [$id, (int) $CLIENT['id']]) : null;
$contact = $msg ? db_row("SELECT * FROM contacts WHERE id=?", [(int) $msg['contact_id']]) : null;
if (!$msg || !$contact || !crm_can_see($contact) || inbox_media_kind($msg) === null) {
    http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('Not found.');
}
$f = inbox_media_get($msg);
if (!$f['ok']) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit($f['error']); }

$size = filesize($f['path']);
$mime = preg_replace('/[^\w\/.+-]/', '', explode(';', $f['mime'])[0]) ?: 'application/octet-stream';
// Anything that is not a picture, sound or video downloads rather than opening in the browser.
$inline = preg_match('#^(image|audio|video)/#', $mime) && empty($_GET['download']);
$name = $f['name'] !== '' ? $f['name'] : 'whatsapp-' . $id . '.' . pathinfo($f['path'], PATHINFO_EXTENSION);
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');

$start = 0; $end = $size - 1;
if (preg_match('/bytes=(\d*)-(\d*)/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $r)) {
    if ($r[1] === '' && $r[2] !== '') { $start = max(0, $size - (int) $r[2]); }
    else { $start = (int) $r[1]; if ($r[2] !== '') $end = min($end, (int) $r[2]); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$fh = fopen($f['path'], 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(65536, $left)); echo $chunk; $left -= strlen($chunk); }
fclose($fh);
