<?php
declare(strict_types=1);

/**
 * Connected apps: built-in connections to Shopify, Zoho CRM, Zoho Books and Zoho Inventory.
 *
 *   integrations       one connected store / Zoho org per row; tokens encrypted; settings JSON
 *   integration_links  outside id ↔ Revenect contact (a person can be a Shopify customer and a Zoho lead)
 *   ext_orders         orders, abandoned checkouts, invoices and sales orders — shown on the lead,
 *                      messaged by the rules in each integration's settings
 *   integration_jobs   calls to make to an app, retried by the worker (integrations_tick)
 *   integration_log    what happened, for the Log tab
 *
 * Provider code lives in shopify.php and zoho.php; this file is what they share. Everything here
 * tolerates migration 067 not having run.
 */
require_once __DIR__ . '/crm_integrations.php';     // crm_int_upsert, crm_int_origin
require_once __DIR__ . '/crm_automation.php';       // crm_queue (WhatsApp / SMS / WhatsApp-then-SMS)
require_once __DIR__ . '/push.php';                 // setting_get / setting_set

/** Every app Revenect can connect to, with what it offers. */
function integrations_catalog(): array
{
    return [
        'shopify' => ['label' => 'Shopify', 'group' => 'Store', 'blurb' => 'Order confirmations and shipping updates by WhatsApp or SMS, abandoned-cart recovery, customers into your contacts and CRM, and Shopify events as automation triggers.'],
        'zoho_crm' => ['label' => 'Zoho CRM', 'group' => 'CRM', 'blurb' => 'Leads, contacts and deals kept in step both ways, and every WhatsApp, Messenger, Instagram and SMS conversation, note, call and visit logged on the Zoho record.'],
        'zoho_books' => ['label' => 'Zoho Books', 'group' => 'Finance', 'blurb' => 'Invoices, payments and sales orders: send the invoice link, thank customers for payment, and start automations from them.'],
        'zoho_inventory' => ['label' => 'Zoho Inventory', 'group' => 'Operations', 'blurb' => 'Sales orders and shipments: confirmations and "on its way" messages with the tracking link.'],
    ];
}

