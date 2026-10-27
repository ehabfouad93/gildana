<?php
declare(strict_types=1);

/**
 * Shopify: connect a store, receive its events, import its customers and orders, message buyers.
 *
 * Connecting, either way:
 *   - Connect button (OAuth) through the platform's Shopify app (Admin → Settings → Shopify app):
 *     the client types their store, approves on Shopify, we get an offline access token.
 *   - Keys: an app the merchant made for their own store — its Admin API access token, or its
 *     client ID + secret (we then fetch short-lived tokens ourselves, the client-credentials grant).
 *     The app's API secret signs the events Shopify sends us.
 *
 * Shopify's events (webhook_shopify.php) are queued as jobs and handled by shopify_event(); the
 * worker also polls recent orders and checkouts every 15 minutes in case an event was missed.
 * Data calls use the Admin REST API, whose JSON is the same shape as the events, so one set of
 * readers serves both.
 */

const SHOPIFY_SCOPES = 'read_customers,read_orders,read_fulfillments,read_checkouts';
const SHOPIFY_TOPICS = ['orders/create', 'orders/updated', 'orders/paid', 'orders/fulfilled', 'orders/cancelled', 'fulfillments/create',
                        'fulfillments/update', 'customers/create', 'customers/update', 'checkouts/create', 'checkouts/update', 'app/uninstalled'];

/** The platform's Shopify app (Admin → Settings). */
function shopify_cfg(): array
{
    return ['api_key' => trim((string) setting_get('shopify_api_key', '')), 'api_secret' => decrypt_secret((string) setting_get('shopify_api_secret', ''))];
}

function shopify_configured(): bool
{
    $c = shopify_cfg();
    return $c['api_key'] !== '' && $c['api_secret'] !== '';
}

function shopify_api_version(): string
{
    $v = (string) config('shopify_api_version', '2026-07');
    return preg_match('/^\d{4}-\d{2}$/', $v) ? $v : '2026-07';
}

/** "mystore", "mystore.myshopify.com" or "https://mystore.myshopify.com/admin" → mystore.myshopify.com, or '' if not a Shopify store address. */
function shopify_shop(string $raw): string
{
    $s = strtolower(trim($raw));
    $s = preg_replace('#^https?://#', '', $s);
    $s = explode('/', $s)[0];
    if ($s !== '' && !str_contains($s, '.')) $s .= '.myshopify.com';
    return preg_match('/^[a-z0-9][a-z0-9-]{0,60}\.myshopify\.com$/', $s) ? $s : '';
}

/** The store's base address. Tests point this at a mock with config('shopify_api_base'). */
function shopify_base(string $shop): string
{
    $o = (string) config('shopify_api_base', '');
    return $o !== '' ? rtrim($o, '/') . '/' . $shop : 'https://' . $shop;
}

function shopify_redirect_uri(): string { return integration_url('shopify_oauth.php'); }
function shopify_webhook_url(): string { return integration_url('webhook_shopify.php'); }

/* ───────────────────────── connecting ───────────────────────── */

function shopify_auth_url(int $clientId, ?int $userId, string $shop): string
{
    $state = integration_state_make($clientId, $userId, 'shopify', $shop);
    return shopify_base($shop) . '/admin/oauth/authorize?' . http_build_query([
        'client_id' => shopify_cfg()['api_key'], 'scope' => SHOPIFY_SCOPES, 'redirect_uri' => shopify_redirect_uri(), 'state' => $state,
    ]);
}

/** Shopify signs the redirect's query string with the app secret. */
function shopify_query_hmac_ok(array $q, string $secret): bool
{
    $given = (string) ($q['hmac'] ?? '');
    unset($q['hmac'], $q['signature']);
    ksort($q);
    $msg = implode('&', array_map(fn($k, $v) => $k . '=' . (is_array($v) ? '["' . implode('", "', $v) . '"]' : $v), array_keys($q), $q));
    return $given !== '' && $secret !== '' && hash_equals(hash_hmac('sha256', $msg, $secret), $given);
}

