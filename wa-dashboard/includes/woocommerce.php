<?php
declare(strict_types=1);

/**
 * WooCommerce: connect a WordPress store, receive its orders and customers, message buyers.
 *
 * Connecting, either way — nothing for the platform admin to register first:
 *   - Connect button: WooCommerce's own key-approval screen (/wc-auth/v1/authorize). The store
 *     owner approves "Revenect" and the store POSTs a REST API key pair to woo_auth.php.
 *   - Keys: a consumer key + secret made under WooCommerce → Settings → Advanced → REST API.
 *
 * The store's address is typed by the client, so every call to it goes through woo_http(): HTTPS
 * only, the host resolved once and refused if private, and that IP pinned for the request so a
 * second DNS answer cannot point it somewhere inside. Redirects are not followed.
 *
 * Events: webhooks (order.created, order.updated, customer.created, customer.updated) signed
 * with a secret of ours (X-WC-Webhook-Signature), plus a 15-minute poll of changed orders.
 * WooCommerce has no abandoned-cart record; the nearest thing it has is an order left unpaid
 * ("pending payment"), which gets a reminder with the store's own payment link.
 */

const WOO_TOPICS = ['order.created', 'order.updated', 'customer.created', 'customer.updated'];

/** "shop.example.com", "https://shop.example.com/wp-admin/" → https://shop.example.com, or '' if not a usable address. */
function woo_store(string $raw): string
{
    $s = trim($raw);
    if ($s === '') return '';
    if (!preg_match('#^https?://#i', $s)) $s = 'https://' . $s;
    $p = parse_url($s);
    $host = strtolower((string) ($p['host'] ?? ''));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host) || !str_contains($host, '.') && !config('webhook_allow_private', false)) return '';
    $path = rtrim(preg_replace('#/(wp-admin|wp-json|shop|my-account|wp-login\.php).*$#i', '', (string) ($p['path'] ?? '')), '/');
    if (!preg_match('#^[A-Za-z0-9/_.~-]*$#', $path)) return '';
    $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
    if ($scheme !== 'https' && !config('webhook_allow_private', false)) $scheme = 'https';   // keys travel only over TLS
    return $scheme . '://' . $host . (isset($p['port']) ? ':' . (int) $p['port'] : '') . $path;
}

/**
 * A call to the client's store. The host must resolve to public addresses only (test rigs may
 * allow private ones), and curl is pinned to the address we checked.
 */
function woo_http(string $method, string $url, array $headers = [], $body = null): array
{
    $p = parse_url($url);
    $host = (string) ($p['host'] ?? '');
    $scheme = strtolower((string) ($p['scheme'] ?? ''));
    $fail = fn(string $e) => ['ok' => false, 'http' => 0, 'json' => null, 'body' => '', 'error' => $e, 'headers' => []];
    if ($host === '' || !in_array($scheme, ['https', 'http'], true)) return $fail('That is not a store address.');
    $open = (bool) config('webhook_allow_private', false);
    if ($scheme !== 'https' && !$open) return $fail('The store must use https://.');
    $opts = [CURLOPT_PROTOCOLS => $open ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS];
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = gethostbynamel($host) ?: [];
        if (!$ips) return $fail('The store address could not be found.');
        foreach ($ips as $ip) if (!$open && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $fail('That address is on a private network.');
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $opts[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $ips[0]];
    } elseif (!$open && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $fail('That address is on a private network.');
    }
    return integration_http($method, $url, $headers, $body, 25, $opts);
}

function woo_err(array $r): string
{
    $j = $r['json'] ?? null;
    if (is_array($j) && !empty($j['message'])) return mb_substr(strip_tags((string) $j['message']), 0, 200) . (!empty($j['code']) ? ' [' . $j['code'] . ']' : '') . ' (' . $r['http'] . ')';
    if ($r['http'] >= 300 && $r['http'] < 400 && !empty($r['headers']['location'])) return 'The store redirects to ' . mb_substr((string) $r['headers']['location'], 0, 120) . ' — connect with that address instead.';
    return $r['error'] !== '' ? $r['error'] : 'HTTP ' . $r['http'];
}

