<?php
declare(strict_types=1);

/**
 * Thin client for the official WhatsApp Cloud API (Meta Graph API).
 * Credentials come from a `clients` row (token stored encrypted).
 */

function wa_graph_base(): string
{
    // `graph_host` is only overridden to point at a mock Graph API when testing;
    // it defaults to the real endpoint, so live behaviour is unchanged.
    $host = rtrim((string) config('graph_host', 'https://graph.facebook.com'), '/');
    return $host . '/' . (string) config('graph_version', 'v21.0');
}

/**
 * The access token to send this client's messages with.
 *
 * A client on their own WhatsApp Business Account uses their own token, exactly as before.
 * A client onboarded under OUR account has no token of their own and sends on the platform's
 * — that is the whole difference between the two modes at the send layer.
 */
function wa_token(array $client): string
{
    $own = decrypt_secret((string) ($client['access_token_enc'] ?? ''));
    if ($own !== '') return $own;
    if (($client['waba_mode'] ?? 'byo') === 'platform') return wa_platform_token();
    return '';
}

/** The platform's own WhatsApp token, for clients sending under our account. */
function wa_platform_token(): string
{
    static $tok = null;
    if ($tok === null) {
        $enc = (string) (db_val("SELECT v FROM app_settings WHERE k='wa_platform_token'") ?: '');
        $tok = $enc !== '' ? decrypt_secret($enc) : '';
    }
    return $tok;
}

/** The phone number id to send from — the client's, or the platform's in platform mode. */
function wa_phone_id(array $client): string
{
    $own = trim((string) ($client['phone_number_id'] ?? ''));
    if ($own !== '') return $own;
    if (($client['waba_mode'] ?? 'byo') === 'platform') {
        return (string) (db_val("SELECT v FROM app_settings WHERE k='wa_platform_phone_id'") ?: '');
    }
    return '';
}

/**
 * Low-level Graph request. Returns:
 *   ['http'=>int, 'json'=>array|null, 'error'=>string, 'raw'=>string]
 */
function wa_request(string $method, string $url, string $token, ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $token];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);

    $raw  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['http' => 0, 'json' => null, 'error' => $cerr ?: 'Network error', 'raw' => ''];
    }
    $json = json_decode((string) $raw, true);
    $error = '';
    if (is_array($json) && isset($json['error'])) {
        $error = (string) ($json['error']['message'] ?? 'API error');
    }
    return ['http' => $http, 'json' => is_array($json) ? $json : null, 'error' => $error, 'raw' => (string) $raw];
}

/**
 * Send a template message.
 * $components is the WhatsApp `template.components` array (may be []).
 * Returns ['ok'=>bool, 'wamid'=>?string, 'error_code'=>?string, 'error_title'=>?string, 'http'=>int]
 */
function wa_send_template(array $client, string $to, string $templateName, string $lang, array $components = []): array
{
    $token = wa_token($client);
    $pnid  = wa_phone_id($client);
    if ($token === '' || $pnid === '') {
        return ['ok' => false, 'wamid' => null, 'error_code' => 'no_credentials',
                'error_title' => 'Missing access token or phone number ID', 'http' => 0];
    }

    $template = [
        'name'     => $templateName,
        'language' => ['code' => $lang],
    ];
    if (!empty($components)) {
        $template['components'] = array_values($components);
    }

    $body = [
        'messaging_product' => 'whatsapp',
        'to'                => $to,
        'type'              => 'template',
        'template'          => $template,
    ];

    $res  = wa_request('POST', wa_graph_base() . '/' . rawurlencode($pnid) . '/messages', $token, $body);
    $json = $res['json'] ?? [];

    if ($res['http'] >= 200 && $res['http'] < 300 && !empty($json['messages'][0]['id'])) {
        return ['ok' => true, 'wamid' => (string) $json['messages'][0]['id'],
                'error_code' => null, 'error_title' => null, 'http' => $res['http']];
    }

    $err = $json['error'] ?? [];
    return [
        'ok'          => false,
        'wamid'       => null,
        'error_code'  => (string) ($err['code'] ?? $res['http']),
        'error_title' => (string) ($err['message'] ?? $res['error'] ?: 'Send failed'),
        'http'        => $res['http'],
    ];
}

