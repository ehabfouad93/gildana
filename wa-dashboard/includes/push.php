<?php
declare(strict_types=1);

/**
 * Web Push (VAPID) — notifies a client's devices when a customer replies.
 *
 * PAYLOAD-LESS by design. Encrypting a push payload needs ECDH + AES128GCM, which is
 * painful to hand-roll without Composer (this host has none). A push with no body needs
 * only a VAPID JWT signed ES256, which core openssl does. The service worker reacts by
 * fetching the unread count itself (see sw.js / push_status.php).
 *
 * Depends on: db, crypto (encrypt_secret), helpers.
 */

require_once __DIR__ . '/crypto.php';

const PUSH_TTL          = 3600;
const PUSH_JWT_LIFETIME = 43200;   // 12h; spec allows max 24h

/* ── tiny key/value store ── */
function setting_get(string $k, ?string $default = null): ?string
{
    try {
        $v = db_val("SELECT v FROM app_settings WHERE k=?", [$k]);
        return $v === null ? $default : (string) $v;
    } catch (Throwable $e) {
        return $default;   // migration 010 not applied yet
    }
}

function setting_set(string $k, string $v): void
{
    db_run("INSERT INTO app_settings (k,v,updated_at) VALUES (?,?,NOW())
            ON DUPLICATE KEY UPDATE v=VALUES(v), updated_at=NOW()", [$k, $v]);
}

/* ── base64url ── */
function b64u_encode(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64u_decode(string $s): string
{
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return (string) base64_decode($s);
}

/**
 * The VAPID keypair, generated on first use.
 * Returns ['public' => base64url uncompressed EC point, 'private_pem' => string] or null
 * when the server can't do EC crypto.
 */
function push_vapid_keys(bool $create = true): ?array
{
    $pub = setting_get('vapid_public');
    $pem = setting_get('vapid_private_enc');
    if ($pub && $pem) {
        $priv = decrypt_secret($pem);
        if ($priv !== '') return ['public' => $pub, 'private_pem' => $priv];
    }
    if (!$create) return null;

    $res = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$res) { error_log('push: openssl EC keygen failed'); return null; }
    $privPem = '';
    if (!openssl_pkey_export($res, $privPem)) { error_log('push: key export failed'); return null; }
    $d = openssl_pkey_get_details($res);
    if (empty($d['ec']['x']) || empty($d['ec']['y'])) { error_log('push: no EC point in key details'); return null; }

    // applicationServerKey is the uncompressed point: 0x04 || X(32) || Y(32).
    $point = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT)
                    . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $pub = b64u_encode($point);
    setting_set('vapid_public', $pub);
    setting_set('vapid_private_enc', encrypt_secret($privPem));
    return ['public' => $pub, 'private_pem' => $privPem];
}

function push_configured(): bool
{
    return push_vapid_keys(false) !== null;
}

/**
 * Convert an OpenSSL ECDSA signature (DER: SEQUENCE { INTEGER r, INTEGER s }) to the raw
 * fixed-width R||S that JWS ES256 requires (64 bytes).
 *
 * This is the easy thing to get wrong: DER INTEGERs are signed, so a value whose top bit
 * is set carries an extra leading 0x00 that must be stripped, while a short value must be
 * left-padded back to 32 bytes. A naive substr() produces a signature every push service
 * rejects. Returns '' if the DER can't be parsed.
 */
function der_to_raw_signature(string $der): string
{
    $off = 0;
    if (strlen($der) < 8 || ord($der[$off++]) !== 0x30) return '';
    $len = ord($der[$off++]);
    if ($len & 0x80) {                       // long form length
        $n = $len & 0x7f;
        if ($n < 1 || $n > 2 || strlen($der) < $off + $n) return '';
        $off += $n;
    }
    $take = function (string $der, int &$off): string {
        if (!isset($der[$off]) || ord($der[$off++]) !== 0x02) return '';
        $l = ord($der[$off++]);
        if ($l < 1 || strlen($der) < $off + $l) return '';
        $v = substr($der, $off, $l);
        $off += $l;
        $v = ltrim($v, "\x00");                        // drop DER sign padding
        if (strlen($v) > 32) return '';
        return str_pad($v, 32, "\0", STR_PAD_LEFT);    // left-pad back to fixed width
    };
    $r = $take($der, $off);
    $s = $take($der, $off);
    if ($r === '' || $s === '') return '';
    return $r . $s;
}