/**
 * WooCommerce REST v3: woo_api($it, 'GET', 'orders', ['per_page' => 50]). Keys go as Basic auth;
 * a server that drops that header gets them as query parameters instead (HTTPS only), and the
 * connection remembers which works. Stores without pretty links are reached through ?rest_route=.
 */
function woo_api(array &$it, string $method, string $path, array $query = [], ?array $body = null): array
{
    $t = $it['tokens'];
    $base = rtrim((string) $it['account'], '/');
    $call = function (bool $qs, bool $route) use ($method, $path, $query, $body, $t, $base) {
        $q = $query + ($qs ? ['consumer_key' => $t['ck'] ?? '', 'consumer_secret' => $t['cs'] ?? ''] : []);
        $url = $route ? $base . '/?' . http_build_query(['rest_route' => '/wc/v3/' . ltrim($path, '/')] + $q)
                      : $base . '/wp-json/wc/v3/' . ltrim($path, '/') . ($q ? '?' . http_build_query($q) : '');
        $hdr = ['Accept: application/json', 'User-Agent: Revenect/1.0'];
        if (!$qs) $hdr[] = 'Authorization: Basic ' . base64_encode(($t['ck'] ?? '') . ':' . ($t['cs'] ?? ''));
        if ($body !== null) $hdr[] = 'Content-Type: application/json';
        return woo_http($method, $url, $hdr, $body !== null ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null);
    };
    $qs = !empty($t['qs']); $route = !empty($t['route']);
    $r = $call($qs, $route);
    // No pretty links: /wp-json is a 404 page. Try the plain route once.
    if (!$route && $r['http'] === 404 && !is_array($r['json'])) { $r2 = $call($qs, true); if ($r2['http'] !== 404) { $r = $r2; $route = true; } }
    // The Authorization header did not arrive (common on shared hosting): keys in the query.
    if (!$qs && $r['http'] === 401 && (str_starts_with((string) $it['account'], 'https://') || config('webhook_allow_private', false))) { $r2 = $call(true, $route); if ($r2['http'] !== 401) { $r = $r2; $qs = true; } }
    if (($qs !== !empty($t['qs']) || $route !== !empty($t['route'])) && $r['ok']) {
        $t['qs'] = $qs ? 1 : 0; $t['route'] = $route ? 1 : 0; $it['tokens'] = $t;
        if (!empty($it['id'])) integration_set_tokens($it, $t);
    }
    if ($r['http'] === 401 && !empty($it['id'])) integration_error($it, 'The store no longer accepts our key — it may have been deleted in WooCommerce. Connect again.');
    return $r;
}

function woo_webhook_url(array $it): string { return integration_url('webhook_woo.php?t=' . $it['hook_token']); }
function woo_auth_callback(): string { return integration_url('woo_auth.php'); }

/* ───────────────────────── connecting ───────────────────────── */

/** WooCommerce's own approval screen. user_id carries our single-use state back with the keys. */
function woo_auth_url(int $clientId, ?int $userId, string $store): string
{
    $state = integration_state_make($clientId, $userId, 'woocommerce', $store);
    return rtrim($store, '/') . '/wc-auth/v1/authorize?' . http_build_query([
        'app_name' => brand_name_safe(), 'scope' => 'read_write', 'user_id' => $state,
        'return_url' => integration_url('client/apps.php?woo=' . substr($state, 0, 12)), 'callback_url' => woo_auth_callback(),
    ]);
}

function brand_name_safe(): string { return function_exists('brand_name') ? mb_substr(brand_name(), 0, 40) : 'Revenect'; }

