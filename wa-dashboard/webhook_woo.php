<?php
declare(strict_types=1);

/**
 * WooCommerce sends a connected store's order and customer events here
 * (registered per connection by woo_job('register_webhooks'), URL ?t=<connection's random token>).
 *
 * Every delivery is signed: X-WC-Webhook-Signature is the base64 HMAC-SHA256 of the raw body with
 * the secret we gave the store when adding the webhook. The event is queued and answered at once
 * (WooCommerce turns a webhook off after repeated failures), then handled.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/integrations.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$t = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[0-9a-f]{48}$/', $t) || !integrations_ready()) { http_response_code(401); exit; }
$row = db_row("SELECT * FROM integrations WHERE hook_token=? AND provider='woocommerce' AND status IN ('active','error')", [$t]);
if (!$row) { http_response_code(401); exit; }
$it = integration_load($row);

$raw = (string) file_get_contents('php://input', false, null, 0, 5 * 1024 * 1024);
// Adding a webhook makes the store send "webhook_id=N" once to check the address. Nothing to do.
if (preg_match('/^webhook_id=\d+$/', trim($raw))) { http_response_code(200); exit; }

$secret = (string) decrypt_secret((string) ($it['webhook_secret_enc'] ?? ''));
$sig = (string) ($_SERVER['HTTP_X_WC_WEBHOOK_SIGNATURE'] ?? '');
if ($secret === '' || $sig === '' || !hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), $sig)) { http_response_code(401); exit; }

$topic = strtolower((string) ($_SERVER['HTTP_X_WC_WEBHOOK_TOPIC'] ?? ''));
$body = json_decode($raw, true);
if (!preg_match('/^(order|customer)\.(created|updated)$/', $topic) || !is_array($body)) { http_response_code(200); exit; }

$did = (string) ($_SERVER['HTTP_X_WC_WEBHOOK_DELIVERY_ID'] ?? '');
$logId = integration_log($it, 'in', $topic, 'Received', true, $did !== '' ? 'woo:' . mb_substr($did, 0, 90) : null);
if ($logId) integration_job($it, 'event', null, ['topic' => $topic, 'body' => $body, 'log_id' => $logId]);
http_response_code(200);
echo 'ok';
if ($logId && function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); integration_jobs_tick(10); }
