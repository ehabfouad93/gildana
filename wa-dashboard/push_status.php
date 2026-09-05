<?php
declare(strict_types=1);
/**
 * Unread count for the service worker, after a payload-less push wakes it.
 *
 * Keyed on the push ENDPOINT rather than the login session: the session cookie has
 * lifetime 0 (dies with the browser), so a push arriving later would often find no
 * session at all. The endpoint is an unguessable capability URL the push service already
 * holds, and this returns only a bare integer — no message text, names or phone numbers.
 */
require __DIR__ . '/includes/config_loader.php';
require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/crypto.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/whatsapp.php';
require __DIR__ . '/includes/credits.php';
require __DIR__ . '/includes/inbox.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
$endpoint = trim((string) ($body['endpoint'] ?? ($_POST['endpoint'] ?? '')));
if ($endpoint === '') { echo json_encode(['ok' => false]); exit; }

$sub = db_row("SELECT client_id FROM push_subscriptions WHERE endpoint_hash=?", [hash('sha256', $endpoint)]);
if (!$sub) { http_response_code(404); echo json_encode(['ok' => false]); exit; }

echo json_encode(['ok' => true, 'count' => inbox_unread_total((int) $sub['client_id'])]);
