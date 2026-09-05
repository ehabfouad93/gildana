<?php
declare(strict_types=1);

/**
 * Per-client AI abstraction — routes to Claude (Anthropic) or OpenAI via raw HTTP.
 * The client's API key is stored encrypted (crypto.php). Used by the automation
 * engine's ai_branch (classify a free-text reply) and ai_score nodes.
 *
 * Raw cURL (not an SDK): matches the app's existing HTTP style (wa_request), needs
 * no Composer on the host, and must speak two providers behind one interface.
 */

const AI_DEFAULT_CLAUDE_MODEL = 'claude-opus-4-8';   // configurable per client; set 'claude-haiku-4-5' to cut cost
const AI_DEFAULT_OPENAI_MODEL = 'gpt-4o-mini';
const AI_ANTHROPIC_VERSION    = '2023-06-01';

/** Resolve a client's AI config: ['provider','key','model'] or null if unset. */
function ai_config(array $client): ?array
{
    /* The client's own key comes first, always. It costs the platform nothing, so it is
       never metered and never charged — which is also why it stays the cheaper option for
       them and the free option for us. */
    $provider = strtolower((string) ($client['ai_provider'] ?? ''));
    $key      = decrypt_secret((string) ($client['ai_api_key_enc'] ?? ''));
    if (in_array($provider, ['claude', 'openai'], true) && $key !== '') {
        $model = trim((string) ($client['ai_model'] ?? ''));
        if ($model === '') $model = $provider === 'claude' ? AI_DEFAULT_CLAUDE_MODEL : AI_DEFAULT_OPENAI_MODEL;
        return ['provider' => $provider, 'key' => $key, 'model' => $model, 'billed' => false];
    }

    /* No key of their own. If their plan includes AI, they use the platform key and their
       tokens are metered against the plan's allowance. Running past the allowance does not
       produce a surprise bill — the caller falls back to the step's own text instead. */
    if (function_exists('billing_ai_included') && billing_ai_included($client)) {
        $left = billing_ai_remaining($client);
        if ($left !== null && $left <= 0) return null;      // allowance spent — fall back
        $pf = billing_platform_ai();
        if ($pf) {
            $model = $pf['model'] !== '' ? $pf['model']
                   : ($pf['provider'] === 'claude' ? AI_DEFAULT_CLAUDE_MODEL : AI_DEFAULT_OPENAI_MODEL);
            return ['provider' => $pf['provider'], 'key' => $pf['key'], 'model' => $model, 'billed' => true];
        }
    }
    return null;
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
        ai_meter($client, $cfg, $res['json']['usage'] ?? [], 'input_tokens', 'output_tokens');
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
    ai_meter($client, $cfg, $res['json']['usage'] ?? [], 'prompt_tokens', 'completion_tokens');
    return ['ok' => true, 'text' => trim($text), 'error' => ''];
}

/**
 * Bill one completion, if it was ours to pay for.
 *
 * Both providers report token counts on the response, under different names. A client using
 * their own key is skipped entirely — nothing recorded, nothing charged.
 */
function ai_meter(array $client, array $cfg, array $usage, string $inKey, string $outKey): void
{
    if (empty($cfg['billed']) || !function_exists('billing_charge_ai')) return;
    billing_charge_ai(
        $client, (string) $cfg['provider'], (string) $cfg['model'],
        (int) ($usage[$inKey] ?? 0), (int) ($usage[$outKey] ?? 0),
        (string) (ai_usage_source() ?: '')
    );
}

