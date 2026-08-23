<?php
declare(strict_types=1);

/**
 * Personal-number gateway adapter.
 *
 * A personal WhatsApp number cannot be driven by Meta's API, so it is driven by a gateway
 * that holds a WhatsApp Web session (Baileys-based, e.g. Evolution API). This file is the
 * only place that knows that gateway's HTTP shape.
 *
 * Configuration is PLATFORM-LEVEL, set once by the operator in Admin → Settings: base URL,
 * API key, and optional endpoint/field overrides so a different gateway vendor can be used
 * without code changes. A client never sees a URL or a token — they only scan a QR.
 *
 * The base URL is the gateway's address as seen FROM THIS APP. When both run as Docker
 * containers that is the service name on the shared network (http://evolution-api:8080) —
 * NOT 127.0.0.1, which inside a container means the container itself. The gateway holds
 * clients' live WhatsApp sessions and must never be published to the internet.
 *
 * Every pw_send_* returns the same array shape as wa_send_* so callers can't tell them apart.
 */

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/helpers.php';  // app_base_url()
require_once __DIR__ . '/push.php';     // setting_get() / setting_set()

/* ── platform configuration ── */

function pw_cfg(): array
{
    $base = trim((string) (setting_get('pw_base_url', '') ?? ''));
    if ($base === '') $base = trim((string) config('personal_base_url', ''));
    $key = decrypt_secret((string) (setting_get('pw_api_key', '') ?? ''));
    if ($key === '') $key = trim((string) config('personal_api_key', ''));

    $paths = json_decode((string) (setting_get('pw_paths', '') ?? ''), true);
    if (!is_array($paths)) $paths = [];

    return [
        'base'    => rtrim($base, '/'),
        'key'     => $key,
        // Endpoint templates — {instance} is substituted per client. Overridable in Admin.
        'paths'   => $paths + [
            'create'    => '/instance/create',
            'qr'        => '/instance/connect/{instance}',
            'pair'      => '/instance/connect/{instance}',
            'status'    => '/instance/connectionState/{instance}',
            'logout'    => '/instance/logout/{instance}',
            'send_text' => '/message/sendText/{instance}',
            'send_img'  => '/message/sendMedia/{instance}',
        ],
        'header'  => (string) (setting_get('pw_auth_header', 'apikey') ?? 'apikey'),
    ];
}

function pw_configured(): bool
{
    $c = pw_cfg();
    return $c['base'] !== '' && $c['key'] !== '';
}

/** Stable per-client instance name; created on first connect. */
function pw_instance(array $client): string
{
    $inst = trim((string) ($client['personal_instance'] ?? ''));
    if ($inst !== '') return $inst;
    return 'client' . (int) $client['id'];
}

/* ── HTTP ── */

/**
 * Call the gateway. Returns ['http'=>int,'json'=>?array,'error'=>string].
 * Short timeouts: this runs inside page loads (QR polling) and the send worker.
 */
function pw_request(string $method, string $path, ?array $body = null, int $timeout = 20): array
{
    $c = pw_cfg();
    if ($c['base'] === '') return ['http' => 0, 'json' => null, 'error' => 'Gateway is not configured'];

    $ch = curl_init($c['base'] . $path);
    $headers = ['Content-Type: application/json'];
    // Most gateways use a custom key header; allow Bearer for those that don't.
    $headers[] = strtolower($c['header']) === 'bearer'
        ? 'Authorization: Bearer ' . $c['key']
        : $c['header'] . ': ' . $c['key'];

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);

    $raw  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['http' => 0, 'json' => null, 'error' => $cerr ?: 'Gateway unreachable'];
    $json = json_decode((string) $raw, true);
    $err  = '';
    if ($http < 200 || $http >= 300) {
        // The gateway nests the useful text under response.message (often an array) while
        // `error` is just "Bad Request" — surface the detail or there is nothing to act on.
        $detail = $json['response']['message'] ?? $json['message'] ?? $json['error'] ?? null;
        if (is_array($detail)) $detail = implode(' · ', array_map(fn($m) => is_scalar($m) ? (string) $m : json_encode($m), $detail));
        $err = trim((string) ($detail ?? '')) !== '' ? (string) $detail : ('HTTP ' . $http);
        error_log('pw_request ' . $method . ' ' . $path . ' → ' . $http . ' ' . substr((string) $raw, 0, 400));
    }
    return ['http' => $http, 'json' => is_array($json) ? $json : null, 'error' => $err];
}

function pw_path(string $key, string $instance): string
{
    $c = pw_cfg();
    return str_replace('{instance}', rawurlencode($instance), (string) ($c['paths'][$key] ?? ''));
}

/* ── instance lifecycle ── */