/**
 * Fetch message templates for the client's WABA.
 * Returns ['ok'=>bool, 'templates'=>array, 'error'=>string]
 */
function wa_fetch_templates(array $client): array
{
    $token = wa_token($client);
    $waba  = (string) ($client['waba_id'] ?? '');
    if ($token === '' || $waba === '') {
        return ['ok' => false, 'templates' => [], 'error' => 'Missing access token or WABA ID'];
    }

    $templates = [];
    $url = wa_graph_base() . '/' . rawurlencode($waba)
         . '/message_templates?fields=name,status,category,language,components&limit=100';

    // Follow paging up to a sane cap.
    for ($page = 0; $page < 20 && $url !== ''; $page++) {
        $res  = wa_request('GET', $url, $token);
        if ($res['http'] < 200 || $res['http'] >= 300) {
            return ['ok' => false, 'templates' => [], 'error' => $res['error'] ?: ('HTTP ' . $res['http'])];
        }
        $json = $res['json'] ?? [];
        foreach (($json['data'] ?? []) as $t) {
            $templates[] = $t;
        }
        $url = (string) ($json['paging']['next'] ?? '');
    }

    return ['ok' => true, 'templates' => $templates, 'error' => ''];
}

/**
 * Extract the BODY text and count {{n}} variables from a template's components.
 * Returns ['body'=>string, 'vars'=>int].
 */
function wa_template_body(array $components): array
{
    $body = '';
    foreach ($components as $c) {
        if (strtoupper((string) ($c['type'] ?? '')) === 'BODY') {
            $body = (string) ($c['text'] ?? '');
            break;
        }
    }
    preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m);
    $vars = empty($m[1]) ? 0 : max(array_map('intval', $m[1]));
    return ['body' => $body, 'vars' => $vars];
}

/**
 * Inspect a template's HEADER component.
 * Returns ['format' => 'IMAGE'|'VIDEO'|'DOCUMENT'|'TEXT'|'', 'text_vars' => int].
 * A media header (IMAGE/VIDEO/DOCUMENT) requires a header media parameter at send time.
 */
function wa_template_header(array $components): array
{
    foreach ($components as $c) {
        if (strtoupper((string) ($c['type'] ?? '')) !== 'HEADER') continue;
        $fmt = strtoupper((string) ($c['format'] ?? 'TEXT'));
        $tv  = 0;
        if ($fmt === 'TEXT') { preg_match_all('/\{\{\s*\d+\s*\}\}/', (string) ($c['text'] ?? ''), $mm); $tv = count($mm[0]); }
        return ['format' => $fmt, 'text_vars' => $tv];
    }
    return ['format' => '', 'text_vars' => 0];
}

/** The sample header media URL stored in an approved template (example.header_handle[0]), or ''. */
function wa_template_header_example(array $components): string
{
    foreach ($components as $c) {
        if (strtoupper((string) ($c['type'] ?? '')) !== 'HEADER') continue;
        $h = $c['example']['header_handle'] ?? ($c['example']['header_url'] ?? null);
        if (is_array($h)) $h = $h[0] ?? '';
        return trim((string) $h);
    }
    return '';
}

/**
 * Full parameter spec of a template — everything a send payload must supply.
 * Returns [
 *   'header'    => ['format'=>'IMAGE|VIDEO|DOCUMENT|LOCATION|TEXT|', 'text_vars'=>int],
 *   'body_vars' => int,
 *   'buttons'   => [ ['type'=>'URL|QUICK_REPLY|PHONE_NUMBER|COPY_CODE|FLOW|OTP', 'index'=>int,
 *                     'dynamic'=>bool, 'text'=>string], ... ],   // dynamic = needs a send parameter
 * ]
 */
