<?php
declare(strict_types=1);
/**
 * Store / remove a Web Push subscription for the logged-in user, and hand the browser the
 * VAPID public key it needs for pushManager.subscribe().
 *
 *   GET  ?key=1            → {ok, key}                (public key only)
 *   POST action=subscribe  → {ok}                     (subscription JSON in `sub`)
 *   POST action=unsubscribe→ {ok}
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/push.php';

header('Content-Type: application/json; charset=UTF-8');

$me = current_user();
if (!$me) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Not signed in']); exit; }

// Admins have no client of their own; they manage clients instead.
$clientId = (int) ($me['client_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $keys = push_vapid_keys(false);
    echo json_encode(['ok' => (bool) $keys, 'key' => $keys['public'] ?? '']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
verify_csrf();

$action = (string) ($_POST['action'] ?? '');
$sub    = json_decode((string) ($_POST['sub'] ?? ''), true);
$endpoint = trim((string) ($sub['endpoint'] ?? ''));
if ($endpoint === '' || !preg_match('~^https://~i', $endpoint)) {
    echo json_encode(['ok' => false, 'error' => 'Bad subscription']); exit;
}
$hash = hash('sha256', $endpoint);

if ($action === 'unsubscribe') {
    db_run("DELETE FROM push_subscriptions WHERE endpoint_hash=?", [$hash]);
    echo json_encode(['ok' => true]); exit;
}

if ($action !== 'subscribe') { echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit; }
if ($clientId <= 0) { echo json_encode(['ok' => false, 'error' => 'This account has no client workspace.']); exit; }

db_run(
    "INSERT INTO push_subscriptions (client_id,user_id,endpoint,endpoint_hash,p256dh,auth,user_agent,created_at)
     VALUES (?,?,?,?,?,?,?,NOW())
     ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), user_id=VALUES(user_id),
                             p256dh=VALUES(p256dh), auth=VALUES(auth), fail_count=0",
    [
        $clientId, (int) $me['id'], substr($endpoint, 0, 500), $hash,
        substr((string) ($sub['keys']['p256dh'] ?? ''), 0, 255),
        substr((string) ($sub['keys']['auth'] ?? ''), 0, 255),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]
);
echo json_encode(['ok' => true]);