function integrations_ready(): bool
{
    static $ok = null;
    if ($ok === null) { try { db_val("SELECT 1 FROM integrations LIMIT 1"); $ok = true; } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

/** An integration row with its tokens and settings decoded. */
function integration_load(?array $row): ?array
{
    if (!$row) return null;
    $row['tokens'] = $row['tokens_enc'] ? (json_decode((string) decrypt_secret((string) $row['tokens_enc']), true) ?: []) : [];
    $row['settings_arr'] = json_decode((string) ($row['settings'] ?? ''), true) ?: [];
    $row['cursor'] = json_decode((string) ($row['cursor_data'] ?? ''), true) ?: [];
    return $row;
}

function integration_get(int $id, ?int $clientId = null): ?array
{
    return integration_load($clientId === null
        ? (db_row("SELECT * FROM integrations WHERE id=?", [$id]) ?: null)
        : (db_row("SELECT * FROM integrations WHERE id=? AND client_id=?", [$id, $clientId]) ?: null));
}

/** The client's connected accounts, newest first. */
function integrations_for(int $clientId, ?string $provider = null): array
{
    if (!integrations_ready()) return [];
    $rows = $provider === null
        ? db_all("SELECT * FROM integrations WHERE client_id=? AND status<>'disconnected' ORDER BY provider, id", [$clientId])
        : db_all("SELECT * FROM integrations WHERE client_id=? AND provider=? AND status<>'disconnected' ORDER BY id", [$clientId, $provider]);
    return array_map('integration_load', $rows);
}

/** Create or refresh a connection. Tokens are stored encrypted; returns the integration id. */
function integration_save(int $clientId, string $provider, string $account, array $tokens, array $fields = []): int
{
    unset($GLOBALS['__crm_zoho']);                           // the CRM's cached list of Zoho connections
    $enc = encrypt_secret(json_encode($tokens, JSON_UNESCAPED_SLASHES));
    $row = db_row("SELECT id, settings FROM integrations WHERE client_id=? AND provider=? AND account=?", [$clientId, $provider, $account]);
    $set = ['tokens_enc' => $enc, 'status' => 'active', 'last_error' => null] + $fields;
    if ($row) {
        $cols = implode(',', array_map(fn($k) => "`$k`=?", array_keys($set)));
        db_run("UPDATE integrations SET $cols, updated_at=NOW() WHERE id=?", array_merge(array_values($set), [(int) $row['id']]));
        return (int) $row['id'];
    }
    $set += ['settings' => json_encode(integration_default_settings($provider), JSON_UNESCAPED_UNICODE), 'created_at' => date('Y-m-d H:i:s'),
             'client_id' => $clientId, 'provider' => $provider, 'account' => $account];
    $cols = implode(',', array_map(fn($k) => "`$k`", array_keys($set)));
    return db_insert("INSERT INTO integrations ($cols) VALUES (" . implode(',', array_fill(0, count($set), '?')) . ")", array_values($set));
}

function integration_set_tokens(array $it, array $tokens): void
{
    db_run("UPDATE integrations SET tokens_enc=?, updated_at=NOW() WHERE id=?", [encrypt_secret(json_encode($tokens, JSON_UNESCAPED_SLASHES)), (int) $it['id']]);
}

function integration_settings_save(array $it, array $settings): void
{
    unset($GLOBALS['__crm_zoho']);
    db_run("UPDATE integrations SET settings=?, updated_at=NOW() WHERE id=?", [json_encode($settings, JSON_UNESCAPED_UNICODE), (int) $it['id']]);
}

function integration_cursor_save(array $it, array $cursor): void
{
    db_run("UPDATE integrations SET cursor_data=? WHERE id=?", [json_encode($cursor), (int) $it['id']]);
}

/** Record a problem (shown on the card) or clear it. */
function integration_error(array $it, string $err): void
{
    db_run("UPDATE integrations SET last_error=?, status=IF(?='', IF(status='error','active',status), 'error'), updated_at=NOW() WHERE id=?",
           [$err !== '' ? mb_substr($err, 0, 500) : null, $err, (int) $it['id']]);
}

/** A line in the Log tab; returns its id. $key drops a repeat of the same outside event (returns 0 then). */
function integration_log(array $it, string $dir, string $event, string $summary, bool $ok = true, ?string $key = null): int
{
    try {
        $id = db_insert("INSERT INTO integration_log (integration_id,client_id,direction,event,summary,ok,event_key,created_at) VALUES (?,?,?,?,?,?,?,NOW())",
                  [(int) $it['id'], (int) $it['client_id'], $dir, mb_substr($event, 0, 60), mb_substr($summary, 0, 300), $ok ? 1 : 0, $key !== null ? mb_substr($key, 0, 100) : null]);
    } catch (Throwable $e) { return 0; }                     // the unique event key: seen this one before
    if ($dir === 'in') db_run("UPDATE integrations SET last_event_at=NOW() WHERE id=?", [(int) $it['id']]);
    // Keep the newest 500 lines per integration.
    if (random_int(1, 50) === 1) {
        $cut = db_val("SELECT id FROM integration_log WHERE integration_id=? ORDER BY id DESC LIMIT 1 OFFSET 500", [(int) $it['id']]);
        if ($cut) db_run("DELETE FROM integration_log WHERE integration_id=? AND id<=?", [(int) $it['id'], (int) $cut]);
    }
    return $id;
}

/* ───────────────────────── settings ───────────────────────── */

/** What a new connection starts with: sensible, and nothing sent to customers until switched on. */
function integration_default_settings(string $provider): array
{
    $rule = fn(string $sms) => ['on' => 0, 'send_by' => 'whatsapp', 'template_id' => 0, 'vars' => [], 'sms_text' => $sms, 'delay' => 0];
    return match ($provider) {
        'shopify' => [
            'sync_customers' => 1, 'to_crm' => 'buyers', 'crm_stage_id' => 0, 'import_days' => 60,
            'messages' => [
                'order_placed'    => $rule('Hi {{first_name}}, thank you for your order {{order_number}} ({{total}}). We will let you know when it ships.'),
                'order_paid'      => $rule('Hi {{first_name}}, we received your payment for order {{order_number}}. Thank you!'),
                'order_shipped'   => $rule('Good news {{first_name}}: order {{order_number}} is on its way. Track it: {{tracking_url}}'),
                'order_cancelled' => $rule('Hi {{first_name}}, order {{order_number}} was cancelled. Reply to this message if you have any question.'),
                'cart_abandoned'  => $rule('Hi {{first_name}}, you left something in your cart at {{shop_name}}. Complete your order here: {{checkout_url}}') + ['delay' => 60, 'second_delay' => 0],
            ],
        ],
        'zoho_crm' => [
            'push_leads' => 1, 'pull_leads' => 1, 'module' => 'Leads', 'deals' => 1, 'deal_stages' => [], 'notes' => 1,
            'field_map' => [], 'stage_map' => [], 'won_stage' => 'Closed Won', 'lost_stage' => 'Closed Lost',
        ],
        'zoho_books', 'zoho_inventory' => [
            'sync_customers' => 1,
            'messages' => [
                'invoice_sent'     => $rule('Hi {{first_name}}, your invoice {{order_number}} for {{total}} is ready: {{order_url}}'),
                'payment_received' => $rule('Hi {{first_name}}, we received your payment for {{order_number}} ({{total}}). Thank you!'),
                'order_confirmed'  => $rule('Hi {{first_name}}, your order {{order_number}} is confirmed. We will update you when it ships.'),
                'order_shipped'    => $rule('Hi {{first_name}}, order {{order_number}} has shipped. {{tracking_url}}'),
            ],
        ],
        default => [],
    };
}

/** The settings, with any keys added since the connection was made filled from the defaults. */
function integration_settings(array $it): array
{
    $def = integration_default_settings((string) $it['provider']);
    $s = $it['settings_arr'] + $def;
    if (isset($def['messages'])) foreach ($def['messages'] as $k => $r) $s['messages'][$k] = ($s['messages'][$k] ?? []) + $r;
    return $s;
}

/** The events each provider can message about, in words. */
function integration_message_events(string $provider): array
{
    return match ($provider) {
        'shopify' => ['order_placed' => 'Order placed', 'order_paid' => 'Payment received', 'order_shipped' => 'Order shipped (with tracking link)',
                      'order_cancelled' => 'Order cancelled', 'cart_abandoned' => 'Abandoned cart (recovery message)'],
        'zoho_books' => ['invoice_sent' => 'Invoice sent (with link)', 'payment_received' => 'Payment received', 'order_confirmed' => 'Sales order confirmed'],
        'zoho_inventory' => ['order_confirmed' => 'Sales order confirmed', 'order_shipped' => 'Shipped (with tracking link)'],
        default => [],
    };
}

/** Variables a message rule can use, with an example for the preview. */
function integration_order_vars(): array
{
    return ['first_name' => 'Mona', 'name' => 'Mona Adel', 'order_number' => '#1042', 'total' => 'EGP 2,450.00', 'items' => '2 × Linen shirt, 1 × Cap',
            'tracking_url' => 'https://track.example/1Z999', 'order_url' => 'https://shop.example/orders/1042', 'checkout_url' => 'https://shop.example/checkouts/abc',
            'shop_name' => 'Your store'];
}

/* ───────────────────────── OAuth state ───────────────────────── */

function integration_state_make(int $clientId, ?int $userId, string $provider, string $extra = ''): string
{
    $state = bin2hex(random_bytes(24));
    db_run("INSERT INTO integration_oauth_state (state,client_id,user_id,provider,extra,created_at) VALUES (?,?,?,?,?,NOW())", [$state, $clientId, $userId, $provider, $extra]);
    try { db_run("DELETE FROM integration_oauth_state WHERE created_at < NOW() - INTERVAL 1 HOUR"); } catch (Throwable $e) {}
    return $state;
}

/** Single use: a replayed callback finds nothing. */
function integration_state_take(string $state, string $provider): ?array
{
    if (!preg_match('/^[0-9a-f]{48}$/', $state)) return null;
    $row = db_row("SELECT * FROM integration_oauth_state WHERE state=? AND provider=? AND created_at > NOW() - INTERVAL 1 HOUR", [$state, $provider]);
    if ($row) db_run("DELETE FROM integration_oauth_state WHERE state=?", [$state]);
    return $row ?: null;
}

/* ───────────────────────── HTTP ───────────────────────── */

/**
 * A call to a connected app. Hosts come from fixed patterns (a *.myshopify.com shop, Zoho's own
 * domains, or the test override), never from free text. Returns ['ok','http','json','body','error','headers'].
 */
function integration_http(string $method, string $url, array $headers = [], $body = null, int $timeout = 25): array
{
    $ch = curl_init($url);
    $hdrs = [];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method,
             CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => false,
             CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$hdrs) { $p = explode(':', $h, 2); if (count($p) === 2) $hdrs[strtolower(trim($p[0]))] = trim($p[1]); return strlen($h); }];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = is_array($body) ? http_build_query($body) : (string) $body;
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $http === 0) return ['ok' => false, 'http' => 0, 'json' => null, 'body' => '', 'error' => 'Could not reach the app: ' . $err, 'headers' => []];
    $json = json_decode((string) $raw, true);
    return ['ok' => $http >= 200 && $http < 300, 'http' => $http, 'json' => is_array($json) ? $json : null, 'body' => (string) $raw, 'error' => '', 'headers' => $hdrs];
}