function wa_template_spec(array $components): array
{
    $spec = ['header' => ['format' => '', 'text_vars' => 0], 'body_vars' => 0, 'buttons' => []];
    foreach ($components as $c) {
        $type = strtoupper((string) ($c['type'] ?? ''));
        if ($type === 'HEADER') {
            $spec['header'] = wa_template_header([$c]);
        } elseif ($type === 'BODY') {
            preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) ($c['text'] ?? ''), $m);
            $spec['body_vars'] = empty($m[1]) ? 0 : max(array_map('intval', $m[1]));
        } elseif ($type === 'BUTTONS') {
            foreach ((array) ($c['buttons'] ?? []) as $idx => $b) {
                $bt  = strtoupper((string) ($b['type'] ?? ''));
                $dyn = ($bt === 'URL' && strpos((string) ($b['url'] ?? ''), '{{') !== false) || $bt === 'COPY_CODE';
                $spec['buttons'][] = ['type' => $bt, 'index' => (int) $idx, 'dynamic' => $dyn, 'text' => (string) ($b['text'] ?? '')];
            }
        }
    }
    return $spec;
}

/* ─────────────────────────────────────────────
   Media handles — upload ONCE, send by id
───────────────────────────────────────────── */

/**
 * Upload a local file to the Cloud API media endpoint and return its media id.
 * Returns ['ok'=>bool, 'id'=>string, 'error'=>string].
 *
 * Why this exists: a template header sent as {"link": url} makes Meta fetch that URL for
 * EVERY recipient. On a bulk send that's thousands of downloads from our own host, which
 * gets throttled and comes back as #131053 / #130472. Uploading once and reusing the id
 * removes the fan-out entirely.
 */
function wa_upload_media(array $client, string $filePath, string $mime = ''): array
{
    $token = wa_token($client);
    $pnid  = wa_phone_id($client);
    if ($token === '' || $pnid === '') {
        return ['ok' => false, 'id' => '', 'error' => 'Missing access token or phone number ID'];
    }
    if (!is_file($filePath) || !is_readable($filePath)) {
        return ['ok' => false, 'id' => '', 'error' => 'File not readable'];
    }
    if ($mime === '') $mime = wa_guess_mime($filePath);

    $ch = curl_init(wa_graph_base() . '/' . rawurlencode($pnid) . '/media');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        // Must be multipart/form-data — do NOT json_encode this body.
        CURLOPT_POSTFIELDS     => [
            'messaging_product' => 'whatsapp',
            'type'              => $mime,
            'file'              => new CURLFile($filePath, $mime, basename($filePath)),
        ],
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 120,   // uploads are slower than sends
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) return ['ok' => false, 'id' => '', 'error' => $cerr ?: 'Network error'];
    $json = json_decode((string) $raw, true);
    if ($http >= 200 && $http < 300 && !empty($json['id'])) {
        return ['ok' => true, 'id' => (string) $json['id'], 'error' => ''];
    }
    $err = $json['error'] ?? [];
    return ['ok' => false, 'id' => '', 'error' => (string) ($err['message'] ?? ('HTTP ' . $http))];
}

/** Best-effort MIME for the file types WhatsApp accepts as header media. */
function wa_guess_mime(string $path): string
{
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
        'mp4' => 'video/mp4', '3gp' => 'video/3gpp', 'pdf' => 'application/pdf',
    ];
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if (isset($map[$ext])) return $map[$ext];
    if (function_exists('mime_content_type')) {
        $m = @mime_content_type($path);
        if (is_string($m) && $m !== '') return $m;
    }
    return 'application/octet-stream';
}

/**
 * Map a header-media URL to a reusable Meta media id, uploading + caching on first use.
 * Returns null when the id can't be obtained — callers MUST fall back to {"link": url}
 * so a media hiccup degrades to the old behaviour instead of failing the send.
 *
 * Cache is keyed on (client, sha256 of the bytes); ids expire on Meta's side, so anything
 * older than 25 days is re-uploaded.
 */