/** The store POSTs the approved key pair here (woo_auth.php). */
function woo_finish_auth(array $p): array
{
    $st = integration_state_take((string) ($p['user_id'] ?? ''), 'woocommerce');
    if (!$st) return ['ok' => false, 'error' => 'Unknown or expired request.'];
    $ck = (string) ($p['consumer_key'] ?? ''); $cs = (string) ($p['consumer_secret'] ?? '');
    if (!preg_match('/^ck_[0-9a-f]{40}$/', $ck) || !preg_match('/^cs_[0-9a-f]{40}$/', $cs)) return ['ok' => false, 'error' => 'The store did not send a valid key.'];
    if (($p['key_permissions'] ?? 'read_write') !== 'read_write') return ['ok' => false, 'error' => 'The key must allow read and write (to add the order notifications).'];
    // The store is waiting on this answer, so the key is not tried now: the worker's first calls do that, and the page shows any problem.
    return woo_connected((int) $st['client_id'], $st['user_id'] !== null ? (int) $st['user_id'] : null, (string) $st['extra'], 'oauth', ['ck' => $ck, 'cs' => $cs], false);
}

function woo_connect_keys(int $clientId, ?int $userId, string $storeRaw, string $ck, string $cs): array
{
    $store = woo_store($storeRaw);
    if ($store === '') return ['ok' => false, 'error' => 'Enter your store address, like https://shop.example.com.'];
    if (!preg_match('/^ck_[0-9a-f]{40}$/', $ck) || !preg_match('/^cs_[0-9a-f]{40}$/', $cs)) return ['ok' => false, 'error' => 'Paste the consumer key (ck_…) and consumer secret (cs_…) exactly as WooCommerce showed them.'];
    return woo_connected($clientId, $userId, $store, 'key', ['ck' => $ck, 'cs' => $cs]);
}

/** Check the key against the store, save it, then add the webhooks and import (in the worker). */
function woo_connected(int $clientId, ?int $userId, string $store, string $mode, array $tokens, bool $check = true): array
{
    $probe = ['id' => 0, 'client_id' => $clientId, 'provider' => 'woocommerce', 'account' => $store, 'tokens' => $tokens];
    $env = $settings = [];
    if ($check) {
        $r = woo_api($probe, 'GET', 'system_status');
        if (!$r['ok'] && $r['http'] !== 401) $r = woo_api($probe, 'GET', 'orders', ['per_page' => 1]);   // a key without system_status rights still works
        if (!$r['ok']) return ['ok' => false, 'error' => 'Could not open the store with this key: ' . woo_err($r), 'client_id' => $clientId];
        $env = (array) ($r['json']['environment'] ?? []);
        $settings = (array) ($r['json']['settings'] ?? []);
    }
    $name = (string) parse_url($store, PHP_URL_HOST);
    $id = integration_save($clientId, 'woocommerce', $store, $probe['tokens'], [
        'auth_mode' => $mode, 'display_name' => mb_substr($name, 0, 190), 'connected_by' => $userId,
        'hook_token' => bin2hex(random_bytes(24)), 'webhook_secret_enc' => encrypt_secret(bin2hex(random_bytes(24))),
    ]);
    $it = integration_get($id);
    $s = integration_settings($it);
    if (!empty($settings['currency'])) $s['currency'] = (string) $settings['currency'];
    if (!empty($env['version'])) $s['wc_version'] = (string) $env['version'];
    integration_settings_save($it, $s);
    integration_log($it, 'out', 'connected', 'Connected ' . $name . ($mode === 'key' ? ' with keys' : ''));
    integration_job($it, 'register_webhooks', 0);
    integration_job($it, 'backfill', 0, ['what' => 'customers']);
    return ['ok' => true, 'id' => $id, 'client_id' => $clientId, 'name' => $name];
}