/* ───────────────────────── jobs ───────────────────────── */

/** Queue a call for the worker. The same pending job (kind + ref) is not queued twice. */
function integration_job(array $it, string $kind, ?int $refId = null, array $payload = [], int $delaySec = 0): void
{
    if ($refId !== null && db_val("SELECT 1 FROM integration_jobs WHERE integration_id=? AND kind=? AND ref_id=? AND status='queued' LIMIT 1", [(int) $it['id'], $kind, $refId])) return;
    db_insert("INSERT INTO integration_jobs (integration_id,client_id,kind,ref_id,payload,status,attempts,next_at,created_at) VALUES (?,?,?,?,?,'queued',0,?,NOW())",
              [(int) $it['id'], (int) $it['client_id'], $kind, $refId, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null, date('Y-m-d H:i:s', time() + $delaySec)]);
}

/** Run due jobs. Each provider handles its own kinds; failures retry with backoff, 6 tries. */
function integration_jobs_tick(int $limit = 40): int
{
    if (!integrations_ready()) return 0;
    $n = 0;
    foreach (db_all("SELECT * FROM integration_jobs WHERE status='queued' AND next_at <= NOW() ORDER BY next_at, id LIMIT " . (int) $limit) as $j) {
        if (!db_run("UPDATE integration_jobs SET status='running' WHERE id=? AND status='queued'", [(int) $j['id']])) continue;
        $it = integration_get((int) $j['integration_id']);
        if (!$it || $it['status'] === 'disconnected') { db_run("UPDATE integration_jobs SET status='failed', last_error='Disconnected' WHERE id=?", [(int) $j['id']]); continue; }
        $payload = json_decode((string) ($j['payload'] ?? ''), true) ?: [];
        try {
            $r = match ((string) $it['provider']) {
                'shopify' => shopify_job($it, $j, $payload),
                'zoho_crm', 'zoho_books', 'zoho_inventory' => zoho_job($it, $j, $payload),
                default => ['ok' => false, 'error' => 'Unknown app', 'retry' => false],
            };
        } catch (Throwable $e) { $r = ['ok' => false, 'error' => $e->getMessage(), 'retry' => true]; }
        if ($r['ok']) { db_run("UPDATE integration_jobs SET status='done', done_at=NOW(), last_error=NULL WHERE id=?", [(int) $j['id']]); $n++; continue; }
        $att = (int) $j['attempts'] + 1;
        $retry = ($r['retry'] ?? true) && $att < 6;
        db_run("UPDATE integration_jobs SET status=?, attempts=?, next_at=?, last_error=? WHERE id=?",
               [$retry ? 'queued' : 'failed', $att, date('Y-m-d H:i:s', time() + 60 * (2 ** $att)), mb_substr((string) $r['error'], 0, 500), (int) $j['id']]);
        if (!$retry) { integration_log($it, 'out', $j['kind'], 'Failed: ' . $r['error'], false); integration_error($it, (string) $r['error']); }
    }
    db_run("DELETE FROM integration_jobs WHERE status='done' AND done_at < NOW() - INTERVAL 7 DAY");
    return $n;
}

