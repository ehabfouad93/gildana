<?php
declare(strict_types=1);

/**
 * Thin client for the official WhatsApp Cloud API (Meta Graph API).
 * Credentials come from a `clients` row (token stored encrypted).
 */

function wa_graph_base(): string
{
    return 'https://graph.facebook.com/' . (string) config('graph_version', 'v21.0');
}

/** Decrypt and return a client's access token. */
function wa_token(array $client): string
{
    return decrypt_secret((string) ($client['access_token_enc'] ?? ''));
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
    $pnid  = (string) ($client['phone_number_id'] ?? '');
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

/**
 * Send many template messages in PARALLEL (curl_multi) for fast bulk campaigns.
 * $items: [key => ['to'=>string, 'name'=>string, 'lang'=>string, 'components'=>array]]
 * Returns [key => ['ok'=>bool, 'wamid'=>?string, 'error_code'=>?string, 'error_title'=>?string]]
 */
function wa_send_template_batch(array $client, array $items): array
{
    $token = wa_token($client);
    $pnid  = (string) ($client['phone_number_id'] ?? '');
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
    $pnid  = (string) ($client['phone_number_id'] ?? '');
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
