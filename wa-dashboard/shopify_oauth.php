<?php
declare(strict_types=1);

/**
 * Shopify sends the browser here after the merchant approves the platform's Shopify app.
 * Register this address in the app's settings as an allowed redirection URL (Admin → Settings →
 * Shopify app shows it). Also used as the app's URL: opened from the store's admin without a
 * code, it starts the connection for whoever is signed in to Revenect.
 *
 * Checked: Shopify's HMAC over the query, the single-use state, and that the store answering is
 * the one the client asked for.
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/integrations.php';

if (!integrations_ready() || !shopify_configured()) integration_done_page('Shopify is not set up', 'The platform\'s Shopify app is not configured yet.', false);

if (!empty($_GET['error'])) {
    $st = integration_state_take((string) ($_GET['state'] ?? ''), 'shopify');
    integration_done_page('Not connected', 'Shopify reported: <strong>' . e((string) ($_GET['error_description'] ?? $_GET['error'])) . '</strong>. Nothing was changed.', false);
}

// Opened from the store's admin (no code): start the connection for the signed-in Revenect user.
if (empty($_GET['code'])) {
    $shop = shopify_shop((string) ($_GET['shop'] ?? ''));
    if ($shop === '' || !shopify_query_hmac_ok($_GET, shopify_cfg()['api_secret'])) integration_done_page('Not connected', 'This link did not come from Shopify.', false);
    $u = current_user();
    $cid = $u ? (int) (($u['role'] === 'admin' ? ($_SESSION['impersonate_client_id'] ?? 0) : $u['client_id']) ?? 0) : 0;
    // Only someone who may connect apps for that account: a client Admin, or the platform admin inside it.
    if ($cid && $u['role'] !== 'admin' && user_client_role(db_row("SELECT * FROM users WHERE id=?", [(int) $u['id']]) ?: []) !== 'admin') $cid = 0;
    if (!$cid) {
        integration_done_page('Finish in Revenect', 'Sign in to Revenect, open <strong>CRM → Connected apps</strong> and press <strong>Connect Shopify</strong> for <strong>'
            . e($shop) . '</strong>.', false, 'login.php');
    }
    redirect(shopify_auth_url($cid, (int) $u['id'], $shop));
}

$r = shopify_finish_oauth($_GET);
if (empty($r['ok'])) integration_done_page('Could not connect Shopify', e((string) $r['error']), false);
integration_done_page('Shopify connected', '<strong>' . e((string) $r['name']) . '</strong> is connected. Customers and recent orders are being imported now — '
    . 'switch on the messages you want under <strong>Messages</strong>.', true, 'client/apps.php?app=' . (int) $r['id']);