/* ───────────────────────── people ───────────────────────── */

/** The contact linked to an outside record, if any. */
function integration_link_contact(array $it, string $kind, string $extId): ?int
{
    $v = db_val("SELECT contact_id FROM integration_links WHERE integration_id=? AND kind=? AND ext_id=?", [(int) $it['id'], $kind, $extId]);
    return $v ? (int) $v : null;
}

function integration_link_ext(array $it, string $kind, int $contactId): ?string
{
    $v = db_val("SELECT ext_id FROM integration_links WHERE integration_id=? AND kind=? AND contact_id=? ORDER BY id DESC LIMIT 1", [(int) $it['id'], $kind, $contactId]);
    return $v !== null && $v !== false ? (string) $v : null;
}

function integration_link_set(array $it, string $kind, string $extId, ?int $contactId, ?string $hash = null, array $data = []): void
{
    db_run("INSERT INTO integration_links (integration_id,client_id,kind,ext_id,contact_id,sync_hash,data,updated_at) VALUES (?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE contact_id=COALESCE(VALUES(contact_id),contact_id), sync_hash=COALESCE(VALUES(sync_hash),sync_hash),
                                    data=COALESCE(VALUES(data),data), updated_at=NOW()",
           [(int) $it['id'], (int) $it['client_id'], $kind, $extId, $contactId, $hash, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null]);
}