/** Disconnect: remove our webhooks from the store and forget the key (the store owner deletes the key in WooCommerce). */
function woo_disconnect(array $it): void
{
    foreach ((array) ($it['cursor']['webhooks'] ?? []) as $hid) woo_api($it, 'DELETE', 'webhooks/' . (int) $hid, ['force' => 'true']);
    db_run("UPDATE integrations SET status='disconnected', tokens_enc=NULL, webhook_secret_enc=NULL, hook_token=NULL, updated_at=NOW() WHERE id=?", [(int) $it['id']]);
    db_run("UPDATE integration_jobs SET status='failed', last_error='Disconnected' WHERE integration_id=? AND status='queued'", [(int) $it['id']]);
    integration_log($it, 'out', 'disconnected', 'Disconnected — also delete the "Revenect" key under WooCommerce → Settings → Advanced → REST API');
}

/* ───────────────────────── reading the store's records ───────────────────────── */

function woo_person(array $o): array
{
    $b = (array) ($o['billing'] ?? []); $sh = (array) ($o['shipping'] ?? []);
    return [
        'first_name' => (string) (($b['first_name'] ?? '') ?: ($o['first_name'] ?? '') ?: ($sh['first_name'] ?? '')),
        'last_name' => (string) (($b['last_name'] ?? '') ?: ($o['last_name'] ?? '') ?: ($sh['last_name'] ?? '')),
        'email' => (string) (($b['email'] ?? '') ?: ($o['email'] ?? '')),
        'phone' => (string) (($b['phone'] ?? '') ?: ($sh['phone'] ?? '')),
        'consent' => false,                                    // WooCommerce keeps no marketing consent of its own
    ];
}

