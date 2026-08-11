<?php
declare(strict_types=1);

/**
 * Real provider adapters. Raw cURL (no SDK / no Composer) so it runs on plain
 * shared hosting, matching the wa-dashboard HTTP style.
 *
 * Every generation adapter returns a normalized result:
 *   ['ok'=>bool, 'error'=>string, 'kind'=>'text|image|video|design',
 *    'text'=>?string, 'rel'=>?string, 'url'=>?string, 'mime'=>?string,
 *    'bytes'=>?int, 'async'=>bool, 'job'=>?array, 'meta'=>array]
 */

const ANTHROPIC_VERSION = '2023-06-01';

/* ─────────────────────────── HTTP helpers ─────────────────────────── */

function adapter_http(string $method, string $url, array $headers, $body = null, int $timeout = 120): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['http' => 0, 'raw' => '', 'ctype' => '', 'error' => $cerr ?: 'Network error'];
    return ['http' => $http, 'raw' => (string) $raw, 'ctype' => $ctype, 'error' => ''];
}

/** JSON POST/GET returning ['http','json','error']. */
function adapter_json(string $method, string $url, array $headers, ?array $body = null, int $timeout = 120): array
{
    $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = adapter_http($method, $url, $headers, $payload, $timeout);
    if ($res['error'] !== '') return ['http' => 0, 'json' => null, 'error' => $res['error']];
    $json = json_decode($res['raw'], true);
    $err  = '';
    if (is_array($json) && isset($json['error'])) {
        $err = is_array($json['error']) ? (string) ($json['error']['message'] ?? 'API error') : (string) $json['error'];
    } elseif ($res['http'] < 200 || $res['http'] >= 300) {
        $err = 'HTTP ' . $res['http'] . ' ' . mb_substr(trim($res['raw']), 0, 300);
    }
    return ['http' => $res['http'], 'json' => is_array($json) ? $json : null, 'error' => $err];
}

/* ─────────────────────────── storage ─────────────────────────── */

function studio_storage_dir(): string { return dirname(__DIR__) . '/storage'; }

function studio_store_bytes(string $bytes, string $ext): array
{
    $sub = date('Ym');
    $dir = studio_storage_dir() . '/' . $sub;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $rel  = $sub . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
    $path = studio_storage_dir() . '/' . $rel;
    file_put_contents($path, $bytes);
    return ['rel' => $rel, 'path' => $path, 'url' => base_url() . '/storage/' . $rel, 'bytes' => strlen($bytes)];
}

/** Download a remote file and store it. Returns store info or null on failure. */
function studio_store_from_url(string $url, string $fallbackExt = 'bin'): ?array
{
    $res = adapter_http('GET', $url, [], null, 180);
    if ($res['error'] !== '' || $res['http'] < 200 || $res['http'] >= 300 || $res['raw'] === '') return null;
    $ext = $fallbackExt;
    if (stripos($res['ctype'], 'mp4') !== false)       $ext = 'mp4';
    elseif (stripos($res['ctype'], 'png') !== false)   $ext = 'png';
    elseif (stripos($res['ctype'], 'jpeg') !== false)  $ext = 'jpg';
    elseif (stripos($res['ctype'], 'webp') !== false)  $ext = 'webp';
    $max = (int) config('max_asset_bytes', 26214400);
    if ($max > 0 && strlen($res['raw']) > $max) return null;
    return studio_store_bytes($res['raw'], $ext) + ['mime' => $res['ctype'] ?: 'application/octet-stream'];
}

function mime_for_ext(string $ext): string
{
    return [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
        'mp4' => 'video/mp4', 'html' => 'text/html', 'txt' => 'text/plain',
    ][strtolower($ext)] ?? 'application/octet-stream';
}

/* ─────────────────────────── TEXT (copy) ─────────────────────────── */