/**
 * Find or create the Revenect contact for someone in an outside app: by the link, then phone, then
 * email. $toCrm puts them in the CRM pipeline too (crm_int_upsert does the CRM part).
 * Marketing consent: opted in ('in', so campaigns reach them) only when the app says they agreed;
 * otherwise 'unknown' — order updates still go, bulk campaigns do not.
 */
function integration_person(array $client, array $it, string $kind, string $extId, array $p, bool $toCrm, int $stageId = 0): ?int
{
    $cid = (int) $client['id'];
    $linked = $extId !== '' ? integration_link_contact($it, $kind, $extId) : null;
    $phone = !empty($p['phone']) ? normalize_phone((string) $p['phone'], (string) ($client['default_country'] ?? '')) : '';
    $email = !empty($p['email']) && filter_var((string) $p['email'], FILTER_VALIDATE_EMAIL) ? strtolower((string) $p['email']) : '';
    $name = trim((string) ($p['name'] ?? trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''))));
    $c = $linked ? db_row("SELECT * FROM contacts WHERE id=? AND client_id=?", [$linked, $cid]) : null;
    if (!$c && $phone !== '') $c = db_row("SELECT * FROM contacts WHERE client_id=? AND phone_e164=?", [$cid, $phone]);
    if (!$c && $email !== '' && db_has_column('contacts', 'email')) $c = db_row("SELECT * FROM contacts WHERE client_id=? AND email=?", [$cid, $email]);
    $prev = crm_int_origin(); crm_int_origin((string) $it['provider']);
    try {
        if ($toCrm && function_exists('crm_enabled') && crm_enabled($client)) {
            $d = ['name' => $name, 'phone' => $phone, 'email' => $email];
            if (!empty($p['source'])) $d['source'] = (string) $p['source'];
            if ($phone === '' && $email === '' && !$c) return null;
            $r = crm_int_upsert($client, $d, ['system' => (string) $it['provider'], 'origin' => (string) $it['provider'], 'source' => (string) $it['provider'],
                                              'opt_in' => !empty($p['consent']) ? 'in' : 'unknown', 'stage_id' => $stageId ?: null, 'id' => $c ? (int) $c['id'] : null]);
            $contactId = !empty($r['ok']) ? (int) ($r['lead']['id'] ?? $r['id'] ?? 0) : 0;
            if (!$contactId && $c) $contactId = (int) $c['id'];
            if (!$contactId) return null;
        } elseif ($c) {
            $contactId = (int) $c['id'];
            if ($name !== '' && trim((string) $c['name']) === '') db_run("UPDATE contacts SET name=? WHERE id=?", [mb_substr($name, 0, 150), $contactId]);
            if ($email !== '' && db_has_column('contacts', 'email') && empty($c['email'])) db_run("UPDATE contacts SET email=? WHERE id=?", [$email, $contactId]);
        } else {
            if ($phone === '') return null;                 // a contact needs a number (an email-only customer waits until they give one)
            $cols = ['client_id' => $cid, 'phone_e164' => $phone, 'name' => mb_substr($name, 0, 150), 'opt_in_status' => !empty($p['consent']) ? 'in' : 'unknown',
                     'source' => (string) $it['provider'], 'created_at' => date('Y-m-d H:i:s')];
            if ($email !== '' && db_has_column('contacts', 'email')) $cols['email'] = $email;
            $contactId = db_insert("INSERT INTO contacts (" . implode(',', array_keys($cols)) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", array_values($cols));
        }
    } finally { crm_int_origin($prev); }
    if (!empty($p['consent'])) db_run("UPDATE contacts SET opt_in_status='in' WHERE id=? AND opt_in_status<>'out'", [$contactId]);
    if ($extId !== '') integration_link_set($it, $kind, $extId, $contactId);
    return $contactId;
}