function wa_resolve_media(array $client, string $url): ?string
{
    static $memo = [];   // per-process: the same URL is asked for once per recipient

    $url = trim($url);
    if ($url === '' || !function_exists('db_row')) return null;
    $cid = (int) ($client['id'] ?? 0);
    if ($cid <= 0) return null;

    $memoKey = $cid . '|' . $url;
    if (array_key_exists($memoKey, $memo)) return $memo[$memoKey];
    $memo[$memoKey] = null;   // negative-cache failures too, so we retry at most once per run

    try {
        // Prefer the file on disk (our own /uploads) — avoids a pointless round-trip.
        $local = wa_local_path_for_url($url);
        $bytes = null;
        if ($local !== null) {
            $bytes = @file_get_contents($local);
        }
        if ($bytes === null || $bytes === false) {
            $bytes = wa_http_get($url);
            if ($bytes === null) return null;
        }
        $hash = hash('sha256', $bytes);

        $row = db_row("SELECT media_id, uploaded_at FROM media_cache WHERE client_id=? AND file_hash=?", [$cid, $hash]);
        if ($row && !empty($row['media_id'])
            && strtotime((string) $row['uploaded_at']) > time() - 25 * 86400) {
            return $memo[$memoKey] = (string) $row['media_id'];
        }

        // Need a real file on disk for CURLFile.
        $tmp = $local;
        $isTemp = false;
        if ($tmp === null) {
            $ext = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'bin';
            $tmp = rtrim(sys_get_temp_dir(), '/\\') . '/wa_' . $hash . '.' . $ext;
            if (@file_put_contents($tmp, $bytes) === false) return null;
            $isTemp = true;
        }
        $res = wa_upload_media($client, $tmp, wa_guess_mime($tmp));
        if ($isTemp) @unlink($tmp);
        if (empty($res['ok'])) {
            error_log('wa_resolve_media upload failed: ' . $res['error']);
            return null;
        }

        db_run(
            "INSERT INTO media_cache (client_id,file_hash,file_url,media_id,mime,uploaded_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE media_id=VALUES(media_id), file_url=VALUES(file_url),
                                     mime=VALUES(mime), uploaded_at=NOW()",
            [$cid, $hash, substr($url, 0, 500), $res['id'], wa_guess_mime($tmp)]
        );
        return $memo[$memoKey] = $res['id'];
    } catch (Throwable $e) {
        error_log('wa_resolve_media: ' . $e->getMessage());
        return null;   // always degrade to link
    }
}

/** Resolve a URL served by this same install to a local path, or null if external. */
function wa_local_path_for_url(string $url): ?string
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '') return null;
    $pos = strpos($path, '/uploads/');
    if ($pos === false) return null;
    $candidate = dirname(__DIR__) . substr($path, $pos);   // <app>/uploads/...
    $real = realpath($candidate);
    $root = realpath(dirname(__DIR__) . '/uploads');
    if ($real === false || $root === false) return null;
    // Containment check — never read outside /uploads.
    if (strncmp($real, $root, strlen($root)) !== 0) return null;
    return is_file($real) ? $real : null;
}

/** Simple GET returning the body, or null. */
function wa_http_get(string $url): ?string
{
    /* The URL here comes from a tenant's campaign settings, so it is untrusted input.
       safe_http_get() enforces HTTPS, re-checks every redirect hop, refuses private and
       link-local addresses, and caps the response size — see includes/helpers.php. */
    return safe_http_get($url, (int) config('media_max_bytes', 33554432));
}

/**
 * Rewrite a prebuilt components payload to send its header media by id instead of link.
 *
 * Campaign payloads are rendered once at creation time, but media ids expire (~30d) — so a
 * campaign scheduled far out must not bake one in. Instead we store the link form and swap
 * in a freshly-resolved id here, at send time, once per campaign per worker run.
 * Passing a null/empty id leaves the payload untouched (link fallback).
 */
function wa_apply_media_id(array $components, ?string $mediaId): array
{
    if ($mediaId === null || $mediaId === '') return $components;
    foreach ($components as $ci => $comp) {
        if (strtolower((string) ($comp['type'] ?? '')) !== 'header') continue;
        foreach ((array) ($comp['parameters'] ?? []) as $pi => $p) {
            $k = strtolower((string) ($p['type'] ?? ''));
            if (!in_array($k, ['image', 'video', 'document'], true)) continue;
            $components[$ci]['parameters'][$pi][$k] = ['id' => $mediaId];
        }
    }
    return $components;
}