function adapter_text(string $vendor, string $model, string $system, string $prompt, int $maxTokens = 900): array
{
    $c = studio_credentials($vendor);
    $fail = fn($e) => ['ok' => false, 'error' => $e, 'kind' => 'text', 'async' => false, 'meta' => []];

    if ($vendor === 'anthropic') {
        if (empty($c['api_key'])) return $fail('Anthropic key not set');
        $r = adapter_json('POST', 'https://api.anthropic.com/v1/messages',
            ['x-api-key: ' . $c['api_key'], 'anthropic-version: ' . ANTHROPIC_VERSION],
            ['model' => $model, 'max_tokens' => $maxTokens, 'system' => $system,
             'messages' => [['role' => 'user', 'content' => $prompt]]]);
        if ($r['error'] !== '' || !$r['json']) return $fail($r['error'] ?: 'No response');
        $text = '';
        foreach (($r['json']['content'] ?? []) as $b) {
            if (($b['type'] ?? '') === 'text') $text .= (string) ($b['text'] ?? '');
        }
        return ['ok' => true, 'error' => '', 'kind' => 'text', 'text' => trim($text), 'async' => false, 'meta' => ['model' => $model]];
    }

    if ($vendor === 'openai') {
        if (empty($c['api_key'])) return $fail('OpenAI key not set');
        $r = adapter_json('POST', 'https://api.openai.com/v1/chat/completions',
            ['Authorization: Bearer ' . $c['api_key']],
            ['model' => $model, 'max_tokens' => $maxTokens,
             'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]]]);
        if ($r['error'] !== '' || !$r['json']) return $fail($r['error'] ?: 'No response');
        $text = (string) ($r['json']['choices'][0]['message']['content'] ?? '');
        return ['ok' => true, 'error' => '', 'kind' => 'text', 'text' => trim($text), 'async' => false, 'meta' => ['model' => $model]];
    }

    return $fail('Unknown text vendor: ' . $vendor);
}

/* ─────────────────────────── IMAGE (design) ─────────────────────────── */

function adapter_image(string $vendor, string $model, string $prompt, array $opts = []): array
{
    $c = studio_credentials($vendor);
    $size = (string) ($opts['size'] ?? '1024x1024');
    $fail = fn($e) => ['ok' => false, 'error' => $e, 'kind' => 'image', 'async' => false, 'meta' => []];

    if ($vendor === 'openai') {
        if (empty($c['api_key'])) return $fail('OpenAI key not set');
        $r = adapter_json('POST', 'https://api.openai.com/v1/images/generations',
            ['Authorization: Bearer ' . $c['api_key']],
            ['model' => $model ?: 'gpt-image-1', 'prompt' => $prompt, 'size' => $size, 'n' => 1], 180);
        if ($r['error'] !== '' || !$r['json']) return $fail($r['error'] ?: 'No response');
        $b64 = (string) ($r['json']['data'][0]['b64_json'] ?? '');
        if ($b64 === '') {
            $url = (string) ($r['json']['data'][0]['url'] ?? '');
            if ($url === '') return $fail('No image returned');
            $st = studio_store_from_url($url, 'png');
            if (!$st) return $fail('Could not download generated image');
            return ['ok' => true, 'error' => '', 'kind' => 'image', 'rel' => $st['rel'], 'url' => $st['url'],
                    'mime' => $st['mime'] ?? 'image/png', 'bytes' => $st['bytes'], 'async' => false, 'meta' => ['model' => $model]];
        }
        $bytes = base64_decode($b64);
        if ($bytes === false) return $fail('Bad image data');
        $st = studio_store_bytes($bytes, 'png');
        return ['ok' => true, 'error' => '', 'kind' => 'image', 'rel' => $st['rel'], 'url' => $st['url'],
                'mime' => 'image/png', 'bytes' => $st['bytes'], 'async' => false, 'meta' => ['model' => $model]];
    }

    if ($vendor === 'stability') {
        if (empty($c['api_key'])) return $fail('Stability key not set');
        $endpoint = 'core';
        if ($model === 'ultra') $endpoint = 'ultra';
        elseif (str_starts_with($model, 'sd3')) $endpoint = 'sd3';
        $url = 'https://api.stability.ai/v2beta/stable-image/generate/' . $endpoint;
        $form = ['prompt' => $prompt, 'output_format' => 'png'];
        if (!empty($opts['aspect_ratio'])) $form['aspect_ratio'] = (string) $opts['aspect_ratio'];
        if ($endpoint === 'sd3') $form['model'] = $model;
        // Multipart form; Accept image/* returns raw bytes.
        $res = adapter_http('POST', $url,
            ['Authorization: Bearer ' . $c['api_key'], 'Accept: image/*'], $form, 180);
        if ($res['error'] !== '') return $fail($res['error']);
        if ($res['http'] < 200 || $res['http'] >= 300) {
            $j = json_decode($res['raw'], true);
            $msg = is_array($j) ? implode('; ', (array) ($j['errors'] ?? [$j['message'] ?? ('HTTP ' . $res['http'])])) : ('HTTP ' . $res['http']);
            return $fail($msg);
        }
        $st = studio_store_bytes($res['raw'], 'png');
        return ['ok' => true, 'error' => '', 'kind' => 'image', 'rel' => $st['rel'], 'url' => $st['url'],
                'mime' => 'image/png', 'bytes' => $st['bytes'], 'async' => false, 'meta' => ['model' => $model]];
    }

    return $fail('Unknown image vendor: ' . $vendor);
}

/* ─────────────── DESIGN MARKUP (Claude editable HTML/SVG poster) ─────────────── */

function adapter_design_markup(string $model, string $prompt, array $opts = []): array
{
    $w = (int) ($opts['width'] ?? 1080);
    $h = (int) ($opts['height'] ?? 1080);
    $system = "You are a senior graphic designer. Output ONE self-contained HTML document for a "
        . "{$w}x{$h}px social media poster. Rules: a single root <div> sized exactly {$w}x{$h}px with "
        . "overflow hidden; ALL CSS inline in a <style> tag; use web-safe/system fonts only (no external "
        . "requests); no <script>; use solid colors and CSS gradients for backgrounds (no external images). "
        . "Make the headline large and legible. Return ONLY the HTML, no explanation, no markdown fences.";
    $res = adapter_text('anthropic', $model, $system, $prompt, 2000);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'kind' => 'design', 'async' => false, 'meta' => []];
    $html = trim($res['text']);
    // Strip accidental markdown fences.
    $html = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $html);
    if (stripos($html, '<') === false) return ['ok' => false, 'error' => 'No markup returned', 'kind' => 'design', 'async' => false, 'meta' => []];
    $st = studio_store_bytes($html, 'html');
    return ['ok' => true, 'error' => '', 'kind' => 'design', 'rel' => $st['rel'], 'url' => $st['url'],
            'mime' => 'text/html', 'bytes' => $st['bytes'], 'async' => false, 'meta' => ['model' => $model, 'w' => $w, 'h' => $h]];
}

