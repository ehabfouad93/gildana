<?php
declare(strict_types=1);

/**
 * WooCommerce's key-approval screen POSTs the new REST API key here when the store owner
 * approves Revenect ("Connect WooCommerce" on Connected apps). The body is JSON:
 * {key_id, user_id, consumer_key, consumer_secret, key_permissions}; user_id is the single-use
 * state we put in the approval link, which says which client asked and for which store.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/integrations.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !integrations_ready()) { http_response_code(405); exit; }
$p = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
if (!is_array($p)) { http_response_code(400); exit; }
$r = woo_finish_auth($p);
http_response_code($r['ok'] ? 200 : 400);
header('Content-Type: application/json');
echo json_encode(['ok' => (bool) $r['ok']]);
