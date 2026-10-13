<?php
declare(strict_types=1);

/**
 * Per-client AI abstraction — routes to Claude (Anthropic) or OpenAI via raw HTTP.
 * The client's API key is stored encrypted (crypto.php). Used by the sentiment
 * engine to classify the mentions the lexicon was not confident about.
 *
 * Raw cURL (not an SDK): matches the platform's existing HTTP style, needs no
 * Composer on the host, and must speak two providers behind one interface.
 *
 * Nothing here throws — every failure degrades to a neutral return value, so a
 * provider outage never breaks a page or a worker run.
 */

// Sentiment classification is high-volume and simple; default to the cheap
// tier and let a client override it per account.
const AI_DEFAULT_CLAUDE_MODEL = 'claude-haiku-4-5';
const AI_DEFAULT_OPENAI_MODEL = 'gpt-4o-mini';
const AI_ANTHROPIC_VERSION    = '2023-06-01';

/** Resolve a client's AI config: ['provider','key','model'] or null if unset. */
function ai_config(array $client): ?array
{
    $provider = strtolower((string) ($client['ai_provider'] ?? ''));
    if (!in_array($provider, ['claude', 'openai'], true)) return null;
    $key = decrypt_secret((string) ($client['ai_api_key_enc'] ?? ''));
    if ($key === '') return null;
    $model = trim((string) ($client['ai_model'] ?? ''));
    if ($model === '') {
        $model = $provider === 'claude' ? AI_DEFAULT_CLAUDE_MODEL : AI_DEFAULT_OPENAI_MODEL;
    }
    return ['provider' => $provider, 'key' => $key, 'model' => $model];
}

function ai_configured(array $client): bool
{
    return ai_config($client) !== null;
}

/** Low-level JSON POST. Returns ['http'=>int,'json'=>?array,'error'=>string]. */
function ai_http(string $url, array $headers, array $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw  = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['http' => 0, 'json' => null, 'error' => $cerr ?: 'Network error'];
    $json = json_decode((string) $raw, true);
    $err  = '';
    if (is_array($json) && isset($json['error'])) {
        $err = (string) ($json['error']['message'] ?? 'API error');
    } elseif ($http < 200 || $http >= 300) {
        $err = 'HTTP ' . $http;
    }
    return ['http' => $http, 'json' => is_array($json) ? $json : null, 'error' => $err];
}

/**
 * Single completion. $messages = [['role'=>'user'|'assistant','content'=>...], ...].
 * Returns ['ok'=>bool, 'text'=>string, 'error'=>string].
 */
function ai_complete(array $client, string $system, array $messages, int $maxTokens = 512): array
{
    $cfg = ai_config($client);
    if (!$cfg) return ['ok' => false, 'text' => '', 'error' => 'AI not configured'];

    if ($cfg['provider'] === 'claude') {
        $res = ai_http(
            'https://api.anthropic.com/v1/messages',
            ['x-api-key: ' . $cfg['key'], 'anthropic-version: ' . AI_ANTHROPIC_VERSION],
            [
                'model'      => $cfg['model'],
                'max_tokens' => $maxTokens,
                'system'     => $system,
                'messages'   => array_values($messages),
            ]
        );
        if ($res['error'] !== '' || !$res['json']) {
            return ['ok' => false, 'text' => '', 'error' => $res['error'] ?: 'No response'];
        }
        $text = '';
        foreach (($res['json']['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= (string) ($block['text'] ?? '');
        }
        return ['ok' => true, 'text' => trim($text), 'error' => ''];
    }

    // OpenAI
    $msgs = array_merge([['role' => 'system', 'content' => $system]], array_values($messages));
    $res  = ai_http(
        'https://api.openai.com/v1/chat/completions',
        ['Authorization: Bearer ' . $cfg['key']],
        ['model' => $cfg['model'], 'max_tokens' => $maxTokens, 'messages' => $msgs]
    );
    if ($res['error'] !== '' || !$res['json']) {
        return ['ok' => false, 'text' => '', 'error' => $res['error'] ?: 'No response'];
    }
    $text = (string) ($res['json']['choices'][0]['message']['content'] ?? '');
    return ['ok' => true, 'text' => trim($text), 'error' => ''];
}

/** Extract the first JSON object from a model reply, defensively. */
function ai_extract_json(string $text): ?array
{
    $text = trim($text);
    if ($text === '') return null;
    if ($text[0] !== '{') {
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $text = substr($text, $start, $end - $start + 1);
    }
    $data = json_decode($text, true);
    return is_array($data) ? $data : null;
}

/** Validate the client's AI key with a tiny call. Returns ['ok'=>bool,'error'=>string]. */
function ai_test_key(array $client): array
{
    if (!ai_configured($client)) return ['ok' => false, 'error' => 'No provider/key set'];
    $res = ai_complete($client, 'Reply with the single word OK.', [['role' => 'user', 'content' => 'ping']], 5);
    return ['ok' => $res['ok'], 'error' => $res['error']];
}