/** The callback: check the signature, the state and the store, swap the code for a token. */
function shopify_finish_oauth(array $q): array
{
    $cfg = shopify_cfg();
    if (!shopify_query_hmac_ok($q, $cfg['api_secret'])) return ['ok' => false, 'error' => 'The answer from Shopify was not signed correctly, so it was refused.'];
    $shop = shopify_shop((string) ($q['shop'] ?? ''));
    $state = integration_state_take((string) ($q['state'] ?? ''), 'shopify');
    if (!$state) return ['ok' => false, 'error' => 'This sign-in link was already used or is over an hour old. Start again from Connected apps.'];
    if ($shop === '' || $shop !== (string) $state['extra']) return ['ok' => false, 'error' => 'Shopify answered for a different store than the one you asked to connect.', 'client_id' => (int) $state['client_id']];
    $r = integration_http('POST', shopify_base($shop) . '/admin/oauth/access_token', ['Content-Type: application/json', 'Accept: application/json'],
                          json_encode(['client_id' => $cfg['api_key'], 'client_secret' => $cfg['api_secret'], 'code' => (string) ($q['code'] ?? '')]));
    $token = (string) ($r['json']['access_token'] ?? '');
    if (!$r['ok'] || $token === '') return ['ok' => false, 'error' => 'Shopify did not give an access token: ' . shopify_err($r), 'client_id' => (int) $state['client_id']];
    return shopify_connected((int) $state['client_id'], $state['user_id'] !== null ? (int) $state['user_id'] : null, $shop, 'oauth',
                             ['access' => $token, 'scope' => (string) ($r['json']['scope'] ?? '')], null);
}

/**
 * Keys: an Admin API access token (shpat_…), or the app's client ID + secret. The API secret is
 * needed either way — it is what Shopify signs its events with.
 */
function shopify_connect_keys(int $clientId, ?int $userId, string $shopRaw, string $token, string $appId, string $secret): array
{
    $shop = shopify_shop($shopRaw);
    if ($shop === '') return ['ok' => false, 'error' => 'Enter your store address, like mystore.myshopify.com.'];
    if ($secret === '') return ['ok' => false, 'error' => 'Paste the app\'s API secret key — Shopify signs its events with it.'];
    $tokens = [];
    if ($token !== '') $tokens = ['access' => $token];
    elseif ($appId !== '') {
        $tokens = ['cc_id' => $appId, 'cc_secret' => $secret];
        $t = shopify_cc_token($shop, $appId, $secret);
        if (!$t['ok']) return $t;
        $tokens += ['access' => $t['token'], 'expires_at' => $t['expires_at']];
    } else return ['ok' => false, 'error' => 'Paste the Admin API access token, or the app\'s client ID.'];
    return shopify_connected($clientId, $userId, $shop, 'key', $tokens, $secret);
}