/** Build a VAPID JWT for one push-service origin. */
function push_vapid_jwt(string $audience, array $keys): string
{
    $sub = trim((string) config('push_subject', ''));
    if ($sub === '') {
        $host = (string) (parse_url((string) config('base_url', ''), PHP_URL_HOST) ?: 'example.com');
        $sub  = 'mailto:admin@' . $host;
    }
    $header  = b64u_encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = b64u_encode((string) json_encode([
        'aud' => $audience,
        'exp' => time() + PUSH_JWT_LIFETIME,
        'sub' => $sub,
    ], JSON_UNESCAPED_SLASHES));

    $key = openssl_pkey_get_private($keys['private_pem']);
    if (!$key) return '';
    $der = '';
    if (!openssl_sign($header . '.' . $payload, $der, $key, OPENSSL_ALGO_SHA256)) return '';
    $raw = der_to_raw_signature($der);
    if ($raw === '') return '';
    return $header . '.' . $payload . '.' . b64u_encode($raw);
}

/**
 * Origin of a push endpoint — the JWT `aud` (RFC 8292).
 * The port belongs in the origin whenever it is not the scheme's default; omitting it
 * makes the token invalid for any service not on 443.
 */
function push_audience(string $endpoint): string
{
    $p = parse_url($endpoint);
    if (empty($p['scheme']) || empty($p['host'])) return '';
    $origin = $p['scheme'] . '://' . $p['host'];
    if (!empty($p['port'])) {
        $port = (int) $p['port'];
        $isDefault = ($p['scheme'] === 'https' && $port === 443) || ($p['scheme'] === 'http' && $port === 80);
        if (!$isDefault) $origin .= ':' . $port;
    }
    return $origin;
}

/**
 * Deliver one payload-less push.
 * Returns ['ok'=>bool, 'code'=>int, 'gone'=>bool] — `gone` means the subscription is dead
 * and should be deleted.
 */
function push_send(array $sub, array $keys): array
{
    $endpoint = (string) $sub['endpoint'];
    $aud = push_audience($endpoint);
    if ($aud === '') return ['ok' => false, 'code' => 0, 'gone' => true];
    $jwt = push_vapid_jwt($aud, $keys);
    if ($jwt === '') return ['ok' => false, 'code' => 0, 'gone' => false];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '',                       // no payload
        CURLOPT_HTTPHEADER     => [
            'TTL: ' . PUSH_TTL,
            'Content-Length: 0',
            'Authorization: vapid t=' . $jwt . ', k=' . $keys['public'],
        ],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'ok'   => $code >= 200 && $code < 300,
        'code' => $code,
        // 404/410 are the spec's "this subscription is dead" responses.
        'gone' => in_array($code, [404, 410], true),
    ];
}

/** Mark that a client has something new to be notified about (collapses by design). */
function push_queue_client(int $clientId): void
{
    try {
        db_run("INSERT INTO push_outbox (client_id, queued_at) VALUES (?, NOW())
                ON DUPLICATE KEY UPDATE queued_at = queued_at", [$clientId]);
    } catch (Throwable $e) {
        error_log('push_queue_client skipped: ' . $e->getMessage());   // migration not applied
    }
}

/** Push to every device subscribed for a client. Returns messages accepted. */
function push_notify_client(int $clientId): int
{
    $keys = push_vapid_keys(false);
    if (!$keys) return 0;
    $subs = db_all("SELECT * FROM push_subscriptions WHERE client_id=?", [$clientId]);
    $sent = 0;
    foreach ($subs as $sub) {
        $r = push_send($sub, $keys);
        if (!empty($r['ok'])) {
            db_run("UPDATE push_subscriptions SET last_ok_at=NOW(), fail_count=0 WHERE id=?", [(int) $sub['id']]);
            $sent++;
        } elseif (!empty($r['gone'])) {
            db_run("DELETE FROM push_subscriptions WHERE id=?", [(int) $sub['id']]);
        } else {
            db_run("UPDATE push_subscriptions SET fail_count=fail_count+1 WHERE id=?", [(int) $sub['id']]);
        }
    }
    return $sent;
}

/**
 * Drain the outbox — called by the background worker. One push per client no matter how
 * many messages arrived. Returns messages accepted.
 */
function push_dispatch(int $limit = 200): int
{
    if (!push_configured()) return 0;
    try {
        $rows = db_all("SELECT client_id FROM push_outbox ORDER BY queued_at ASC LIMIT " . (int) $limit);
    } catch (Throwable $e) {
        return 0;
    }
    $sent = 0;
    foreach ($rows as $r) {
        $cid = (int) $r['client_id'];
        // Clear first: a message arriving mid-send re-queues the client for the next run
        // rather than being swallowed by this one.
        db_run("DELETE FROM push_outbox WHERE client_id=?", [$cid]);
        $sent += push_notify_client($cid);
    }
    return $sent;
}