/* ─────────────────────────── VIDEO (Kling) ─────────────────────────── */

/** Sign a short-lived HS256 JWT from Kling access/secret keys. */
function kling_jwt(string $accessKey, string $secretKey): string
{
    $b64 = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    $header  = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = $b64(json_encode(['iss' => $accessKey, 'exp' => time() + 1800, 'nbf' => time() - 5]));
    $sig     = $b64(hash_hmac('sha256', $header . '.' . $payload, $secretKey, true));
    return $header . '.' . $payload . '.' . $sig;
}

function kling_base(array $c): string
{
    $b = trim((string) ($c['base'] ?? ''));
    return rtrim($b !== '' ? $b : 'https://api-singapore.klingai.com', '/');
}

/** Start a text-to-video job. Returns normalized async result with job info. */
function adapter_video_start(string $model, string $prompt, array $opts = []): array
{
    $c = studio_credentials('kling');
    $fail = fn($e) => ['ok' => false, 'error' => $e, 'kind' => 'video', 'async' => true, 'meta' => []];
    if (empty($c['access_key']) || empty($c['secret_key'])) return $fail('Kling keys not set');

    $jwt  = kling_jwt((string) $c['access_key'], (string) $c['secret_key']);
    $base = kling_base($c);
    $body = [
        'model_name'   => $model ?: 'kling-v1-6',
        'prompt'       => $prompt,
        'duration'     => (string) ($opts['duration'] ?? '5'),
        'aspect_ratio' => (string) ($opts['aspect_ratio'] ?? '16:9'),
        'mode'         => (string) ($opts['mode'] ?? 'std'),
    ];
    $r = adapter_json('POST', $base . '/v1/videos/text2video', ['Authorization: Bearer ' . $jwt], $body, 60);
    if ($r['error'] !== '' || !$r['json']) return $fail($r['error'] ?: 'No response');
    if ((int) ($r['json']['code'] ?? -1) !== 0) return $fail((string) ($r['json']['message'] ?? 'Kling error'));
    $taskId = (string) ($r['json']['data']['task_id'] ?? '');
    if ($taskId === '') return $fail('No task_id returned');
    return ['ok' => true, 'error' => '', 'kind' => 'video', 'async' => true,
            'job' => ['vendor' => 'kling', 'task_id' => $taskId, 'model' => $body['model_name']], 'meta' => []];
}