/* ───────────────────────── orders & messages ───────────────────────── */

/** Store or update an order / checkout / invoice / sales order. Returns [row, previous status]. */
function integration_order_upsert(array $it, string $kind, string $extId, array $o): array
{
    $prev = db_row("SELECT * FROM ext_orders WHERE integration_id=? AND kind=? AND ext_id=?", [(int) $it['id'], $kind, $extId]);
    $vals = ['contact_id' => $o['contact_id'] ?? ($prev['contact_id'] ?? null), 'number' => $o['number'] ?? null, 'status' => $o['status'] ?? null,
             'financial' => $o['financial'] ?? null, 'fulfillment' => $o['fulfillment'] ?? null, 'total' => $o['total'] ?? null, 'currency' => $o['currency'] ?? null,
             'items' => isset($o['items']) ? mb_substr((string) $o['items'], 0, 2000) : null, 'phone' => $o['phone'] ?? null, 'email' => $o['email'] ?? null,
             'name' => isset($o['name']) ? mb_substr((string) $o['name'], 0, 150) : null, 'tracking_url' => isset($o['tracking_url']) ? mb_substr((string) $o['tracking_url'], 0, 500) : null,
             'link_url' => isset($o['link_url']) ? mb_substr((string) $o['link_url'], 0, 700) : null, 'placed_at' => $o['placed_at'] ?? null];
    if ($prev) {
        $set = []; $args = [];
        foreach ($vals as $k => $v) if ($v !== null) { $set[] = "`$k`=?"; $args[] = $v; }
        $args[] = (int) $prev['id'];
        db_run("UPDATE ext_orders SET " . implode(',', $set) . ($set ? ',' : '') . " updated_at=NOW() WHERE id=?", $args);
        $id = (int) $prev['id'];
    } else {
        $cols = ['client_id' => (int) $it['client_id'], 'integration_id' => (int) $it['id'], 'source' => (string) $it['provider'], 'kind' => $kind, 'ext_id' => $extId]
              + array_filter($vals, fn($v) => $v !== null) + ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
        $id = db_insert("INSERT INTO ext_orders (" . implode(',', array_map(fn($k) => "`$k`", array_keys($cols))) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", array_values($cols));
    }
    return [db_row("SELECT * FROM ext_orders WHERE id=?", [$id]), $prev ? (string) $prev['status'] : null];
}

/** The order's values for message variables. */
function integration_order_values(array $it, array $order): array
{
    $total = $order['total'] !== null ? trim(($order['currency'] ? $order['currency'] . ' ' : '') . number_format((float) $order['total'], 2)) : '';
    return ['order_number' => (string) ($order['number'] ?: $order['ext_id']), 'total' => $total, 'items' => (string) ($order['items'] ?? ''),
            'tracking_url' => (string) ($order['tracking_url'] ?? ''), 'order_url' => (string) ($order['link_url'] ?? ''),
            'checkout_url' => (string) ($order['link_url'] ?? ''), 'shop_name' => (string) ($it['display_name'] ?: $it['account'])];
}

/** {{order_number}} etc. replaced; contact variables ({{first_name}}, {{name}}) left for the sender. */
function integration_render(string $text, array $vals): string
{
    return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn($m) => array_key_exists($m[1], $vals) ? (string) $vals[$m[1]] : $m[0], $text);
}

