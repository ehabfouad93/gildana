<?php
declare(strict_types=1);
/**
 * An incoming webhook URL (CRM → Integrations → Receive). Another system POSTs its own JSON (or a
 * form) here; the URL's token says which account and which field mapping. The token is the secret:
 * only its hash is stored, and a wrong one gets the same "not found" as no token at all.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/crm.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$reply = function (int $status, array $d): void { http_response_code($status); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };

$token = (string) ($_GET['t'] ?? '');
$hook = crm_int_ready() && preg_match('/^[0-9a-f]{48}$/', $token)
      ? db_row("SELECT * FROM crm_inbound_hooks WHERE token_hash=? AND active=1", [hash('sha256', $token)]) : null;
if (!$hook) $reply(404, ['ok' => false, 'error' => 'Unknown URL.']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') $reply(200, ['ok' => true, 'message' => 'Ready. POST lead data here as JSON or a form.']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $reply(405, ['ok' => false, 'error' => 'Use POST.']);

$raw = (string) file_get_contents('php://input', false, null, 0, 1024 * 1024);
$payload = $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($payload)) $payload = $_POST ?: [];
if (!$payload && $raw !== '') parse_str($raw, $payload);
if (!$payload) $reply(400, ['ok' => false, 'error' => 'Send the lead as JSON or form fields.']);

// A list of leads at once ([{…},{…}] or {"leads":[…]}), up to 100.
$many = array_is_list($payload) ? $payload : (isset($payload['leads']) && is_array($payload['leads']) && array_is_list($payload['leads']) ? $payload['leads'] : null);
if ($many !== null) {
    $res = [];
    foreach (array_slice($many, 0, 100) as $one) {
        $r = is_array($one) ? crm_inbound_receive($hook, $one) : ['ok' => false, 'error' => 'Not an object.'];
        $res[] = $r['ok'] ? ['ok' => true, 'id' => $r['id'], 'code' => $r['lead']['code'] ?? null, 'created' => $r['created']] : ['ok' => false, 'error' => $r['error']];
    }
    $reply(200, ['ok' => true, 'results' => $res]);
}
$r = crm_inbound_receive($hook, $payload);
if (!$r['ok']) $reply(422, ['ok' => false, 'error' => $r['error']]);
$reply($r['created'] ? 201 : 200, ['ok' => true, 'id' => $r['id'], 'code' => $r['lead']['code'] ?? null, 'created' => $r['created']]);