/**
 * Is this send failure worth one retry?
 *
 * Only genuine hiccups — a media fetch/upload glitch, a rate limit, a 5xx or a dropped
 * connection. One blip used to fail a message permanently, which looked like random
 * losses on large sends.
 *
 * Deliberate Meta drops are NOT retried, because a resend returns the same error and only
 * burns quota and delays the batch:
 *   #131049 per-user marketing frequency cap ("healthy ecosystem engagement")
 *   #130472 recipient is in a marketing-experiment group (Meta: resending will not bypass it)
 *   #131026 undeliverable (blocked us / not on WhatsApp / restricted) — do not retry
 *   #133010 phone number not registered · #132xxx bad template params · #200 no permission
 */
function wa_error_is_transient(string $code, string $title): bool
{
    $code = trim($code);

    // Explicit deny-list first: these look retryable by wording but never are.
    $never = ['131049', '130472', '131026', '133010', '131047', '131051', '132000', '132001',
              '132005', '132007', '132012', '132015', '132068', '132069', '200', '190', '10'];
    if (in_array($code, $never, true)) return false;

    $transient = ['131053', '131056', '130429', '500', '502', '503', '504', '429', '0'];
    if (in_array($code, $transient, true)) return true;

    $t = strtolower($title);
    // "not delivered to maintain healthy ecosystem engagement" must not match on wording.
    if (strpos($t, 'healthy ecosystem') !== false) return false;
    foreach (['rate limit', 'too many', 'timeout', 'timed out', 'temporarily', 'try again',
              'media upload', 'failed to download', 'internal error', 'network error'] as $needle) {
        if (strpos($t, $needle) !== false) return true;
    }
    return false;
}

/** Does this template need a media header (and therefore gentler send concurrency)? */
function wa_template_has_media(array $components): bool
{
    $spec = wa_template_spec($components);
    return in_array(strtoupper((string) $spec['header']['format']), ['IMAGE', 'VIDEO', 'DOCUMENT'], true);
}

/**
 * Build the FULL template `components` payload from the template's structure + a saved
 * field config, so every component type sends correctly (avoids Meta #132012):
 *   HEADER: image/video/document (media id, falling back to link) · location · text vars
 *   BODY:   {{n}} variables
 *   BUTTONS: dynamic URL suffix · copy-code (coupon). Static/quick-reply buttons need nothing.
 *
 * Shared by Campaigns, the bot canvas template node and the Lead Qualifier so all three
 * send identical, complete payloads.
 *
 * @param array    $tplComponents The template's stored `components` JSON.
 * @param array    $cfg           Saved field config: vars, header_media, header_vars, header_loc, buttons.
 * @param array    $contact       Contact row (for name/attribute-sourced variables).
 * @param array    $client        Client row — enables media-id upload; omit to force links.
 * @param callable|null $resolver fn(array $spec, array $contact): string — overrides the
 *                                default variable resolution (campaigns pass their own,
 *                                which also supports contact attributes).
 */