/**
 * Poll a Kling job. Returns:
 *   ['status'=>'processing|succeeded|failed', 'error'=>?, 'rel'=>?, 'url'=>?, 'mime'=>?, 'bytes'=>?]
 */
function adapter_video_poll(string $taskId): array
{
    $c = studio_credentials('kling');
    if (empty($c['access_key']) || empty($c['secret_key'])) return ['status' => 'failed', 'error' => 'Kling keys not set'];
    $jwt  = kling_jwt((string) $c['access_key'], (string) $c['secret_key']);
    $base = kling_base($c);
    $r = adapter_json('GET', $base . '/v1/videos/text2video/' . rawurlencode($taskId), ['Authorization: Bearer ' . $jwt], null, 60);
    if ($r['error'] !== '' || !$r['json']) return ['status' => 'processing', 'error' => $r['error']];
    $data   = $r['json']['data'] ?? [];
    $status = (string) ($data['task_status'] ?? '');
    if ($status === 'failed') return ['status' => 'failed', 'error' => (string) ($data['task_status_msg'] ?? 'Generation failed')];
    if ($status !== 'succeed') return ['status' => 'processing', 'error' => ''];
    $videoUrl = (string) ($data['task_result']['videos'][0]['url'] ?? '');
    if ($videoUrl === '') return ['status' => 'failed', 'error' => 'No video URL in result'];
    $st = studio_store_from_url($videoUrl, 'mp4');
    if (!$st) return ['status' => 'failed', 'error' => 'Could not download video'];
    return ['status' => 'succeeded', 'error' => '', 'rel' => $st['rel'], 'url' => $st['url'],
            'mime' => 'video/mp4', 'bytes' => $st['bytes']];
}

/* ─────────────────────────── PUBLISH (Meta) ─────────────────────────── */

function meta_graph_version(array $c): string
{
    $v = trim((string) ($c['graph_version'] ?? ''));
    return $v !== '' ? $v : 'v21.0';
}

/**
 * Publish an asset (public URL required) to Facebook or Instagram.
 * $module is 'meta_facebook' or 'meta_instagram'. $asset = ['url','mime'].
 * Returns ['ok','error','post_id','permalink'].
 */