/** Which part of the product asked for this completion — for the usage breakdown. */
function ai_usage_source(?string $set = null): string
{
    static $src = '';
    if ($set !== null) $src = $set;
    return $src;
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

/** Render a transcript array into a compact text block for prompting. */
function ai_transcript_text(array $transcript, int $maxTurns = 20): string
{
    $lines = [];
    foreach (array_slice($transcript, -$maxTurns) as $t) {
        $role = ($t['role'] ?? 'user') === 'assistant' ? 'Bot' : 'Lead';
        $lines[] = $role . ': ' . trim((string) ($t['text'] ?? ''));
    }
    return implode("\n", $lines);
}

/**
 * Classify the lead's latest reply into one of $branches (each ['label','description']).
 * Returns the chosen 0-based index, or -1 if undecided/error.
 */
function ai_classify(array $client, array $transcript, array $branches): int
{
    if (!$branches) return -1;
    $list = [];
    foreach (array_values($branches) as $i => $b) {
        $list[] = $i . ') ' . trim((string) ($b['label'] ?? ('Option ' . $i)))
                . ' — ' . trim((string) ($b['description'] ?? ''));
    }
    $system = "You are a routing classifier for a WhatsApp lead-qualification bot. "
            . "Read the conversation and choose the single option that best matches the lead's most recent reply and intent. "
            . "Respond with ONLY a JSON object: {\"branch\": <integer index>}. No other text.";
    $user = "Conversation:\n" . ai_transcript_text($transcript)
          . "\n\nOptions:\n" . implode("\n", $list)
          . "\n\nReturn the best option index as JSON.";
    $res = ai_complete($client, $system, [['role' => 'user', 'content' => $user]], 60);
    if (!$res['ok']) return -1;
    $json = ai_extract_json($res['text']);
    if ($json === null || !array_key_exists('branch', $json)) return -1;
    $idx = (int) $json['branch'];
    return ($idx >= 0 && $idx < count($branches)) ? $idx : -1;
}

/**
 * Score the conversation against $criterion, returning an integer 0..$maxPoints.
 * Returns 0 on failure.
 */
function ai_score_reply(array $client, array $transcript, string $criterion, int $maxPoints): int
{
    $maxPoints = max(0, $maxPoints);
    $system = "You are scoring a sales lead from a WhatsApp conversation. "
            . "Award points from 0 to {$maxPoints} based on how well the lead meets the criterion. "
            . "Respond with ONLY a JSON object: {\"points\": <integer 0-{$maxPoints}>}. No other text.";
    $user = "Criterion for a good lead: " . trim($criterion)
          . "\n\nConversation:\n" . ai_transcript_text($transcript)
          . "\n\nReturn the points as JSON.";
    $res = ai_complete($client, $system, [['role' => 'user', 'content' => $user]], 40);
    if (!$res['ok']) return 0;
    $json = ai_extract_json($res['text']);
    if ($json === null || !array_key_exists('points', $json)) return 0;
    return max(0, min($maxPoints, (int) $json['points']));
}

/**
 * Knowledge-grounded conversational reply for the Lead Qualifier / AI Chat Agent.
 * The AI answers the lead using ONLY the client-provided knowledge base, sounds human,
 * pursues the client's goals, extracts requested fields, and flags disinterest.
 *   $goals    = ['book a visit', 'get the budget', …]
 *   $captures = [['key'=>'mobile','label'=>'the customer\'s mobile number'], …]
 * Returns ['ok','reply','done','interested'(bool),'reason','captured'(assoc key=>value)].
 */
function ai_chat_reply(array $client, array $transcript, string $latest, string $knowledge, string $persona = '', array $goals = [], array $captures = [], string $instructions = ''): array
{
    $knowledge = trim($knowledge);
    if (mb_strlen($knowledge) > 12000) $knowledge = mb_substr($knowledge, 0, 12000); // bound token cost
    $persona = trim($persona) !== '' ? trim($persona) : 'a friendly, professional sales assistant';
    $instructions = trim($instructions);

    $goalLines = '';
    foreach (array_values(array_filter(array_map('trim', $goals))) as $g) $goalLines .= "- {$g}\n";

    $capLines = ''; $capKeys = [];
    foreach ($captures as $c) {
        $k = trim((string) ($c['key'] ?? '')); $l = trim((string) ($c['label'] ?? $k));
        if ($k === '') continue;
        $capKeys[] = $k;
        $capLines .= "- {$k}: {$l}\n";
    }

    $system = "You are {$persona} chatting with a potential customer on WhatsApp. "
            . "Reply in the SAME language the customer writes in (Arabic or English; match their dialect). "
            . "Sound human: short, warm, natural — 1 to 3 sentences, like a real person texting, no bullet lists. "
            . ($instructions !== ''
                ? "\n\nIMPORTANT INSTRUCTIONS FROM THE BUSINESS — follow these exactly, especially for language, dialect, tone and wording:\n{$instructions}\n\n"
                : '')
            . "Answer ONLY using the KNOWLEDGE below. If something is not covered, say a colleague will follow up with the details — never invent facts, prices, dates, or promises.\n"
            . ($goalLines !== '' ? "\nPursue these goals naturally, without ignoring the customer's own questions:\n{$goalLines}" : '')
            . ($capLines !== '' ? "\nWhenever the customer provides any of these, return them in \"captured\" (key → value), else omit:\n{$capLines}" : '')
            . "\nIf the customer is clearly NOT interested (says no, stop, not now, wrong number, etc.), set \"interested\": false and give a short \"reason\".\n"
            . "Set \"done\": true when the goals are met, the customer wants to proceed, or they are not interested.\n"
            . "Respond with ONLY a JSON object: {\"reply\": \"<your whatsapp message>\", \"done\": <true|false>, \"interested\": <true|false>, \"reason\": \"<short, only if not interested>\", \"captured\": { }}. No text outside the JSON.\n\n"
            . "KNOWLEDGE:\n" . $knowledge;

    $user = "Conversation so far:\n" . ai_transcript_text($transcript)
          . "\n\nCustomer's latest message: " . trim($latest)
          . "\n\nWrite your reply now as JSON.";

    $res = ai_complete($client, $system, [['role' => 'user', 'content' => $user]], 500);
    if (!$res['ok']) return ['ok' => false, 'reply' => '', 'done' => false, 'interested' => true, 'reason' => '', 'captured' => []];

    $json = ai_extract_json($res['text']);
    if ($json === null || !isset($json['reply'])) {
        $txt = trim($res['text']);   // JSON parse failed → use raw text, keep chatting
        return ['ok' => $txt !== '', 'reply' => $txt, 'done' => false, 'interested' => true, 'reason' => '', 'captured' => []];
    }

    // Only keep captured keys the client asked for (defensive).
    $captured = [];
    if (isset($json['captured']) && is_array($json['captured'])) {
        foreach ($json['captured'] as $k => $v) {
            if (($capKeys === [] || in_array((string) $k, $capKeys, true)) && $v !== '' && $v !== null) {
                $captured[(string) $k] = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
            }
        }
    }
    $interested = !array_key_exists('interested', $json) || !empty($json['interested']);
    return [
        'ok'         => true,
        'reply'      => trim((string) $json['reply']),
        'done'       => !empty($json['done']) || !$interested,
        'interested' => $interested,
        'reason'     => trim((string) ($json['reason'] ?? '')),
        'captured'   => $captured,
    ];
}

/** Validate the client's AI key with a tiny call. Returns ['ok'=>bool,'error'=>string]. */
function ai_test_key(array $client): array
{
    if (!ai_configured($client)) return ['ok' => false, 'error' => 'No provider/key set'];
    $res = ai_complete($client, 'Reply with the single word OK.', [['role' => 'user', 'content' => 'ping']], 5);
    return ['ok' => $res['ok'], 'error' => $res['error']];
}