/** Create this client's gateway instance and persist its name + webhook secret. */
function pw_instance_create(array $client): array
{
    $inst = pw_instance($client);
    $secret = trim((string) ($client['personal_hook_secret'] ?? ''));
    if ($secret === '') $secret = bin2hex(random_bytes(16));

    // app_base_url() prefers config('base_url') and falls back to the current request.
    $hook = rtrim(app_base_url(), '/') . '/webhook_personal.php?k=' . $secret;

    // Evolution v2 payload shape:
    //   - `integration` is REQUIRED (v1 had no such field) — omitting it returns 400.
    //   - the webhook moved from flat keys (webhook / webhook_by_events / events) to a
    //     nested object in v2.2+.
    $res = pw_request('POST', pw_path('create', $inst), [
        'instanceName' => $inst,
        'qrcode'       => true,
        'integration'  => 'WHATSAPP-BAILEYS',
        'webhook'      => [
            'url'      => $hook,
            'byEvents' => false,
            'base64'   => false,
            'events'   => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'],
        ],
    ]);
    // An instance that already exists is not an error — we just reuse it.
    $exists = $res['http'] === 403 || $res['http'] === 409
           || stripos($res['error'], 'already') !== false;
    if ($res['error'] !== '' && !$exists) {
        return ['ok' => false, 'error' => $res['error']];
    }

    db_run("UPDATE clients SET personal_instance=?, personal_hook_secret=? WHERE id=?",
        [$inst, $secret, (int) $client['id']]);
    return ['ok' => true, 'instance' => $inst, 'secret' => $secret, 'error' => ''];
}

/**
 * Fetch a fresh QR for linking. Returns ['ok','qr'(data URI or raw payload),'code','error'].
 * QRs rotate every ~20-60s, so the UI re-fetches rather than showing a stale one.
 */
function pw_qr(array $client): array
{
    $inst = pw_instance($client);
    $res  = pw_request('GET', pw_path('qr', $inst));
    if ($res['error'] !== '') return ['ok' => false, 'qr' => '', 'code' => '', 'error' => $res['error']];

    $j = $res['json'] ?? [];
    // Gateways differ: base64 image, raw pairing string, or nested under `qrcode`.
    $qr = (string) ($j['base64'] ?? $j['qrcode']['base64'] ?? $j['qr'] ?? '');
    $code = (string) ($j['code'] ?? $j['qrcode']['code'] ?? $j['pairingCode'] ?? '');
    if ($qr !== '' && strpos($qr, 'data:') !== 0 && preg_match('~^[A-Za-z0-9+/=]+$~', $qr)) {
        $qr = 'data:image/png;base64,' . $qr;
    }
    return ['ok' => ($qr !== '' || $code !== ''), 'qr' => $qr, 'code' => $code, 'error' => ''];
}

/**
 * Ask the gateway for an 8-character pairing code — WhatsApp's alternative to scanning
 * (Linked devices → Link with phone number). There is no SMS linking method.
 */
function pw_pair_code(array $client, string $msisdn): array
{
    $inst = pw_instance($client);
    $msisdn = preg_replace('/\D+/', '', $msisdn) ?? '';
    if ($msisdn === '') return ['ok' => false, 'code' => '', 'error' => 'Enter the phone number first.'];

    $res = pw_request('GET', pw_path('pair', $inst) . '?number=' . rawurlencode($msisdn));
    if ($res['error'] !== '') return ['ok' => false, 'code' => '', 'error' => $res['error']];
    $j = $res['json'] ?? [];
    $code = (string) ($j['pairingCode'] ?? $j['code'] ?? '');
    return ['ok' => $code !== '', 'code' => $code, 'error' => $code === '' ? 'Gateway did not return a pairing code.' : ''];
}

/**
 * Current session state. Returns ['state'=>'connected|qr_pending|disconnected','msisdn'=>string,'error'=>string]
 * and syncs the clients row so the worker and UI agree.
 */
function pw_status(array $client, bool $persist = true): array
{
    $inst = pw_instance($client);
    $res  = pw_request('GET', pw_path('status', $inst), null, 10);
    if ($res['error'] !== '') {
        return ['state' => (string) ($client['personal_status'] ?? 'disconnected'),
                'msisdn' => (string) ($client['personal_msisdn'] ?? ''), 'error' => $res['error']];
    }
    $j = $res['json'] ?? [];
    $raw = strtolower((string) ($j['instance']['state'] ?? $j['state'] ?? $j['status'] ?? ''));
    $state = in_array($raw, ['open', 'connected', 'online'], true) ? 'connected'
           : (in_array($raw, ['connecting', 'qr', 'qrcode', 'pairing'], true) ? 'qr_pending' : 'disconnected');
    $msisdn = preg_replace('/\D+/', '', (string) ($j['instance']['owner'] ?? $j['owner'] ?? $j['number'] ?? '')) ?? '';

    if ($persist) {
        $connectedAt = $state === 'connected' ? 'NOW()' : 'personal_connected_at';
        db_run("UPDATE clients SET personal_status=?, personal_msisdn=COALESCE(NULLIF(?,''), personal_msisdn),
                       personal_connected_at={$connectedAt} WHERE id=?",
            [$state, $msisdn, (int) $client['id']]);
    }
    return ['state' => $state, 'msisdn' => $msisdn ?: (string) ($client['personal_msisdn'] ?? ''), 'error' => ''];
}