function wa_build_components(array $tplComponents, array $cfg, array $contact, array $client = [], ?callable $resolver = null): array
{
    $spec = wa_template_spec($tplComponents);
    $out  = [];
    if ($resolver === null) {
        $resolver = function (array $s, array $c): string {
            return function_exists('auto_resolve_var') ? auto_resolve_var($s, $c) : (string) ($s['value'] ?? '-');
        };
    }
    $pick = function ($bag, $i) {
        if (!is_array($bag)) return [];
        return (array) ($bag[(string) $i] ?? $bag[$i] ?? []);
    };

    // ── HEADER ──
    $hf = strtoupper((string) $spec['header']['format']);
    if (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
        $url = trim((string) ($cfg['header_media'] ?? ''));
        if ($url !== '') {
            $k = strtolower($hf);
            // Reuse a Meta media id when we can; fall back to the raw link otherwise.
            $mediaId = !empty($client) ? wa_resolve_media($client, $url) : null;
            $param = $mediaId !== null ? [$k => ['id' => $mediaId]] : [$k => ['link' => $url]];
            $out[] = ['type' => 'header', 'parameters' => [array_merge(['type' => $k], $param)]];
        }
    } elseif ($hf === 'LOCATION') {
        $loc = (array) ($cfg['header_loc'] ?? []);
        $out[] = ['type' => 'header', 'parameters' => [['type' => 'location', 'location' => [
            'latitude'  => (string) ($loc['lat'] ?? ''), 'longitude' => (string) ($loc['lng'] ?? ''),
            'name'      => (string) ($loc['name'] ?? ''), 'address'  => (string) ($loc['address'] ?? ''),
        ]]]];
    } elseif ($hf === 'TEXT' && (int) $spec['header']['text_vars'] > 0) {
        $params = [];
        for ($i = 1; $i <= (int) $spec['header']['text_vars']; $i++) {
            $params[] = ['type' => 'text', 'text' => $resolver($pick($cfg['header_vars'] ?? [], $i), $contact)];
        }
        $out[] = ['type' => 'header', 'parameters' => $params];
    }

    // ── BODY ──
    if ((int) $spec['body_vars'] > 0) {
        $params = [];
        for ($i = 1; $i <= (int) $spec['body_vars']; $i++) {
            $params[] = ['type' => 'text', 'text' => $resolver($pick($cfg['vars'] ?? [], $i), $contact)];
        }
        $out[] = ['type' => 'body', 'parameters' => $params];
    }

    // ── BUTTONS (only dynamic ones take a parameter) ──
    foreach ((array) $spec['buttons'] as $b) {
        if (empty($b['dynamic'])) continue;
        $idx = (int) $b['index'];
        $bag = (array) ($cfg['buttons'] ?? []);
        $val = trim((string) ($bag[(string) $idx] ?? $bag[$idx] ?? ''));
        if ($val === '') $val = '-';
        if ($b['type'] === 'URL') {
            $out[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string) $idx,
                      'parameters' => [['type' => 'text', 'text' => $val]]];
        } elseif ($b['type'] === 'COPY_CODE') {
            $out[] = ['type' => 'button', 'sub_type' => 'copy_code', 'index' => (string) $idx,
                      'parameters' => [['type' => 'coupon_code', 'coupon_code' => $val]]];
        }
    }

    return $out;
}

/**
 * Send many template messages in PARALLEL (curl_multi) for fast bulk campaigns.
 * $items: [key => ['to'=>string, 'name'=>string, 'lang'=>string, 'components'=>array]]
 * Returns [key => ['ok'=>bool, 'wamid'=>?string, 'error_code'=>?string, 'error_title'=>?string]]
 */
