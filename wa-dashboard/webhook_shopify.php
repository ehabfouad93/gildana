<?php
declare(strict_types=1);

/**
 * Shopify sends a store's events here (orders, fulfilments, customers, checkouts, uninstall) and,
 * for the platform's Shopify app, the three privacy requests (customers/data_request,
 * customers/redact, shop/redact).
 *
 * Every POST is signed (X-Shopify-Hmac-Sha256, base64 HMAC-SHA256 of the raw body) with the app's
 * API secret — the platform app's, or the merchant's own app's when they connected with keys.
 * The store comes from X-Shopify-Shop-Domain and must be one a client connected. The event is
 * queued and answered at once (Shopify waits 5 seconds at most), then handled.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/integrations.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$raw   = (string) file_get_contents('php://input', false, null, 0, 5 * 1024 * 1024);
$hmac  = (string) ($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '');
$shop  = shopify_shop((string) ($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? ''));
$topic = strtolower(trim((string) ($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '')));
$evId  = (string) ($_SERVER['HTTP_X_SHOPIFY_EVENT_ID'] ?? $_SERVER['HTTP_X_SHOPIFY_WEBHOOK_ID'] ?? '');

if ($hmac === '' || $shop === '' || !preg_match('#^[a-z_]+/[a-z_]+$#', $topic) || !integrations_ready()) { http_response_code(401); exit; }

// Each client that connected this store, whose secret signed this body.
$sig = fn(string $secret) => $secret !== '' && hash_equals(base64_encode(hash_hmac('sha256', $raw, $secret, true)), $hmac);
$matches = [];
foreach (db_all("SELECT * FROM integrations WHERE provider='shopify' AND account=?", [$shop]) as $row) {
    $it = integration_load($row);
    if ($sig(shopify_hook_secret($it))) $matches[] = $it;
}
$privacy = in_array($topic, ['customers/data_request', 'customers/redact', 'shop/redact'], true);
if (!$matches) {
    // A privacy request for a store nobody connected (or that was removed): signed by the app, nothing held.
    if ($privacy && $sig(shopify_cfg()['api_secret'])) { http_response_code(200); exit; }
    http_response_code(401);
    exit;
}

$body = json_decode($raw, true);
if (!is_array($body)) { http_response_code(400); exit; }

$run = [];
foreach ($matches as $it) {
    $key = $evId !== '' ? 'shopify:' . $evId : null;
    if ($privacy) { integration_log($it, 'in', $topic, shopify_privacy($it, $topic, $body), true, $key); continue; }
    if ($topic === 'app/uninstalled') {
        if (integration_log($it, 'in', $topic, 'The app was removed from the store — disconnected', false, $key)) {
            db_run("UPDATE integrations SET status='disconnected', tokens_enc=NULL, last_error='The app was removed from the Shopify store.' WHERE id=?", [(int) $it['id']]);
        }
        continue;
    }
    if ($it['status'] === 'disconnected') continue;
    $logId = integration_log($it, 'in', $topic, 'Received', true, $key);
    if (!$logId) continue;                                   // the same event again (Shopify retries): already have it
    integration_job($it, 'event', null, ['topic' => $topic, 'body' => $body, 'log_id' => $logId]);
    $run[] = $it;
}
http_response_code(200);
echo 'ok';

// Answer Shopify first, then handle what came in.
if ($run && function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); integration_jobs_tick(10); }
