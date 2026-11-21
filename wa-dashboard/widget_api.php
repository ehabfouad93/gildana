<?php
/**
 * Website chat API — used by the chat widget, and open to a developer who wants their own design.
 *
 *   GET  widget_api.php?k=KEY&a=config                       → the widget's look and words
 *   POST widget_api.php?k=KEY&a=send     {vid, text | button_id, page?}   → the reply (and anything new)
 *   GET  widget_api.php?k=KEY&a=poll&vid=…&after=ID          → new messages (a person may answer from the Inbox)
 *   POST widget_api.php?k=KEY&a=identify {vid, name?, phone?}            → who the visitor is
 *
 * vid: 32 hexadecimal characters the visitor's browser makes once and keeps (it is what the
 * conversation hangs on, so it must be random — crypto.getRandomValues). Bodies may be JSON or a form.
 * Calls from a browser on another site are allowed only from the widget's sites (when it lists any).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config_loader.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/credits.php';
require_once __DIR__ . '/includes/whatsapp.php';
require_once __DIR__ . '/includes/ai.php';
require_once __DIR__ . '/includes/crm.php';
require_once __DIR__ . '/includes/notify.php';
require_once __DIR__ . '/includes/automation.php';
require_once __DIR__ . '/includes/push.php';
require_once __DIR__ . '/includes/sending.php';
require_once __DIR__ . '/includes/webchat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$out = function (int $code, array $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; };

$widget = webchat_widget_by_key((string) ($_GET['k'] ?? ''));
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($widget && $origin !== '' && webchat_origin_ok($widget, $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (!$widget) $out(404, ['ok' => false, 'error' => 'This chat does not exist.']);
if ($origin !== '' && !webchat_origin_ok($widget, $origin)) $out(403, ['ok' => false, 'error' => 'This chat is not set up for this website.']);

$client = db_row("SELECT * FROM clients WHERE id=?", [(int) $widget['client_id']]);
if (!$client || ($client['status'] ?? '') !== 'active') $out(403, ['ok' => false, 'error' => 'This chat is not available.']);

$in = $_POST;
if (!$in && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$a = (string) ($_GET['a'] ?? ($in['a'] ?? ''));
$s = webchat_settings($widget);
$paused = $widget['status'] !== 'active';

if ($a === 'config') {
    unset($s['add_lead']);
    $out(200, ['ok' => true, 'paused' => $paused, 'settings' => $s]);
}

$vid = strtolower((string) ($in['vid'] ?? ($_GET['vid'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $vid)) $out(400, ['ok' => false, 'error' => 'Missing or invalid vid (32 hexadecimal characters).']);
$page = mb_substr(trim((string) ($in['page'] ?? '')), 0, 500);
if ($page !== '' && !preg_match('#^https?://#i', $page)) $page = '';

if ($a === 'poll') {
    [$contact] = webchat_contact($client, $widget, $vid, false);
    $out(200, ['ok' => true, 'messages' => $contact ? webchat_messages($contact, max(0, (int) ($_GET['after'] ?? 0))) : []]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(405, ['ok' => false, 'error' => 'Use POST.']);
if ($paused) $out(403, ['ok' => false, 'error' => 'This chat is switched off.']);

[$contact, $err] = webchat_contact($client, $widget, $vid, true, $page, (string) ($in['context'] ?? ''));
if (!$contact) $out(429, ['ok' => false, 'error' => $err]);

if ($a === 'identify') {
    $r = webchat_identify($client, $widget, $contact, (string) ($in['name'] ?? ''), (string) ($in['phone'] ?? ''));
    $out($r['ok'] ? 200 : 422, ['ok' => $r['ok'], 'error' => $r['error'] ?? '']);
}

if ($a === 'send') {
    $after = max(0, (int) ($in['after'] ?? 0));
    $r = webchat_inbound($client, $widget, $contact, (string) ($in['text'] ?? ''), mb_substr((string) ($in['button_id'] ?? ''), 0, 60), $page);
    if (!$r['ok']) $out(429, ['ok' => false, 'error' => $r['error']]);
    $contact = db_row("SELECT * FROM contacts WHERE id=?", [(int) $contact['id']]);
    $out(200, ['ok' => true, 'id' => $r['id'], 'messages' => webchat_messages($contact, $after)]);
}

$out(400, ['ok' => false, 'error' => 'Unknown action.']);
