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
        // Where the GATEWAY should call US back. Usually the public site URL, but when both
        // run as containers the internal service name (http://revenect) is far more reliable
        // — see pw_hook_url().
        'hook'    => rtrim(trim((string) (setting_get('pw_hook_base', '') ?? '')), '/'),
        // Endpoint templates — {instance} is substituted per client. Overridable in Admin.
        'paths'   => $paths + [
            'create'      => '/instance/create',
            'qr'          => '/instance/connect/{instance}',
            'pair'        => '/instance/connect/{instance}',
            'status'      => '/instance/connectionState/{instance}',
            'logout'      => '/instance/logout/{instance}',
            'webhook_set' => '/webhook/set/{instance}',
            'send_text'   => '/message/sendText/{instance}',
            'send_img'    => '/message/sendMedia/{instance}',
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

/**
 * The inbound callback URL handed to the gateway.
 *
 * Defaults to the public site URL, which is correct when the gateway is a separate host.
 * But when the gateway runs as a container beside this app, that public URL makes its
 * callback leave the server and come back in through the reverse proxy — a hairpin that
 * many Docker/NAT setups drop silently, so inbound messages vanish with the webhook
 * looking perfectly configured on both sides. Setting pw_hook_base to the app's internal
 * service name (e.g. http://revenect) keeps the hop on the container network: no DNS, no
 * TLS, no round trip through the internet.
 *
 * The secret in the query string is what authenticates the call either way, so an internal
 * URL is no less safe — and it is never reachable from outside at all.
 */
function pw_hook_url(string $secret): string
{
    $c = pw_cfg();
    $base = $c['hook'] !== '' ? $c['hook'] : rtrim(app_base_url(), '/');
    return $base . '/webhook_personal.php?k=' . $secret;
}

/**
 * The webhook object shape the gateway needs, shared by instance creation and the
 * standalone webhook_set call below. `enabled` is required — several Evolution builds
 * default it to false even when a url and events are supplied, which silently produces
 * an instance that sends and receives QR/status fine but never posts an inbound message.
 */
function pw_webhook_payload(string $hook): array
{
    return [
        'enabled'  => true,
        'url'      => $hook,
        'byEvents' => false,
        'base64'   => false,
        'events'   => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'],
    ];
}

/**
 * (Re-)register the inbound webhook on the gateway for an ALREADY-EXISTING instance, using
 * the dedicated set-webhook endpoint rather than instance/create.
 *
 * This exists because instance/create is a create-or-fail call: for an instance the gateway
 * already knows about (any client reconnecting, or one whose instance predates a webhook
 * events/URL change) it returns 403/409 "already exists", and the webhook payload inside
 * that same request body is discarded along with it — the instance keeps working for QR,
 * status and sending, but silently stops delivering inbound messages, which is exactly the
 * "received messages don't sync" symptom with no error anywhere to point at.
 */
function pw_set_webhook(array $client): array
{
    $secret = trim((string) ($client['personal_hook_secret'] ?? ''));
    if ($secret === '') return ['ok' => false, 'error' => 'No webhook secret yet — connect first.'];
    $hook = pw_hook_url($secret);

    $res = pw_request('POST', pw_path('webhook_set', pw_instance($client)), [
        'webhook' => pw_webhook_payload($hook),
    ]);
    return ['ok' => $res['error'] === '', 'error' => $res['error']];
}

/** Create this client's gateway instance and persist its name + webhook secret. */
function pw_instance_create(array $client): array
{
    $inst = pw_instance($client);
    $secret = trim((string) ($client['personal_hook_secret'] ?? ''));
    if ($secret === '') $secret = bin2hex(random_bytes(16));

    $hook = pw_hook_url($secret);

    // Evolution v2 payload shape:
    //   - `integration` is REQUIRED (v1 had no such field) — omitting it returns 400.
    //   - the webhook moved from flat keys (webhook / webhook_by_events / events) to a
    //     nested object in v2.2+.
    $res = pw_request('POST', pw_path('create', $inst), [
        'instanceName' => $inst,
        'qrcode'       => true,
        'integration'  => 'WHATSAPP-BAILEYS',
        'webhook'      => pw_webhook_payload($hook),
    ]);
    // An instance that already exists is not an error — we just reuse it.
    $exists = $res['http'] === 403 || $res['http'] === 409
           || stripos($res['error'], 'already') !== false;
    if ($res['error'] !== '' && !$exists) {
        return ['ok' => false, 'error' => $res['error']];
    }

    db_run("UPDATE clients SET personal_instance=?, personal_hook_secret=? WHERE id=?",
        [$inst, $secret, (int) $client['id']]);

    // The create call's own webhook block is only honoured for a BRAND NEW instance — for
    // an existing one (the $exists branch above) the gateway ignored it entirely. Registering
    // it again here, unconditionally, via the dedicated endpoint means every connect (first
    // time or a reconnect) leaves the webhook correctly set, regardless of which branch fired.
    pw_set_webhook(['id' => (int) $client['id'], 'personal_instance' => $inst, 'personal_hook_secret' => $secret]);

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

/**
 * What to put in the gateway's `number` field for this recipient.
 *
 * Normally the digits. But a contact WhatsApp addresses by LID has no usable number — the
 * gateway answers {"exists":false} for it — so when we know the exact address they last
 * messaged from, send to that instead. Every module passes a phone string around, so the
 * lookup happens here rather than threading the contact row through channel.php and back.
 */
function pw_target(array $client, string $to): string
{
    $digits = preg_replace('/\D+/', '', $to) ?? '';
    if ($digits === '') return $to;
    try {
        $jid = (string) db_val("SELECT wa_jid FROM contacts WHERE client_id=? AND phone_e164=? AND wa_jid IS NOT NULL",
            [(int) $client['id'], $digits]);
    } catch (Throwable $e) {
        return $digits;                      // migration 013 not applied yet
    }
    if (stripos($jid, '@lid') === false) return $digits;

    // A LID is on file. That only matters when the number we hold IS that LID's digits —
    // i.e. we never learned the real one. If we did learn it, the number is the better
    // address: it is what the person actually dials, and it survives WhatsApp reassigning
    // the LID. Overriding it with the LID here is what made the first fix send to the wrong
    // address even after the number was known.
    $lidDigits = preg_replace('/\D+/', '', explode('@', $jid)[0]) ?? '';
    return ($digits === $lidDigits) ? $jid : $digits;
}

function pw_send_text(array $client, string $to, string $body): array
{
    if (!pw_configured()) {
        return ['ok' => false, 'wamid' => null, 'error_code' => 'no_gateway',
                'error_title' => 'Personal-number gateway is not configured.', 'http' => 0];
    }
    $res = pw_request('POST', pw_path('send_text', pw_instance($client)), [
        'number' => pw_target($client, $to),
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

    // Prefer sending the bytes over sending a URL.
    //
    // Handing the gateway a link makes it fetch the image back over the public internet,
    // so anything between here and there breaks the send: a missing file 404s, and the
    // failure surfaces as an opaque "AxiosError 404" from inside the gateway rather than
    // as something the operator can act on. When the file is ours, read it directly.
    $media = $link;
    $path  = (string) parse_url($link, PHP_URL_PATH);
    $isOurs = strpos($path, '/uploads/') !== false;

    if ($isOurs) {
        // wa_local_path_for_url() returns null both for "not ours" and "ours but missing",
        // so the two must be told apart here — otherwise a missing file is quietly sent as
        // a URL and 404s inside the gateway, which is the failure this replaces.
        $local = function_exists('wa_local_path_for_url') ? wa_local_path_for_url($link) : null;
        if ($local === null || !is_readable($local)) {
            return ['ok' => false, 'wamid' => null, 'error_code' => 'media_missing',
                    'error_title' => 'The image is missing on the server (' . basename($path) . '). Re-upload it in the campaign.',
                    'http' => 0];
        }
        $bytes = @file_get_contents($local);
        if ($bytes === false || $bytes === '') {
            return ['ok' => false, 'wamid' => null, 'error_code' => 'media_unreadable',
                    'error_title' => 'The image could not be read on the server (' . basename($path) . ').',
                    'http' => 0];
        }
        $media = base64_encode($bytes);
    }

    $res = pw_request('POST', pw_path('send_img', pw_instance($client)), [
        'number'    => pw_target($client, $to),
        'mediatype' => 'image',
        'media'     => $media,
        'caption'   => $caption,
        'fileName'  => basename((string) parse_url($link, PHP_URL_PATH)) ?: 'image.jpg',
    ], 60);
    return pw_result($res);
}

/* ── inbound ── */

/**
 * A real phone number out of a JID, or '' when the JID isn't one.
 *
 * WhatsApp addresses many chats by LID now — "<id>@lid", an opaque per-user identifier that
 * deliberately hides the phone number. Its digits look like a number and are even a
 * plausible length, so the only reliable tell is the @lid suffix. Treating those digits as
 * a phone is what makes every reply come back {"exists":false}.
 */
function pw_jid_number(string $jid): string
{
    $jid = trim($jid);
    if ($jid === '') return '';
    if (stripos($jid, '@lid') !== false) return '';      // opaque id, never dialable
    if (stripos($jid, '@g.us') !== false) return '';     // group
    if (stripos($jid, '@broadcast') !== false) return '';

    $local  = explode(':', explode('@', $jid)[0])[0];    // strip any :device suffix
    $digits = preg_replace('/\D+/', '', $local) ?? '';
    // E.164 is 8-15 digits. Outside that it's an identifier of some kind, not a number.
    return (strlen($digits) >= 8 && strlen($digits) <= 15) ? $digits : '';
}

/**
 * Normalise a gateway webhook payload to the shape webhook_personal.php needs:
 *   ['from'=>digits, 'jid'=>string, 'is_lid'=>bool, 'text'=>string, 'type'=>string,
 *    'wamid'=>string, 'from_me'=>bool, 'name'=>string]
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

    $isLid = stripos($remote, '@lid') !== false;

    // Prefer the phone number the address itself carries; if it's a LID, take the mapping
    // WhatsApp sends alongside — remoteJidAlt for a direct chat, the participant/sender
    // variants elsewhere. Field naming differs between Baileys and Evolution builds, so try
    // each rather than depending on one.
    $from = pw_jid_number($remote);
    if ($from === '') {
        foreach ([$key['remoteJidAlt'] ?? '', $key['senderPn'] ?? '', $key['participantPn'] ?? '',
                  $key['participantAlt'] ?? '', $key['senderJid'] ?? '',
                  $d['senderPn'] ?? '', $d['participantPn'] ?? '', $d['sender'] ?? ''] as $alt) {
            $from = pw_jid_number((string) $alt);
            if ($from !== '') break;
        }
    }
    // Still nothing: WhatsApp sent no mapping for this LID and there is no way to derive one.
    // Keep the message anyway — dropping it would lose the conversation silently — keyed by
    // the LID's digits. Replies go to `jid`, not to these digits, so they still deliver.
    if ($from === '') $from = preg_replace('/\D+/', '', explode('@', $remote)[0]) ?? '';
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
        // The exact address this arrived from. Replies target this rather than a number
        // rebuilt from it, which is the only thing that works for a LID-addressed chat.
        'jid'     => $remote,
        'is_lid'  => $isLid,
        'text'    => trim($text),
        'type'    => $type,
        'wamid'   => (string) ($key['id'] ?? $d['id'] ?? ''),
        'from_me' => !empty($key['fromMe']),
        'name'    => (string) ($d['pushName'] ?? ''),
    ];
}