function woo_items(array $o): string
{
    $out = [];
    foreach (array_slice((array) ($o['line_items'] ?? []), 0, 12) as $li) {
        $t = trim(html_entity_decode((string) ($li['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($t === '') continue;
        $q = (int) ($li['quantity'] ?? 1);
        $out[] = ($q > 1 ? $q . ' × ' : '') . $t;
    }
    return implode(', ', $out);
}

/** Our words for a WooCommerce order status. */
function woo_order_status(array $o): string
{
    return match ((string) ($o['status'] ?? '')) {
        'completed' => 'shipped',
        'processing' => 'paid',
        'cancelled' => 'cancelled',
        'refunded' => 'refunded',
        'failed' => 'failed',
        'on-hold', 'pending' => !empty($o['date_paid_gmt']) || !empty($o['date_paid']) ? 'paid' : 'placed',
        default => 'placed',
    };
}

/** A tracking link, when a shipment-tracking plugin put one in the order's details. */
function woo_tracking(array $o): string
{
    foreach ((array) ($o['meta_data'] ?? []) as $m) {
        $k = strtolower((string) ($m['key'] ?? ''));
        if (!str_contains($k, 'track')) continue;
        $vals = is_array($m['value'] ?? null) ? (array) $m['value'] : [$m['value'] ?? ''];
        $found = '';
        array_walk_recursive($vals, function ($v) use (&$found) { if (!$found && is_string($v) && preg_match('#^https://[^\s"<>]+$#i', $v)) $found = $v; });
        if (!empty($found)) return $found;
    }
    foreach ((array) ($o['shipment_trackings'] ?? []) as $t) if (!empty($t['tracking_link']) && preg_match('#^https://#i', (string) $t['tracking_link'])) return (string) $t['tracking_link'];
    return '';
}

function woo_time(array $o, string $k): ?string
{
    $t = (string) ($o[$k . '_gmt'] ?? '');
    if ($t !== '') return date('Y-m-d H:i:s', strtotime($t . 'Z'));
    $t = (string) ($o[$k] ?? '');
    return $t !== '' && strtotime($t) ? date('Y-m-d H:i:s', strtotime($t)) : null;
}

/* ───────────────────────── handling events ───────────────────────── */

/** One record from the store. $quiet: history — stored and linked, nobody messaged. */
function woo_event(array $it, string $topic, array $p, bool $quiet = false): string
{
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $it['client_id']]);
    if (!$client) return 'no account';
    $s = integration_settings($it);
    $toCrmBuyers = in_array($s['to_crm'] ?? 'buyers', ['buyers', 'all'], true);
    $stage = (int) ($s['crm_stage_id'] ?? 0);

    if (str_starts_with($topic, 'customer.')) {
        if (empty($s['sync_customers'])) return 'customers not synced';
        $pp = woo_person($p);
        $id = integration_person($client, $it, 'customer', (string) ($p['id'] ?? ''), $pp + ['source' => 'woocommerce'], ($s['to_crm'] ?? '') === 'all', $stage);
        return $id ? 'contact #' . $id : 'no phone or email';
    }
    if (!str_starts_with($topic, 'order.') || empty($p['id'])) return 'ignored';
    if (in_array((string) ($p['status'] ?? ''), ['checkout-draft', 'trash', 'auto-draft'], true)) return 'draft order — ignored';

    $pp = woo_person($p);
    $custId = (int) ($p['customer_id'] ?? 0);
    $cid = integration_person($client, $it, 'customer', $custId ? (string) $custId : '', $pp + ['source' => 'woocommerce'], $toCrmBuyers, $stage);
    $status = woo_order_status($p);
    $base = rtrim((string) $it['account'], '/');
    $received = !empty($p['order_key']) ? $base . '/checkout/order-received/' . (int) $p['id'] . '/?key=' . rawurlencode((string) $p['order_key']) : null;
    $pay = (string) ($p['payment_url'] ?? '');
    $link = $status === 'placed' && preg_match('#^https?://#i', $pay) ? $pay : $received;    // unpaid: the link to pay
    [$row, $prev] = integration_order_upsert($it, 'order', (string) $p['id'], [
        'contact_id' => $cid, 'number' => '#' . ($p['number'] ?? $p['id']), 'status' => $status, 'financial' => (string) ($p['status'] ?? ''),
        'fulfillment' => $status === 'shipped' ? 'completed' : null, 'total' => isset($p['total']) ? (float) $p['total'] : null, 'currency' => $p['currency'] ?? null,
        'items' => woo_items($p), 'phone' => $pp['phone'] ?: null, 'email' => $pp['email'] ?: null, 'name' => trim($pp['first_name'] . ' ' . $pp['last_name']) ?: null,
        'tracking_url' => woo_tracking($p) ?: null, 'link_url' => $link, 'placed_at' => woo_time($p, 'date_created'),
    ]);
    if (!$row) return 'not stored';
    if ($cid && $status !== 'cancelled' && $toCrmBuyers) {
        $sum = db_val("SELECT SUM(total) FROM ext_orders WHERE contact_id=? AND kind='order' AND status NOT IN ('cancelled','refunded','failed')", [$cid]);
        if ($sum !== null) db_run("UPDATE contacts SET deal_value=? WHERE id=? AND stage_id IS NOT NULL", [(float) $sum, $cid]);
    }
    if ($quiet) {
        if ($prev === null) foreach (['order_placed', 'order_paid', 'order_shipped', 'order_cancelled', 'payment_pending', 't:woo_order', 't:woo_paid', 't:woo_completed', 't:woo_cancelled', 't:woo_unpaid'] as $ev) integration_mark($row, $ev);
        return 'order ' . $row['number'] . ' stored';
    }
    if ($status === 'failed') return 'order ' . $row['number'] . ' payment failed';
    $paid = in_array($status, ['paid', 'shipped'], true);
    if ($prev === null) {
        if ($paid && !empty($s['messages']['order_placed']['on'])) integration_mark($row, 'order_paid');
        integration_notify($it, $row, 'order_placed');
        integration_trigger($it, 'woo_order', $row);
        $row = db_row("SELECT * FROM ext_orders WHERE id=?", [(int) $row['id']]);
    }
    if ($status === 'cancelled') {
        integration_notify($it, $row, 'order_cancelled');
        integration_trigger($it, 'woo_cancelled', $row);
    } elseif ($paid) {
        integration_mark($row, 'payment_pending');            // paid: no "complete your payment" reminder any more
        integration_notify($it, $row, 'order_paid');
        integration_trigger($it, 'woo_paid', $row);
        if ($status === 'shipped') {
            $row = db_row("SELECT * FROM ext_orders WHERE id=?", [(int) $row['id']]);
            if (!$row['tracking_url'] && ($t = woo_fetch_tracking($it, (int) $p['id'])) !== '') { db_run("UPDATE ext_orders SET tracking_url=? WHERE id=?", [$t, (int) $row['id']]); $row['tracking_url'] = $t; }
            integration_notify($it, $row, 'order_shipped');
            integration_trigger($it, 'woo_completed', $row);
        }
    }
    return 'order ' . $row['number'] . ' ' . $status;
}

/** The WooCommerce Shipment Tracking extension's own endpoint, when the store has it. */
function woo_fetch_tracking(array $it, int $orderId): string
{
    $r = woo_api($it, 'GET', 'orders/' . $orderId . '/shipment-trackings');
    if (!$r['ok'] || !is_array($r['json'])) return '';
    foreach (array_reverse($r['json']) as $t) if (!empty($t['tracking_link']) && preg_match('#^https://#i', (string) $t['tracking_link'])) return mb_substr((string) $t['tracking_link'], 0, 500);
    return '';
}

/* ───────────────────────── the worker ───────────────────────── */

function woo_job(array $it, array $job, array $payload): array
{
    switch ((string) $job['kind']) {
        case 'event':
            $res = woo_event($it, (string) ($payload['topic'] ?? ''), (array) ($payload['body'] ?? []));
            if (!empty($payload['log_id'])) db_run("UPDATE integration_log SET summary=? WHERE id=? AND integration_id=?", [mb_substr($res, 0, 300), (int) $payload['log_id'], (int) $it['id']]);
            else integration_log($it, 'in', (string) ($payload['topic'] ?? 'event'), $res);
            return ['ok' => true];

        case 'register_webhooks':
            $s = integration_settings($it);
            if (empty($s['currency'])) {                       // connected through the approval screen: read the store's details now
                $st = woo_api($it, 'GET', 'system_status');
                if ($st['ok']) { $s['currency'] = (string) ($st['json']['settings']['currency'] ?? ''); $s['wc_version'] = (string) ($st['json']['environment']['version'] ?? ''); integration_settings_save($it, $s); }
            }
            $secret = (string) decrypt_secret((string) $it['webhook_secret_enc']);
            $url = woo_webhook_url($it);
            $have = woo_api($it, 'GET', 'webhooks', ['per_page' => 100]);
            if (!$have['ok']) return ['ok' => false, 'error' => 'Could not list the store\'s webhooks: ' . woo_err($have), 'retry' => $have['http'] !== 403 && $have['http'] !== 401];
            $mine = [];
            foreach ((array) $have['json'] as $w) if (($w['delivery_url'] ?? '') === $url && ($w['status'] ?? '') === 'active') $mine[(string) $w['topic']] = (int) $w['id'];
            $failed = [];
            foreach (WOO_TOPICS as $t) {
                if (isset($mine[$t])) continue;
                $r = woo_api($it, 'POST', 'webhooks', [], ['name' => brand_name_safe() . ' — ' . $t, 'topic' => $t, 'delivery_url' => $url, 'secret' => $secret, 'status' => 'active']);
                if ($r['ok'] && !empty($r['json']['id'])) $mine[$t] = (int) $r['json']['id'];
                else $failed[] = $t . ' (' . woo_err($r) . ')';
            }
            $cur = $it['cursor']; $cur['webhooks'] = array_values($mine);
            integration_cursor_save($it, $cur);
            if (count($failed) === count(WOO_TOPICS)) return ['ok' => false, 'error' => 'The store refused the order notifications: ' . implode('; ', array_slice($failed, 0, 2))];
            integration_log($it, 'out', 'webhooks', count($mine) . ' notifications in place' . ($failed ? ' — refused: ' . implode(', ', array_map(fn($f) => explode(' ', $f)[0], $failed)) : ''), !$failed);
            return ['ok' => true];

        case 'backfill':
            $s = integration_settings($it);
            $what = (string) ($payload['what'] ?? 'customers');
            if ($what === 'customers' && empty($s['sync_customers'])) { integration_job($it, 'backfill', 0, ['what' => 'orders']); return ['ok' => true]; }
            $page = max(1, (int) ($payload['page'] ?? 1));
            $q = ['per_page' => 100, 'page' => $page];
            if ($what === 'orders') $q += ['after' => gmdate('Y-m-d\TH:i:s', time() - 86400 * max(1, min(365, (int) ($s['import_days'] ?? 60)))), 'orderby' => 'date', 'order' => 'desc'];
            else $q += ['role' => 'all'];
            $r = woo_api($it, 'GET', $what, $q);
            if (!$r['ok']) return ['ok' => false, 'error' => 'Import of ' . $what . ': ' . woo_err($r), 'retry' => !in_array($r['http'], [401, 403], true)];
            $n = 0;
            foreach ((array) $r['json'] as $rec) if (is_array($rec)) { woo_event($it, $what === 'orders' ? 'order.updated' : 'customer.updated', $rec, true); $n++; }
            $total = (int) ($payload['done'] ?? 0) + $n;
            $pages = (int) ($r['headers']['x-wp-totalpages'] ?? 0);
            if ($n > 0 && ($pages ? $page < $pages : $n === 100) && $page < 50) integration_job($it, 'backfill', null, ['what' => $what, 'page' => $page + 1, 'done' => $total], 1);
            else {
                integration_log($it, 'in', 'import', 'Imported ' . $total . ' ' . $what);
                if ($what === 'customers') integration_job($it, 'backfill', 0, ['what' => 'orders']);
                else db_run("UPDATE integrations SET last_sync_at=NOW() WHERE id=?", [(int) $it['id']]);
            }
            return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'Unknown job ' . $job['kind'], 'retry' => false];
}

/** Every minute: reminders for orders left unpaid. Every 15 minutes: a poll for changed orders the webhooks missed. */
function woo_tick(array $it): int
{
    $n = 0;
    $s = integration_settings($it);
    $wait = max(15, (int) ($s['messages']['payment_pending']['delay'] ?? 60));
    foreach (db_all("SELECT * FROM ext_orders WHERE integration_id=? AND kind='order' AND status='placed' AND financial='pending' AND contact_id IS NOT NULL
                       AND created_at < NOW() - INTERVAL ? MINUTE AND created_at > NOW() - INTERVAL 3 DAY
                       AND (notified IS NULL OR FIND_IN_SET('payment_pending', notified)=0) LIMIT 50", [(int) $it['id'], $wait]) as $o) {
        if (integration_notify($it, $o, 'payment_pending')) $n++;
        integration_trigger($it, 'woo_unpaid', $o);
    }
    $cur = $it['cursor'];
    if ((int) ($cur['poll_at'] ?? 0) < time() - 900 && $it['status'] === 'active' && empty($it['last_error'])) {
        $since = max(time() - 86400, (int) ($cur['polled_to'] ?? 0) ?: time() - 3600) - 120;
        $to = time();
        $r = woo_api($it, 'GET', 'orders', ['per_page' => 100, 'modified_after' => gmdate('Y-m-d\TH:i:s', $since), 'dates_are_gmt' => 'true', 'orderby' => 'modified', 'order' => 'desc']);
        if ($r['ok']) {
            foreach ((array) $r['json'] as $rec) {
                if (!is_array($rec)) continue;
                $old = (woo_time($rec, 'date_created') ?? '') < date('Y-m-d H:i:s', time() - 2 * 86400);
                woo_event($it, 'order.updated', $rec, $old);
            }
            $cur['polled_to'] = $to;
        }
        $cur['poll_at'] = time();
        integration_cursor_save($it, $cur);
    }
    return $n;
}