/** Unlink the number (client pressed Disconnect). */
function pw_logout(array $client): array
{
    $res = pw_request('DELETE', pw_path('logout', pw_instance($client)));
    db_run("UPDATE clients SET personal_status='disconnected', personal_connected_at=NULL WHERE id=?",
        [(int) $client['id']]);
    return ['ok' => $res['error'] === '', 'error' => $res['error']];
}

/* ── sending ── */

/** Normalise a gateway send response into the wa_send_* contract. */
function pw_result(array $res): array
{
    if ($res['error'] !== '') {
        return ['ok' => false, 'wamid' => null,
                'error_code' => (string) ($res['http'] ?: 'gateway'),
                'error_title' => $res['error'], 'http' => $res['http']];
    }
    $j = $res['json'] ?? [];
    $id = (string) ($j['key']['id'] ?? $j['messageId'] ?? $j['id'] ?? '');
    return ['ok' => true, 'wamid' => $id !== '' ? $id : null,
            'error_code' => null, 'error_title' => null, 'http' => $res['http']];
}

function pw_send_text(array $client, string $to, string $body): array
{
    if (!pw_configured()) {
        return ['ok' => false, 'wamid' => null, 'error_code' => 'no_gateway',
                'error_title' => 'Personal-number gateway is not configured.', 'http' => 0];
    }
    $res = pw_request('POST', pw_path('send_text', pw_instance($client)), [
        'number' => preg_replace('/\D+/', '', $to),
        'text'   => $body,
        // keep the gateway's own pacing helpers on where supported
        'options' => ['delay' => 0, 'presence' => 'composing'],
    ], 30);
    return pw_result($res);
}

function pw_send_image(array $client, string $to, string $link, string $caption = ''): array
{
    if (!pw_configured()) {
        return ['ok' => false, 'wamid' => null, 'error_code' => 'no_gateway',
                'error_title' => 'Personal-number gateway is not configured.', 'http' => 0];
    }
    $res = pw_request('POST', pw_path('send_img', pw_instance($client)), [
        'number'    => preg_replace('/\D+/', '', $to),
        'mediatype' => 'image',
        'media'     => $link,
        'caption'   => $caption,
    ], 45);
    return pw_result($res);
}

/* ── inbound ── */

/**
 * Normalise a gateway webhook payload to the shape webhook_personal.php needs:
 *   ['from'=>digits, 'text'=>string, 'type'=>string, 'wamid'=>string, 'from_me'=>bool, 'name'=>string]
 * Returns null when the payload is not an inbound message (status/connection events).
 */
function pw_parse_inbound(array $payload): ?array
{
    $ev = strtolower((string) ($payload['event'] ?? ''));
    if ($ev !== '' && strpos($ev, 'messages') === false) return null;

    $d = $payload['data'] ?? $payload;
    if (isset($d[0]) && is_array($d[0])) $d = $d[0];   // some gateways batch

    $key = (array) ($d['key'] ?? []);
    $remote = (string) ($key['remoteJid'] ?? $d['from'] ?? '');
    if ($remote === '' || strpos($remote, '@g.us') !== false) return null;   // ignore groups

    $from = preg_replace('/\D+/', '', explode('@', $remote)[0]) ?? '';
    if ($from === '') return null;

    $m = (array) ($d['message'] ?? []);
    $text = (string) ($m['conversation']
        ?? $m['extendedTextMessage']['text']
        ?? $m['imageMessage']['caption']
        ?? $m['videoMessage']['caption']
        ?? $d['text'] ?? $d['body'] ?? '');

    $type = 'text';
    if (isset($m['imageMessage'])) $type = 'image';
    elseif (isset($m['audioMessage'])) $type = 'audio';
    elseif (isset($m['documentMessage'])) $type = 'document';
    elseif (isset($m['videoMessage'])) $type = 'video';

    // Button/list replies arrive as their own message types; treat the selected title as text.
    if (isset($m['buttonsResponseMessage'])) { $type = 'button'; $text = (string) ($m['buttonsResponseMessage']['selectedDisplayText'] ?? $text); }
    if (isset($m['listResponseMessage']))    { $type = 'button'; $text = (string) ($m['listResponseMessage']['title'] ?? $text); }

    return [
        'from'    => $from,
        'text'    => trim($text),
        'type'    => $type,
        'wamid'   => (string) ($key['id'] ?? $d['id'] ?? ''),
        'from_me' => !empty($key['fromMe']),
        'name'    => (string) ($d['pushName'] ?? ''),
    ];
}