/**
 * Claim an event on an order: true the first time, false ever after (and when another worker got
 * there first). The event is claimed whether or not a message goes, so switching a rule on later
 * never messages people about what already happened.
 */
function integration_claim(int $orderId, string $event): bool
{
    for ($i = 0; $i < 3; $i++) {
        $cur = db_val("SELECT notified FROM ext_orders WHERE id=?", [$orderId]);
        $done = array_filter(explode(',', (string) $cur));
        if (in_array($event, $done, true)) return false;
        $done[] = $event;
        if (db_run("UPDATE ext_orders SET notified=? WHERE id=? AND notified <=> ?", [mb_substr(implode(',', $done), 0, 255), $orderId, $cur])) return true;
    }
    return false;
}

function integration_unclaim(int $orderId, string $event): void
{
    $cur = (string) db_val("SELECT notified FROM ext_orders WHERE id=?", [$orderId]);
    db_run("UPDATE ext_orders SET notified=? WHERE id=?", [implode(',', array_diff(array_filter(explode(',', $cur)), [$event])) ?: null, $orderId]);
}

/** Mark an event as handled on an order without sending anything. */
function integration_mark(array $order, string $event): void
{
    integration_claim((int) $order['id'], $event);
}

/**
 * Send the customer the message an event's rule asks for — once per event per order, never twice.
 * Goes through the CRM message queue (WhatsApp template, SMS, or WhatsApp then SMS if it fails),
 * at any hour (transactional), skipping people who opted out.
 */