/** A fresh token for an app installed on the merchant's own store (client-credentials grant, valid ~24 hours). */
function shopify_cc_token(string $shop, string $id, string $secret): array
{
    $r = integration_http('POST', shopify_base($shop) . '/admin/oauth/access_token', ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                          ['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret]);
    $tok = (string) ($r['json']['access_token'] ?? '');
    if (!$r['ok'] || $tok === '') return ['ok' => false, 'error' => 'Shopify refused the client ID and secret: ' . shopify_err($r)];
    return ['ok' => true, 'token' => $tok, 'expires_at' => time() + max(600, (int) ($r['json']['expires_in'] ?? 86399))];
}

/** Store the connection, read the store's name, then register events and import (in the worker). */
function shopify_connected(int $clientId, ?int $userId, string $shop, string $mode, array $tokens, ?string $hookSecret): array
{
    $probe = ['provider' => 'shopify', 'account' => $shop, 'tokens' => $tokens, 'id' => 0, 'client_id' => $clientId];
    $r = shopify_api($probe, 'GET', 'shop');
    if (!$r['ok']) return ['ok' => false, 'error' => 'Could not read the store with these details: ' . shopify_err($r), 'client_id' => $clientId];
    $shopInfo = (array) ($r['json']['shop'] ?? []);
    $id = integration_save($clientId, 'shopify', $shop, $tokens, [
        'auth_mode' => $mode, 'display_name' => mb_substr((string) ($shopInfo['name'] ?? $shop), 0, 190), 'connected_by' => $userId,
        'webhook_secret_enc' => $hookSecret !== null ? encrypt_secret($hookSecret) : null,
    ]);
    $it = integration_get($id);
    $s = integration_settings($it);
    $s['currency'] = (string) ($shopInfo['currency'] ?? '');
    integration_settings_save($it, $s);
    integration_log($it, 'out', 'connected', 'Connected ' . ($shopInfo['name'] ?? $shop) . ($mode === 'key' ? ' with keys' : ''));
    integration_job($it, 'register_webhooks', 0);
    integration_job($it, 'backfill', 0, ['what' => 'customers']);
    return ['ok' => true, 'id' => $id, 'client_id' => $clientId, 'name' => (string) ($shopInfo['name'] ?? $shop)];
}

/** Disconnect: remove our webhooks from the store, forget the tokens. Orders already received stay. */
function shopify_disconnect(array $it): void
{
    foreach ((array) ($it['cursor']['webhooks'] ?? []) as $hid) shopify_api($it, 'DELETE', 'webhooks/' . (int) $hid);
    db_run("UPDATE integrations SET status='disconnected', tokens_enc=NULL, webhook_secret_enc=NULL, updated_at=NOW() WHERE id=?", [(int) $it['id']]);
    db_run("UPDATE integration_jobs SET status='failed', last_error='Disconnected' WHERE integration_id=? AND status='queued'", [(int) $it['id']]);
    integration_log($it, 'out', 'disconnected', 'Disconnected');
}

/* ───────────────────────── calling Shopify ───────────────────────── */

function shopify_err(array $r): string
{
    $j = $r['json'] ?? null;
    if (is_array($j)) {
        $e = $j['errors'] ?? $j['error_description'] ?? $j['error'] ?? null;
        if (is_array($e)) $e = implode('; ', array_map(fn($k, $v) => (is_string($k) ? "$k: " : '') . (is_array($v) ? implode(', ', $v) : $v), array_keys($e), $e));
        if ($e) return mb_substr((string) $e, 0, 200) . ' (' . $r['http'] . ')';
    }
    return $r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['http'];
}

/** A token that works now (client-credentials tokens are renewed shortly before they lapse). */
function shopify_token(array &$it): string
{
    $t = $it['tokens'];
    if (!empty($t['cc_id']) && (int) ($t['expires_at'] ?? 0) < time() + 300) {
        $n = shopify_cc_token((string) $it['account'], (string) $t['cc_id'], (string) $t['cc_secret']);
        if ($n['ok']) {
            $t['access'] = $n['token']; $t['expires_at'] = $n['expires_at'];
            $it['tokens'] = $t;
            if (!empty($it['id'])) integration_set_tokens($it, $t);
        }
    }
    return (string) ($t['access'] ?? '');
}

/** Admin REST call: shopify_api($it, 'GET', 'orders', ['status' => 'any']). Waits and retries once when rate-limited. */
function shopify_api(array &$it, string $method, string $path, array $query = [], ?array $body = null): array
{
    $url = shopify_base((string) $it['account']) . '/admin/api/' . shopify_api_version() . '/' . ltrim($path, '/') . '.json' . ($query ? '?' . http_build_query($query) : '');
    $hdr = ['X-Shopify-Access-Token: ' . shopify_token($it), 'Accept: application/json', 'Content-Type: application/json'];
    for ($try = 0; $try < 2; $try++) {
        $r = integration_http($method, $url, $hdr, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
        if ($r['http'] !== 429) break;
        sleep(min(4, max(1, (int) ($r['headers']['retry-after'] ?? 2))));
    }
    if ($r['http'] === 401 && !empty($it['id'])) integration_error($it, 'Shopify no longer accepts our access — connect the store again.');
    return $r;
}

/** The page_info of the next page, from Shopify's Link header. */
function shopify_next_page(array $r): string
{
    $l = (string) ($r['headers']['link'] ?? '');
    return preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>;\s*rel="next"/', $l, $m) ? urldecode($m[1]) : '';
}

/* ───────────────────────── reading Shopify's records ───────────────────────── */

/** The person behind an order, checkout or customer record. */
function shopify_person(array $o): array
{
    $cu = (array) ($o['customer'] ?? []);
    $addr = (array) ($o['shipping_address'] ?? $o['billing_address'] ?? $cu['default_address'] ?? []);
    $phone = (string) ($o['phone'] ?? '') ?: (string) ($cu['phone'] ?? '') ?: (string) ($addr['phone'] ?? '') ?: (string) ($o['billing_address']['phone'] ?? '');
    $sub = fn($c) => is_array($c) && ($c['state'] ?? '') === 'subscribed';
    return [
        'id' => (string) ($cu['id'] ?? ''),
        'first_name' => (string) ($cu['first_name'] ?? $o['first_name'] ?? $addr['first_name'] ?? ''),
        'last_name' => (string) ($cu['last_name'] ?? $o['last_name'] ?? $addr['last_name'] ?? ''),
        'email' => (string) ($o['email'] ?? $o['contact_email'] ?? $cu['email'] ?? ''),
        'phone' => $phone,
        'consent' => $sub($cu['sms_marketing_consent'] ?? $o['sms_marketing_consent'] ?? null) || $sub($cu['email_marketing_consent'] ?? $o['email_marketing_consent'] ?? null)
                     || !empty($o['buyer_accepts_marketing']) || !empty($o['accepts_marketing']),
    ];
}

function shopify_items(array $o): string
{
    $out = [];
    foreach (array_slice((array) ($o['line_items'] ?? []), 0, 12) as $li) {
        $t = trim((string) ($li['title'] ?? $li['name'] ?? ''));
        if ($t === '') continue;
        $q = (int) ($li['quantity'] ?? 1);
        $out[] = ($q > 1 ? $q . ' × ' : '') . $t . (!empty($li['variant_title']) && $li['variant_title'] !== 'Default Title' ? ' (' . $li['variant_title'] . ')' : '');
    }
    return implode(', ', $out);
}

/** Our words for where an order is. */
function shopify_order_status(array $o): string
{
    if (!empty($o['cancelled_at'])) return 'cancelled';
    $fin = (string) ($o['financial_status'] ?? '');
    if (in_array($fin, ['refunded', 'voided'], true)) return 'refunded';
    if (in_array((string) ($o['fulfillment_status'] ?? ''), ['fulfilled', 'partial'], true)) return 'shipped';
    if (in_array($fin, ['paid', 'partially_paid', 'partially_refunded'], true)) return 'paid';
    return 'placed';
}

function shopify_tracking(array $o): string
{
    foreach (array_reverse((array) ($o['fulfillments'] ?? [])) as $f) {
        $u = (string) (($f['tracking_urls'][0] ?? '') ?: ($f['tracking_url'] ?? ''));
        if ($u !== '' && preg_match('#^https?://#i', $u)) return $u;
    }
    return '';
}

function shopify_time(?string $t): ?string
{
    return $t && strtotime($t) ? date('Y-m-d H:i:s', strtotime($t)) : null;
}

/* ───────────────────────── handling events ───────────────────────── */

/**
 * One event from Shopify (or a record read while importing / polling). $quiet: an old record —
 * stored and linked, but nobody is messaged and no automation starts.
 */
function shopify_event(array $it, string $topic, array $p, bool $quiet = false): string
{
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $it['client_id']]);
    if (!$client) return 'no account';
    $s = integration_settings($it);
    $toCrmBuyers = in_array($s['to_crm'] ?? 'buyers', ['buyers', 'all'], true);
    $stage = (int) ($s['crm_stage_id'] ?? 0);

    if (str_starts_with($topic, 'customers/')) {
        if (empty($s['sync_customers'])) return 'customers not synced';
        $pp = shopify_person(['customer' => $p] + $p);
        $id = integration_person($client, $it, 'customer', (string) ($p['id'] ?? ''), $pp + ['source' => 'shopify'], ($s['to_crm'] ?? '') === 'all', $stage);
        return $id ? 'contact #' . $id : 'no phone or email';
    }

    if (str_starts_with($topic, 'checkouts/')) {
        $pp = shopify_person($p);
        $cid = integration_person($client, $it, 'customer', (string) ($p['customer']['id'] ?? ''), $pp, ($s['to_crm'] ?? '') === 'all', $stage);
        $done = !empty($p['completed_at']);
        [$row] = integration_order_upsert($it, 'checkout', (string) ($p['token'] ?? $p['id'] ?? ''), [
            'contact_id' => $cid, 'number' => null, 'status' => null, 'total' => isset($p['total_price']) ? (float) $p['total_price'] : null,
            'currency' => $p['currency'] ?? $p['presentment_currency'] ?? null, 'items' => shopify_items($p), 'phone' => $pp['phone'] ?: null, 'email' => $pp['email'] ?: null,
            'name' => trim($pp['first_name'] . ' ' . $pp['last_name']) ?: null, 'link_url' => $p['abandoned_checkout_url'] ?? null,
            'placed_at' => shopify_time($p['created_at'] ?? null),
        ]);
        if ($done) db_run("UPDATE ext_orders SET status=IF(reminders>0,'recovered','completed') WHERE id=? AND (status IS NULL OR status IN ('open','abandoned'))", [(int) $row['id']]);
        elseif (empty($row['status'])) db_run("UPDATE ext_orders SET status='open' WHERE id=?", [(int) $row['id']]);
        return 'checkout ' . ($done ? 'completed' : 'open');
    }

    if ($topic === 'fulfillments/create' || $topic === 'fulfillments/update') {
        $oid = (string) ($p['order_id'] ?? '');
        $row = $oid !== '' ? db_row("SELECT * FROM ext_orders WHERE integration_id=? AND kind='order' AND ext_id=?", [(int) $it['id'], $oid]) : null;
        if (!$row) { if ($oid !== '') integration_job($it, 'fetch_order', (int) $oid); return 'order not seen yet — fetching it'; }
        $track = (string) (($p['tracking_urls'][0] ?? '') ?: ($p['tracking_url'] ?? ''));
        if ($track !== '' && preg_match('#^https?://#i', $track)) db_run("UPDATE ext_orders SET tracking_url=? WHERE id=?", [mb_substr($track, 0, 500), (int) $row['id']]);
        if (in_array((string) ($p['status'] ?? 'success'), ['success', 'open', 'pending'], true) && !in_array($row['status'], ['cancelled', 'refunded'], true)) {
            db_run("UPDATE ext_orders SET status='shipped' WHERE id=?", [(int) $row['id']]);
            $row = db_row("SELECT * FROM ext_orders WHERE id=?", [(int) $row['id']]);
            if (!$quiet) { integration_notify($it, $row, 'order_shipped'); integration_trigger($it, 'shopify_fulfilled', $row); }
        }
        return 'fulfilment for order ' . ($row['number'] ?? $oid);
    }

    if (str_starts_with($topic, 'orders/')) {
        $pp = shopify_person($p);
        $cid = integration_person($client, $it, 'customer', (string) ($p['customer']['id'] ?? ''), $pp + ['source' => 'shopify'], $toCrmBuyers, $stage);
        $status = shopify_order_status($p);
        [$row, $prev] = integration_order_upsert($it, 'order', (string) ($p['id'] ?? ''), [
            'contact_id' => $cid, 'number' => (string) ($p['name'] ?? ('#' . ($p['order_number'] ?? ''))), 'status' => $status,
            'financial' => $p['financial_status'] ?? null, 'fulfillment' => $p['fulfillment_status'] ?? 'unfulfilled',
            'total' => isset($p['total_price']) ? (float) $p['total_price'] : null, 'currency' => $p['currency'] ?? null, 'items' => shopify_items($p),
            'phone' => $pp['phone'] ?: null, 'email' => $pp['email'] ?: null, 'name' => trim($pp['first_name'] . ' ' . $pp['last_name']) ?: null,
            'tracking_url' => shopify_tracking($p) ?: null, 'link_url' => $p['order_status_url'] ?? null, 'placed_at' => shopify_time($p['created_at'] ?? null),
        ]);
        // A checkout that became this order: recovered (if we had reminded them) or simply completed.
        $tok = (string) ($p['checkout_token'] ?? '');
        if ($tok !== '') {
            db_run("UPDATE ext_orders SET status=IF(status IN ('open','abandoned') OR status IS NULL, IF(reminders>0,'recovered','completed'), status),
                            recovered_order_id=?, contact_id=COALESCE(contact_id,?)
                     WHERE integration_id=? AND kind='checkout' AND ext_id=? AND recovered_order_id IS NULL", [(int) $row['id'], $cid, (int) $it['id'], $tok]);
        }
        if ($cid && db_has_column('contacts', 'deal_value') && $status !== 'cancelled' && $toCrmBuyers) {
            // The CRM shows what they have bought in total.
            $sum = db_val("SELECT SUM(total) FROM ext_orders WHERE contact_id=? AND kind='order' AND status NOT IN ('cancelled','refunded')", [$cid]);
            if ($sum !== null) db_run("UPDATE contacts SET deal_value=? WHERE id=? AND stage_id IS NOT NULL", [(float) $sum, $cid]);
        }
        if ($quiet || !$row) {
            // Imported history: mark everything as already told, so an update later does not message about the past.
            if ($row && $prev === null) foreach (['order_placed', 'order_paid', 'order_shipped', 'order_cancelled', 't:shopify_order', 't:shopify_paid', 't:shopify_fulfilled', 't:shopify_cancelled'] as $ev) integration_mark($row, $ev);
            return 'order ' . ($row['number'] ?? '') . ' stored';
        }
        $rules = $s['messages'];
        $paid = in_array((string) ($p['financial_status'] ?? ''), ['paid', 'partially_paid'], true);
        if ($prev === null || $topic === 'orders/create') {
            // Paid at checkout: one message, not "thank you for your order" and "payment received" together.
            if ($paid && !empty($rules['order_placed']['on'])) integration_mark($row, 'order_paid');
            integration_notify($it, $row, 'order_placed');
            integration_trigger($it, 'shopify_order', $row);
            $row = db_row("SELECT * FROM ext_orders WHERE id=?", [(int) $row['id']]);
        }
        if ($status === 'cancelled') {
            integration_notify($it, $row, 'order_cancelled');
            integration_trigger($it, 'shopify_cancelled', $row);
        } else {
            if ($paid || $status === 'paid') { integration_notify($it, $row, 'order_paid'); integration_trigger($it, 'shopify_paid', $row); }
            $row = db_row("SELECT * FROM ext_orders WHERE id=?", [(int) $row['id']]);
            if ($status === 'shipped') { integration_notify($it, $row, 'order_shipped'); integration_trigger($it, 'shopify_fulfilled', $row); }
        }
        return 'order ' . $row['number'] . ' ' . $status;
    }
    return 'ignored';
}

/* ───────────────────────── the worker ───────────────────────── */

function shopify_job(array $it, array $job, array $payload): array
{
    switch ((string) $job['kind']) {
        case 'event':
            $res = shopify_event($it, (string) ($payload['topic'] ?? ''), (array) ($payload['body'] ?? []));
            if (!empty($payload['log_id'])) db_run("UPDATE integration_log SET summary=? WHERE id=? AND integration_id=?", [mb_substr($res, 0, 300), (int) $payload['log_id'], (int) $it['id']]);
            else integration_log($it, 'in', (string) ($payload['topic'] ?? 'event'), $res);
            return ['ok' => true];

        case 'register_webhooks':
            $have = shopify_api($it, 'GET', 'webhooks', ['limit' => 250]);
            if (!$have['ok']) return ['ok' => false, 'error' => 'Could not list the store\'s webhooks: ' . shopify_err($have), 'retry' => $have['http'] !== 403];
            $mine = []; $url = shopify_webhook_url();
            foreach ((array) ($have['json']['webhooks'] ?? []) as $w) if (($w['address'] ?? '') === $url) $mine[(string) $w['topic']] = (int) $w['id'];
            $failed = [];
            foreach (SHOPIFY_TOPICS as $t) {
                if (isset($mine[$t])) continue;
                $r = shopify_api($it, 'POST', 'webhooks', [], ['webhook' => ['topic' => $t, 'address' => $url, 'format' => 'json']]);
                if ($r['ok'] && !empty($r['json']['webhook']['id'])) $mine[$t] = (int) $r['json']['webhook']['id'];
                else $failed[] = $t . ' (' . shopify_err($r) . ')';
            }
            $cur = $it['cursor']; $cur['webhooks'] = array_values($mine);
            integration_cursor_save($it, $cur);
            if ($failed && count($failed) === count(SHOPIFY_TOPICS)) return ['ok' => false, 'error' => 'Shopify refused the event subscriptions: ' . implode('; ', array_slice($failed, 0, 3))];
            integration_log($it, 'out', 'webhooks', count($mine) . ' event subscriptions in place' . ($failed ? ' — not allowed: ' . implode(', ', array_map(fn($f) => explode(' ', $f)[0], $failed)) : ''), !$failed);
            return ['ok' => true];

        case 'backfill':
            return shopify_backfill($it, $payload);

        case 'fetch_order':
            $r = shopify_api($it, 'GET', 'orders/' . (int) $job['ref_id']);
            if (!$r['ok']) return ['ok' => false, 'error' => shopify_err($r), 'retry' => $r['http'] >= 500 || $r['http'] === 0];
            $o = (array) ($r['json']['order'] ?? []);
            $recent = strtotime((string) ($o['created_at'] ?? '')) > time() - 3 * 86400;
            shopify_event($it, 'orders/updated', $o, !$recent);
            return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'Unknown job ' . $job['kind'], 'retry' => false];
}

/** Import, one page per run: customers, then the last N days of orders. Nobody is messaged about the past. */
function shopify_backfill(array $it, array $p): array
{
    $s = integration_settings($it);
    $what = (string) ($p['what'] ?? 'customers');
    if ($what === 'customers' && empty($s['sync_customers'])) { integration_job($it, 'backfill', 0, ['what' => 'orders']); return ['ok' => true]; }
    $q = ['limit' => 100];
    if (!empty($p['page'])) $q['page_info'] = (string) $p['page'];
    elseif ($what === 'orders') $q += ['status' => 'any', 'created_at_min' => date('c', time() - 86400 * max(1, min(365, (int) ($s['import_days'] ?? 60))))];
    $r = shopify_api($it, 'GET', $what, $q);
    if (!$r['ok']) return ['ok' => false, 'error' => 'Import of ' . $what . ': ' . shopify_err($r), 'retry' => $r['http'] !== 403];
    $n = 0;
    foreach ((array) ($r['json'][$what] ?? []) as $rec) { shopify_event($it, $what === 'orders' ? 'orders/updated' : 'customers/update', (array) $rec, true); $n++; }
    $total = (int) ($p['done'] ?? 0) + $n;
    $next = shopify_next_page($r);
    if ($next !== '') integration_job($it, 'backfill', null, ['what' => $what, 'page' => $next, 'done' => $total], 1);
    else {
        integration_log($it, 'in', 'import', 'Imported ' . $total . ' ' . $what);
        if ($what === 'customers') integration_job($it, 'backfill', 0, ['what' => 'orders']);
        else db_run("UPDATE integrations SET last_sync_at=NOW() WHERE id=?", [(int) $it['id']]);
    }
    return ['ok' => true];
}

/** Every minute: abandoned-cart reminders. Every 15 minutes: a poll for anything the events missed. */
function shopify_tick(array $it): int
{
    $n = 0;
    $s = integration_settings($it);
    $rule = $s['messages']['cart_abandoned'] ?? [];
    $client = null;
    // Carts left open longer than the wait, by someone we can reach.
    $wait = max(15, (int) ($rule['delay'] ?? 60));
    foreach (db_all("SELECT * FROM ext_orders WHERE integration_id=? AND kind='checkout' AND status='open' AND reminders=0 AND contact_id IS NOT NULL
                       AND updated_at < NOW() - INTERVAL ? MINUTE AND updated_at > NOW() - INTERVAL 3 DAY LIMIT 50", [(int) $it['id'], $wait]) as $co) {
        if (!db_run("UPDATE ext_orders SET status='abandoned' WHERE id=? AND status='open'", [(int) $co['id']])) continue;
        $co['status'] = 'abandoned';
        $sent = integration_notify($it, $co, 'cart_abandoned');
        $flows = integration_trigger($it, 'shopify_abandoned', $co);
        if ($sent || $flows) db_run("UPDATE ext_orders SET reminders=1 WHERE id=?", [(int) $co['id']]);
        $n += (int) $sent;
    }
    $second = (int) ($rule['second_delay'] ?? 0);           // hours after the first, 0 = no second reminder
    if ($second > 0 && !empty($rule['on'])) {
        foreach (db_all("SELECT * FROM ext_orders WHERE integration_id=? AND kind='checkout' AND status='abandoned' AND reminders=1
                           AND updated_at < NOW() - INTERVAL ? HOUR AND updated_at > NOW() - INTERVAL 7 DAY LIMIT 50", [(int) $it['id'], $second]) as $co) {
            db_run("UPDATE ext_orders SET reminders=2 WHERE id=?", [(int) $co['id']]);
            if (integration_notify($it, $co, 'cart_abandoned', 0, 'cart_abandoned2')) $n++;
        }
    }
    // The safety poll.
    $cur = $it['cursor'];
    if ((int) ($cur['poll_at'] ?? 0) < time() - 900 && $it['status'] === 'active' && empty($it['last_error'])) {
        $since = date('c', max(time() - 86400, (int) ($cur['polled_to'] ?? 0) ?: time() - 3600) - 120);
        $cur['poll_at'] = time();
        $to = time();
        foreach (['orders' => ['status' => 'any'], 'checkouts' => []] as $what => $extra) {
            $r = shopify_api($it, 'GET', $what, ['limit' => 100, 'updated_at_min' => $since] + $extra);
            if (!$r['ok']) continue;
            foreach ((array) ($r['json'][$what] ?? []) as $rec) {
                $old = strtotime((string) ($rec['created_at'] ?? '')) < time() - 2 * 86400;
                shopify_event($it, $what === 'orders' ? 'orders/updated' : 'checkouts/update', (array) $rec, $old);
            }
        }
        $cur['polled_to'] = $to;
        integration_cursor_save($it, $cur);
    }
    return $n;
}

/* ───────────────────────── incoming events ───────────────────────── */

/** The secret Shopify signs this connection's events with. */
function shopify_hook_secret(array $it): string
{
    if (!empty($it['webhook_secret_enc'])) return (string) decrypt_secret((string) $it['webhook_secret_enc']);
    return shopify_cfg()['api_secret'];
}

/**
 * The privacy requests Shopify sends every app (set in the app's settings, not per store):
 * a customer's data, erase a customer, erase a store.
 */
function shopify_privacy(array $it, string $topic, array $p): string
{
    if ($topic === 'customers/data_request') {
        $n = (int) db_val("SELECT COUNT(*) FROM ext_orders WHERE integration_id=? AND email=?", [(int) $it['id'], (string) ($p['customer']['email'] ?? '-')]);
        return 'Data request for ' . ($p['customer']['email'] ?? 'a customer') . ': ' . $n . ' order(s) held. Export them from the contact if the store asks.';
    }
    if ($topic === 'customers/redact') {
        $ids = array_map('strval', (array) ($p['orders_to_redact'] ?? []));
        $email = (string) ($p['customer']['email'] ?? '');
        if ($ids) db_run("DELETE FROM ext_orders WHERE integration_id=? AND ext_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", array_merge([(int) $it['id']], $ids));
        if ($email !== '') db_run("DELETE FROM ext_orders WHERE integration_id=? AND email=?", [(int) $it['id'], $email]);
        if (!empty($p['customer']['id'])) db_run("DELETE FROM integration_links WHERE integration_id=? AND kind='customer' AND ext_id=?", [(int) $it['id'], (string) $p['customer']['id']]);
        return 'Erased the store\'s data about a customer (' . count($ids) . ' order(s))';
    }
    if ($topic === 'shop/redact') {
        db_run("DELETE FROM ext_orders WHERE integration_id=?", [(int) $it['id']]);
        db_run("DELETE FROM integration_links WHERE integration_id=?", [(int) $it['id']]);
        db_run("UPDATE integrations SET status='disconnected', tokens_enc=NULL WHERE id=?", [(int) $it['id']]);
        return 'Erased everything received from the store';
    }
    return 'ignored';
}