function wa_send_template_batch(array $client, array $items): array
{
    $token = wa_token($client);
    $pnid  = wa_phone_id($client);
    $results = [];
    if ($token === '' || $pnid === '' || !$items) {
        foreach ($items as $k => $_) {
            $results[$k] = ['ok' => false, 'wamid' => null, 'error_code' => 'no_credentials', 'error_title' => 'Missing access token or phone number ID'];
        }
        return $results;
    }
    $url = wa_graph_base() . '/' . rawurlencode($pnid) . '/messages';
    $mh  = curl_multi_init();
    $handles = [];
    foreach ($items as $k => $it) {
        $template = ['name' => (string) $it['name'], 'language' => ['code' => (string) $it['lang']]];
        if (!empty($it['components'])) $template['components'] = array_values($it['components']);
        $body = ['messaging_product' => 'whatsapp', 'to' => (string) $it['to'], 'type' => 'template', 'template' => $template];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$k] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 1.0);
    } while ($running > 0);

    foreach ($handles as $k => $ch) {
        $raw  = curl_multi_getcontent($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $json = json_decode((string) $raw, true);
        if ($http >= 200 && $http < 300 && !empty($json['messages'][0]['id'])) {
            $results[$k] = ['ok' => true, 'wamid' => (string) $json['messages'][0]['id'], 'error_code' => null, 'error_title' => null];
        } else {
            $err = $json['error'] ?? [];
            $results[$k] = ['ok' => false, 'wamid' => null,
                'error_code'  => (string) ($err['code'] ?? $http),
                'error_title' => (string) ($err['message'] ?? 'Send failed')];
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

/* ─────────────────────────────────────────────
   Free-form sends (automation flows, inside 24h window)
───────────────────────────────────────────── */

/** Low-level: POST a fully-built message `$payload` (minus messaging_product/to). */
function wa_send_message(array $client, string $to, array $payload): array
{
    $token = wa_token($client);
    $pnid  = wa_phone_id($client);
    if ($token === '' || $pnid === '') {
        return ['ok' => false, 'wamid' => null, 'error_code' => 'no_credentials',
                'error_title' => 'Missing access token or phone number ID', 'http' => 0];
    }
    $body = array_merge(['messaging_product' => 'whatsapp', 'to' => $to], $payload);
    $res  = wa_request('POST', wa_graph_base() . '/' . rawurlencode($pnid) . '/messages', $token, $body);
    $json = $res['json'] ?? [];
    if ($res['http'] >= 200 && $res['http'] < 300 && !empty($json['messages'][0]['id'])) {
        return ['ok' => true, 'wamid' => (string) $json['messages'][0]['id'],
                'error_code' => null, 'error_title' => null, 'http' => $res['http']];
    }
    $err = $json['error'] ?? [];
    return ['ok' => false, 'wamid' => null,
            'error_code'  => (string) ($err['code'] ?? $res['http']),
            'error_title' => (string) ($err['message'] ?? ($res['error'] ?: 'Send failed')),
            'http' => $res['http']];
}

function wa_send_text(array $client, string $to, string $body): array
{
    return wa_send_message($client, $to, ['type' => 'text', 'text' => ['body' => $body]]);
}

function wa_send_image(array $client, string $to, string $link, string $caption = ''): array
{
    $img = ['link' => $link];
    if ($caption !== '') $img['caption'] = $caption;
    return wa_send_message($client, $to, ['type' => 'image', 'image' => $img]);
}

/**
 * Interactive reply buttons (max 3). $buttons = [['id'=>..,'title'=>..], ...]
 * Titles are truncated to WhatsApp's 20-char limit; ids to 256.
 */
/**
 * A tappable list — WhatsApp's answer to "more than three choices".
 *
 * Reply buttons cap at 3. A list holds up to 10 rows behind a single button, which is the
 * difference between "pick a service" working and having to split it across screens.
 * Cloud API only; see channel_send_list() for what a personal number does instead.
 */
function wa_send_list(array $client, string $to, string $body, string $buttonText, array $rows, string $header = ''): array
{
    $items = [];
    foreach (array_slice(array_values($rows), 0, 10) as $i => $r) {
        $items[] = [
            'id'          => substr((string) ($r['id'] ?? ('r' . $i)), 0, 200),
            'title'       => substr((string) ($r['title'] ?? ('Option ' . ($i + 1))), 0, 24),
            'description' => substr((string) ($r['description'] ?? ''), 0, 72),
        ];
    }
    $interactive = [
        'type'   => 'list',
        'body'   => ['text' => substr($body, 0, 1024)],
        'action' => [
            // Meta caps this at 20 characters and rejects the whole message if it is longer.
            'button'   => substr($buttonText !== '' ? $buttonText : 'Choose', 0, 20),
            'sections' => [['title' => substr($header !== '' ? $header : 'Options', 0, 24), 'rows' => $items]],
        ],
    ];
    return wa_send_message($client, $to, ['type' => 'interactive', 'interactive' => $interactive]);
}

function wa_send_buttons(array $client, string $to, string $body, array $buttons): array
{
    $rows = [];
    foreach (array_slice(array_values($buttons), 0, 3) as $i => $b) {
        $rows[] = ['type' => 'reply', 'reply' => [
            'id'    => substr((string) ($b['id'] ?? ('b' . $i)), 0, 256),
            'title' => substr((string) ($b['title'] ?? ('Option ' . ($i + 1))), 0, 20),
        ]];
    }
    return wa_send_message($client, $to, [
        'type' => 'interactive',
        'interactive' => [
            'type'   => 'button',
            'body'   => ['text' => substr($body, 0, 1024)],
            'action' => ['buttons' => $rows],
        ],
    ]);
}