function adapter_publish_meta(string $module, array $asset, string $caption): array
{
    $c = studio_credentials('meta');
    $fail = fn($e) => ['ok' => false, 'error' => $e, 'post_id' => '', 'permalink' => ''];
    $token = (string) ($c['access_token'] ?? '');
    if ($token === '') return $fail('Meta access token not set');
    $ver   = meta_graph_version($c);
    $isVideo = str_starts_with((string) ($asset['mime'] ?? ''), 'video');
    $mediaUrl = (string) ($asset['url'] ?? '');
    if ($mediaUrl === '' || str_contains($mediaUrl, 'localhost') || str_contains($mediaUrl, '127.0.0.1')) {
        return $fail('A public https media URL is required to publish (set base_url to a reachable domain).');
    }

    if ($module === 'meta_facebook') {
        $pageId = (string) ($c['facebook_page_id'] ?? '');
        if ($pageId === '') return $fail('Facebook Page ID not set');
        if ($isVideo) {
            $r = adapter_json('POST', "https://graph.facebook.com/$ver/$pageId/videos", [],
                ['file_url' => $mediaUrl, 'description' => $caption, 'access_token' => $token], 180);
            $id = (string) ($r['json']['id'] ?? '');
        } else {
            $r = adapter_json('POST', "https://graph.facebook.com/$ver/$pageId/photos", [],
                ['url' => $mediaUrl, 'caption' => $caption, 'access_token' => $token], 120);
            $id = (string) ($r['json']['post_id'] ?? $r['json']['id'] ?? '');
        }
        if ($r['error'] !== '' || $id === '') return $fail($r['error'] ?: 'Publish failed');
        return ['ok' => true, 'error' => '', 'post_id' => $id, 'permalink' => "https://facebook.com/$id"];
    }

    if ($module === 'meta_instagram') {
        $igId = (string) ($c['instagram_user_id'] ?? '');
        if ($igId === '') return $fail('Instagram User ID not set');
        // 1) create media container
        $body = $isVideo
            ? ['media_type' => 'REELS', 'video_url' => $mediaUrl, 'caption' => $caption, 'access_token' => $token]
            : ['image_url' => $mediaUrl, 'caption' => $caption, 'access_token' => $token];
        $r = adapter_json('POST', "https://graph.facebook.com/$ver/$igId/media", [], $body, 120);
        $creationId = (string) ($r['json']['id'] ?? '');
        if ($r['error'] !== '' || $creationId === '') return $fail($r['error'] ?: 'Could not create IG media container');
        // 2) videos need processing before publish — poll status
        if ($isVideo) {
            for ($i = 0; $i < 20; $i++) {
                sleep(3);
                $s = adapter_json('GET', "https://graph.facebook.com/$ver/$creationId?fields=status_code&access_token=" . urlencode($token), [], null, 60);
                $code = (string) ($s['json']['status_code'] ?? '');
                if ($code === 'FINISHED') break;
                if ($code === 'ERROR') return $fail('IG media processing failed');
            }
        }
        // 3) publish
        $p = adapter_json('POST', "https://graph.facebook.com/$ver/$igId/media_publish", [],
            ['creation_id' => $creationId, 'access_token' => $token], 120);
        $mediaId = (string) ($p['json']['id'] ?? '');
        if ($p['error'] !== '' || $mediaId === '') return $fail($p['error'] ?: 'IG publish failed');
        return ['ok' => true, 'error' => '', 'post_id' => $mediaId, 'permalink' => "https://www.instagram.com/"];
    }

    return $fail('Unknown publish module: ' . $module);
}

/** Lightweight credential test per vendor. Returns ['ok','error']. */
function adapter_test_vendor(string $vendor): array
{
    $c = studio_credentials($vendor);
    switch ($vendor) {
        case 'anthropic':
            $r = adapter_text('anthropic', 'claude-haiku-4-5', 'Reply with OK.', 'ping', 5);
            return ['ok' => $r['ok'], 'error' => $r['error']];
        case 'openai':
            $r = adapter_json('GET', 'https://api.openai.com/v1/models', ['Authorization: Bearer ' . ($c['api_key'] ?? '')], null, 30);
            return ['ok' => $r['error'] === '', 'error' => $r['error']];
        case 'stability':
            $r = adapter_json('GET', 'https://api.stability.ai/v1/user/account', ['Authorization: Bearer ' . ($c['api_key'] ?? '')], null, 30);
            return ['ok' => $r['error'] === '', 'error' => $r['error']];
        case 'kling':
            if (empty($c['access_key']) || empty($c['secret_key'])) return ['ok' => false, 'error' => 'Keys missing'];
            $jwt = kling_jwt((string) $c['access_key'], (string) $c['secret_key']);
            $r = adapter_json('GET', kling_base($c) . '/v1/videos/text2video/nonexistent', ['Authorization: Bearer ' . $jwt], null, 30);
            // Auth OK even if the task id is unknown (we only check we weren't rejected as unauthorized).
            $ok = !str_contains(strtolower($r['error']), 'auth') && !str_contains($r['error'], '401');
            return ['ok' => $ok, 'error' => $ok ? '' : $r['error']];
        case 'meta':
            $token = (string) ($c['access_token'] ?? '');
            if ($token === '') return ['ok' => false, 'error' => 'Token missing'];
            $r = adapter_json('GET', 'https://graph.facebook.com/' . meta_graph_version($c) . '/me?access_token=' . urlencode($token), [], null, 30);
            return ['ok' => $r['error'] === '', 'error' => $r['error']];
    }
    return ['ok' => false, 'error' => 'Unknown vendor'];
}