function integration_notify(array $it, array $order, string $event, int $delaySec = 0, ?string $claimAs = null): bool
{
    if (!integration_claim((int) $order['id'], $claimAs ?? $event)) return false;
    $s = integration_settings($it);
    $rule = $s['messages'][$event] ?? null;
    if (!$rule || empty($rule['on']) || empty($order['contact_id'])) return false;
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $it['client_id']]);
    if (!$client) return false;
    $vals = integration_order_values($it, $order);
    if (!function_exists('crm_sms_available')) require_once __DIR__ . '/crm_notify.php';
    $by = in_array($rule['send_by'] ?? '', ['whatsapp', 'sms', 'wa_then_sms'], true) ? (string) $rule['send_by'] : 'whatsapp';
    if ($by !== 'whatsapp' && !(function_exists('crm_sms_available') && crm_sms_available($client))) $by = 'whatsapp';
    $tokens = array_map(function ($t) use ($vals) {
        $t = (string) $t;
        if (preg_match('/^\{\{\s*([a-z_]+)\s*\}\}$/', $t, $m) && array_key_exists($m[1], $vals)) return 'text:' . ($vals[$m[1]] !== '' ? $vals[$m[1]] : '-');
        if (str_contains($t, '{{')) return 'text:' . integration_render($t, $vals);
        return $t;                                         // a contact token (first_name, name…) or text:…
    }, (array) ($rule['vars'] ?? []));
    $tpl = (int) ($rule['template_id'] ?? 0);
    if ($by !== 'sms' && !$tpl) { if ($by === 'whatsapp') return false; $by = 'sms'; }
    $sms = integration_render((string) ($rule['sms_text'] ?? ''), $vals);
    if ($by !== 'whatsapp' && trim($sms) === '') { if (!$tpl) return false; $by = 'whatsapp'; }
    $delay = max($delaySec, 60 * (int) ($rule['delay'] ?? 0));
    // Values are already in the tokens and the SMS text; the context only says "send at any hour".
    try {
        crm_queue($client, (int) $order['contact_id'], $tpl, $tokens, '', mb_substr($it['provider'] . ':' . $event, 0, 40),
                  date('Y-m-d H:i:s', time() + $delay), ['any_time' => 1, 'order_id' => (int) $order['id']], $by, $sms);
    } catch (Throwable $e) {
        integration_unclaim((int) $order['id'], $claimAs ?? $event);   // so a retry can still send it
        throw $e;
    }
    integration_log($it, 'out', $event, 'Message queued for ' . ($order['name'] ?: 'the customer') . ' — ' . $vals['order_number'] . ' (' . str_replace('_', ' then ', $by) . ')');
    return true;
}

/** Start automations listening for an app event, with the order's values available to their steps. */
function integration_trigger(array $it, string $kind, array $order): int
{
    if (!function_exists('automation_handle_crm_event')) require_once __DIR__ . '/automation.php';
    if (empty($order['contact_id']) || !integration_claim((int) $order['id'], 't:' . $kind)) return 0;
    $client = db_row("SELECT * FROM clients WHERE id=?", [(int) $it['client_id']]);
    if (!$client) return 0;
    return automation_handle_crm_event($client, (int) $order['contact_id'], $kind,
        ['fields' => integration_order_values($it, $order), 'total' => (float) ($order['total'] ?? 0), 'integration_id' => (int) $it['id']]);
}

/* ───────────────────────── worker ───────────────────────── */

/** The worker's pass over every connected app. Returns a summary for the run log. */
function integrations_tick(): array
{
    if (!integrations_ready()) return [];
    $out = ['jobs' => integration_jobs_tick()];
    foreach (db_all("SELECT * FROM integrations WHERE status IN ('active','error') ORDER BY id") as $row) {
        $it = integration_load($row);
        try {
            if ($it['provider'] === 'shopify') $out['shopify'] = ($out['shopify'] ?? 0) + shopify_tick($it);
            else $out['zoho'] = ($out['zoho'] ?? 0) + zoho_tick($it);
        } catch (Throwable $e) { integration_error($it, $e->getMessage()); }
    }
    return $out;
}

/** The page an OAuth callback ends on. $message is HTML (escape what goes in). */
function integration_done_page(string $title, string $message, bool $ok, string $back = 'client/apps.php'): void
{
    http_response_code($ok ? 200 : 400);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . '</title><link rel="stylesheet" href="assets/dashboard.css"></head><body style="padding:40px">'
       . '<div class="card" style="max-width:560px;margin:0 auto"><h2>' . e($title) . '</h2>'
       . '<div class="alert ' . ($ok ? 'success' : 'error') . '" style="font-size:13px">' . $message . '</div>'
       . '<a class="btn btn-primary" href="' . e($back) . '">Back to Connected apps</a></div></body></html>';
    exit;
}

/** The public address of one of our endpoints (for webhooks and OAuth redirects). */
function integration_url(string $path): string
{
    return rtrim(app_base_url(), '/') . '/' . ltrim($path, '/');
}

require_once __DIR__ . '/shopify.php';
require_once __DIR__ . '/zoho.php';
