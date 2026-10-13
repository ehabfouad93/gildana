<?php
declare(strict_types=1);

/**
 * Hybrid sentiment engine.
 *
 * Pass 1 — a bilingual lexicon (Arabic incl. Egyptian dialect + English) scores
 *          every new mention. No network, no cost.
 * Pass 2 — only the mentions the lexicon was not confident about are sent to the
 *          AI model, batched, and only when the client has a key and budget.
 *
 * A human's manual classification always wins: every engine write carries
 * `AND is_manual = 0`, so nothing here can ever silently undo a person's call.
 */

const SENT_MIN_CONFIDENCE  = 0.55;   // below this → escalate to the AI pass
const SENT_LABEL_THRESHOLD = 0.15;   // |score| below this → neutral
const SENT_AI_MAX_ATTEMPTS = 2;

/* ── text normalization ────────────────────────────────────────────────── */

/**
 * Fold Arabic orthography and lowercase Latin so that lexicon lookups and
 * keyword matching both see one canonical form.
 *
 * Without this, "جيلدانا" / "جِيلدانا" / "چيلدانا" are three different strings
 * and half the matches are silently missed.
 */
function sent_normalize(string $text): string
{
    $t = $text;

    // Strip tashkeel (harakat) and the tatweel elongation character.
    $t = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $t) ?? $t;
    $t = str_replace("\u{0640}", '', $t);

    // Unify the letter forms writers use interchangeably.
    $t = strtr($t, [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي', 'ئ' => 'ي',
        'ة' => 'ه',
        'ؤ' => 'و',
        'گ' => 'ك', 'چ' => 'ج', 'پ' => 'ب', 'ڤ' => 'ف',
    ]);

    // Arabic-Indic digits → ASCII.
    $t = strtr($t, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4',
                    '٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);

    // Drop URLs, @handles and the # of hashtags (the word itself still counts).
    $t = preg_replace('#https?://\S+#u', ' ', $t) ?? $t;
    $t = preg_replace('/@[\w\.\-]+/u', ' ', $t) ?? $t;
    $t = str_replace('#', ' ', $t);

    // "حلوووو" and "greaaaat" collapse to two repeats so they match the lexicon.
    $t = preg_replace('/(.)\1{2,}/u', '$1$1', $t) ?? $t;

    $t = mb_strtolower($t, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
}

/** 'ar' when Arabic letters dominate, else 'en'. */
function sent_detect_lang(string $text): string
{
    $arabic = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
    $latin  = preg_match_all('/[A-Za-z]/u', $text);
    $total  = $arabic + $latin;
    if ($total === 0) return 'en';
    return ($arabic / $total) > 0.30 ? 'ar' : 'en';
}

/** Load a lexicon with every key normalized, memoized per request. */
function sent_lexicon(string $lang): array
{
    static $cache = [];
    if (isset($cache[$lang])) return $cache[$lang];

    $file = __DIR__ . '/lexicon_' . ($lang === 'ar' ? 'ar' : 'en') . '.php';
    $raw  = is_file($file) ? (array) require $file : [];

    $norm = function (array $map, bool $assoc): array {
        $out = [];
        foreach ($map as $k => $v) {
            if ($assoc) {
                $key = sent_normalize((string) $k);
                if ($key !== '') $out[$key] = (float) $v;
            } else {
                $key = sent_normalize((string) $v);
                if ($key !== '') $out[] = $key;
            }
        }
        return $out;
    };

    $cache[$lang] = [
        'terms'        => $norm($raw['positive'] ?? [], true) + $norm($raw['negative'] ?? [], true),
        'negators'     => $norm($raw['negators'] ?? [], false),
        'intensifiers' => $norm($raw['intensifiers'] ?? [], true),
        'diminishers'  => $norm($raw['diminishers'] ?? [], true),
        'ambiguous'    => $norm($raw['ambiguous'] ?? [], false),
    ];
    return $cache[$lang];
}

/** Emoji carry sentiment more reliably than most words. */
function sent_emoji(): array
{
    return [
        '😍' => 0.85, '❤' => 0.7, '❤️' => 0.7, '😻' => 0.8, '👏' => 0.65, '🔥' => 0.6,
        '💯' => 0.75, '👍' => 0.7, '🥰' => 0.8, '😊' => 0.6, '🙏' => 0.4, '✨' => 0.5,
        '😡' => -0.9, '🤬' => -1.0, '👎' => -0.8, '💩' => -0.9, '😠' => -0.8, '😤' => -0.7,
        '🙄' => -0.55, '😑' => -0.45, '😢' => -0.6, '😭' => -0.6, '⚠' => -0.4, '❌' => -0.5,
        // Laughter is the classic sarcasm carrier — no polarity, but it lowers
        // confidence, which is what routes the mention to the model instead.
        '😂' => 0.0, '🤣' => 0.0,
    ];
}

/* ── the lexicon pass ──────────────────────────────────────────────────── */

/**
 * Score text against the lexicon.
 *
 * @return array{label:string,score:float,confidence:float,hits:array,reason:string}
 */
function sent_lexicon_score(string $text, string $lang = ''): array
{
    $lang = $lang !== '' && $lang !== 'both' ? $lang : sent_detect_lang($text);
    $lex  = sent_lexicon($lang);
    $norm = sent_normalize($text);

    if ($norm === '') {
        return ['label' => 'unknown', 'score' => 0.0, 'confidence' => 0.0, 'hits' => [], 'reason' => 'No text'];
    }

    $weights   = [];
    $hits      = [];
    $ambiguous = false;

    /* Emoji first — they survive normalization and never collide with words. */
    foreach (sent_emoji() as $glyph => $w) {
        $n = mb_substr_count($text, $glyph);
        if ($n === 0) continue;
        if ($w === 0.0) { $ambiguous = true; continue; }
        for ($i = 0; $i < min($n, 3); $i++) { $weights[] = $w; }
        $hits[] = ['term' => $glyph, 'w' => $w, 'negated' => false];
    }

    foreach ($lex['ambiguous'] as $marker) {
        if ($marker !== '' && str_contains($norm, $marker)) { $ambiguous = true; break; }
    }

    /* Multi-word phrases before single tokens, consuming their match so that
       "خدمه سيئه" is not also counted as a bare "سيئه". */
    $working = $norm;
    $phrases = [];
    foreach ($lex['terms'] as $term => $w) {
        if (!str_contains($term, ' ')) continue;
        $phrases[$term] = $w;
    }
    uksort($phrases, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
    foreach ($phrases as $term => $w) {
        if (!str_contains($working, $term)) continue;
        $negated = sent_phrase_negated($working, $term, $lex['negators']);
        $applied = $negated ? -$w * 0.75 : $w;
        $weights[] = $applied;
        $hits[]    = ['term' => $term, 'w' => $applied, 'negated' => $negated];
        $working   = str_replace($term, ' ', $working);
    }

    /* Single tokens, with a 3-token negation lookback and a 2-token modifier lookback. */
    $tokens = preg_split('/[^\p{L}\p{N}_]+/u', $working, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $tok = $tokens[$i];
        $w   = $lex['terms'][$tok] ?? null;

        // Egyptian ما...ش circumfix: the negation is fused into the word
        // ("ماحبتهوش"), so a lookback for a separate negator cannot see it.
        $fused = false;
        if ($w === null && $lang === 'ar') {
            $stem = sent_strip_circumfix($tok);
            if ($stem !== null) {
                $fused = true;
                $w = $lex['terms'][$stem] ?? null;
                if ($w === null) {
                    // Unknown stem inside a negated construction: a weak negative
                    // lean, flagged ambiguous so the model gets the final say.
                    $w = -0.35;
                    $ambiguous = true;
                }
            }
        }
        if ($w === null) continue;

        $negated = $fused;
        if (!$fused) {
            for ($b = max(0, $i - 3); $b < $i; $b++) {
                if (in_array($tokens[$b], $lex['negators'], true)) { $negated = true; break; }
            }
        }

        $factor = 1.0;
        for ($b = max(0, $i - 2); $b < $i; $b++) {
            if (isset($lex['intensifiers'][$tokens[$b]])) $factor = $lex['intensifiers'][$tokens[$b]];
            if (isset($lex['diminishers'][$tokens[$b]]))  $factor = $lex['diminishers'][$tokens[$b]];
        }

        // Negation softens as well as flips: "not great" is not "terrible".
        $applied = $negated ? -$w * 0.75 * $factor : $w * $factor;
        $weights[] = $applied;
        $hits[]    = ['term' => $tok, 'w' => $applied, 'negated' => $negated];
    }

    if (!$weights) {
        return ['label' => 'neutral', 'score' => 0.0, 'confidence' => 0.0,
                'hits' => [], 'reason' => 'No sentiment terms found'];
    }

    $sum   = array_sum($weights);
    $mass  = array_sum(array_map('abs', $weights));
    $n     = count($weights);

    // Soft squash into (-1,1): a pile of mild hits should not outrank one strong one.
    $score = $sum / (abs($sum) + 1.5);

    $agreement = $mass > 0 ? abs($sum) / $mass : 0.0;   // 1.0 = every hit agrees
    $density   = min(1.0, $n / 3.0);
    $confidence = 0.35 * $agreement + 0.35 * $density + 0.30 * min(1.0, abs($score) * 1.6);

    if ($ambiguous)                      $confidence *= 0.60;   // sarcasm risk
    if ($count < 4 && $n < 2)            $confidence *= 0.70;   // one-word posts
    if ($agreement < 0.6 && $n >= 2)     $confidence *= 0.75;   // mixed opinion

    $label = $score >= SENT_LABEL_THRESHOLD ? 'positive'
           : ($score <= -SENT_LABEL_THRESHOLD ? 'negative' : 'neutral');

    $top = array_slice(array_map(function ($h) { return $h['term']; }, $hits), 0, 4);

    return [
        'label'      => $label,
        'score'      => round($score, 3),
        'confidence' => round(max(0.0, min(1.0, $confidence)), 3),
        'hits'       => $hits,
        'reason'     => 'Matched: ' . implode(', ', $top),
    ];
}

/**
 * Strip the Egyptian ما...ش negation circumfix, returning the inner stem.
 * "ماحبتهوش" → "حبتهو"; returns null when the token is not of that shape.
 */
function sent_strip_circumfix(string $token): ?string
{
    if (!preg_match('/^(ما|م)(.{2,})ش$/u', $token, $m)) return null;
    $stem = $m[2];
    if (mb_strlen($stem) < 2) return null;

    $lex = sent_lexicon('ar');
    if (isset($lex['terms'][$stem])) return $stem;

    // Peel off attached object pronouns (ماحبتهوش → حبته → حبت → حب).
    // Note rtrim() is byte-based and would corrupt UTF-8 here, so the suffixes
    // are removed one at a time with mb_substr().
    $suffixes = ['هم', 'ها', 'هو', 'كم', 'نا', 'ني', 'ه', 'و', 'ك', 'ت'];
    $cand = $stem;
    for ($i = 0; $i < 4 && mb_strlen($cand) > 2; $i++) {
        $trimmed = null;
        foreach ($suffixes as $sfx) {
            if (mb_substr($cand, -mb_strlen($sfx)) === $sfx) {
                $trimmed = mb_substr($cand, 0, mb_strlen($cand) - mb_strlen($sfx));
                break;
            }
        }
        if ($trimmed === null || $trimmed === '') break;
        $cand = $trimmed;
        if (isset($lex['terms'][$cand])) return $cand;
    }

    // Last resort: a known term the stem begins with, e.g. "عجبنيش" → "عجبني".
    foreach ($lex['terms'] as $term => $w) {
        if (mb_strlen($term) >= 3 && str_starts_with($stem, $term)) return $term;
    }

    return $stem;
}

/** Is a matched phrase preceded by a negator within a few words? */
function sent_phrase_negated(string $haystack, string $phrase, array $negators): bool
{
    $pos = mb_strpos($haystack, $phrase);
    if ($pos === false || $pos === 0) return false;
    $before = mb_substr($haystack, max(0, $pos - 30), min(30, $pos));
    $words  = preg_split('/[^\p{L}\p{N}_]+/u', $before, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach (array_slice($words, -3) as $w) {
        if (in_array($w, $negators, true)) return true;
    }
    return false;
}

/* ── writing results back ──────────────────────────────────────────────── */

/**
 * Apply a classification. The `is_manual = 0` guard is the whole contract:
 * a human's decision is never overwritten by the engine.
 */
function sent_apply(int $mentionId, string $label, float $score, float $confidence,
                    string $method, string $reason, ?array $topics = null, string $state = 'done'): bool
{
    $n = db_run(
        "UPDATE mentions
            SET sentiment = ?, sentiment_score = ?, sentiment_confidence = ?,
                sentiment_method = ?, sentiment_reason = ?, sentiment_at = NOW(),
                topics_json = ?, classify_state = ?
          WHERE id = ? AND is_manual = 0",
        [
            $label, round($score, 3), round($confidence, 3), $method,
            mb_substr($reason, 0, 300),
            $topics ? json_encode(array_values($topics), JSON_UNESCAPED_UNICODE) : null,
            $state, $mentionId,
        ]
    );
    return $n > 0;
}

/**
 * Run the lexicon over a batch of pending mentions.
 * Escalates the unsure ones to `needs_ai` — unless the client cannot use AI, in
 * which case the lexicon's best guess stands rather than leaving it unclassified.
 */
function sent_classify_lexicon(int $limit = 300): int
{
    $rows = db_all(
        "SELECT m.id, m.client_id, m.title, m.content, m.lang,
                c.ai_provider, c.ai_api_key_enc
           FROM mentions m
           JOIN clients c ON c.id = m.client_id
          WHERE m.classify_state = 'pending' AND m.is_manual = 0
          ORDER BY m.id
          LIMIT " . (int) max(1, $limit)
    );

    $done = 0;
    foreach ($rows as $row) {
        $text = trim((string) $row['title'] . "\n" . (string) $row['content']);
        $res  = sent_lexicon_score($text, (string) $row['lang']);

        $aiAvailable = (string) $row['ai_provider'] !== '' && (string) ($row['ai_api_key_enc'] ?? '') !== '';
        $escalate    = $res['confidence'] < SENT_MIN_CONFIDENCE;

        if ($escalate && $aiAvailable) {
            // Keep the provisional label visible while it waits for the model.
            sent_apply((int) $row['id'], $res['label'] === 'unknown' ? 'neutral' : $res['label'],
                (float) $res['score'], (float) $res['confidence'], 'lexicon',
                $res['reason'], null, 'needs_ai');
        } else {
            $reason = $res['reason'];
            if ($escalate) $reason .= ' (lexicon only — no AI key configured)';
            sent_apply((int) $row['id'], $res['label'] === 'unknown' ? 'neutral' : $res['label'],
                (float) $res['score'], (float) $res['confidence'], 'lexicon', $reason, null, 'done');
        }
        $done++;
    }
    return $done;
}

/* ── the AI pass ───────────────────────────────────────────────────────── */

/**
 * Drain the `needs_ai` queue, grouped by client and batched to keep cost down.
 * Respects each client's daily cap; degrades to the lexicon verdict on any error.
 */
function sent_classify_ai(int $limit = 40, int $batchSize = 10, ?int $deadline = null): int
{
    $rows = db_all(
        "SELECT m.id, m.client_id, m.title, m.content, m.keyword_id, m.ai_attempts
           FROM mentions m
           JOIN clients c ON c.id = m.client_id
          WHERE m.classify_state = 'needs_ai'
            AND m.is_manual = 0
            AND m.ai_attempts < " . SENT_AI_MAX_ATTEMPTS . "
            AND c.ai_provider <> ''
            AND c.status = 'active'
          ORDER BY m.client_id, m.id
          LIMIT " . (int) max(1, $limit)
    );
    if (!$rows) return 0;

    $byClient = [];
    foreach ($rows as $r) { $byClient[(int) $r['client_id']][] = $r; }

    $done = 0;
    foreach ($byClient as $clientId => $mentions) {
        if ($deadline !== null && time() >= $deadline) break;

        $client = db_row("SELECT * FROM clients WHERE id = ?", [$clientId]);
        if (!$client || !ai_configured($client)) continue;

        $budget = sent_ai_budget_left($client);
        if ($budget <= 0) continue;

        foreach (array_chunk($mentions, max(1, $batchSize)) as $chunk) {
            if ($budget <= 0) break;
            if ($deadline !== null && time() >= $deadline) break;

            $results = sent_ai_batch($client, $chunk);
            sent_ai_count($clientId, 1);
            $budget--;

            foreach ($chunk as $m) {
                $mid = (int) $m['id'];
                $r   = $results[$mid] ?? null;

                if (!$r) {
                    // No usable answer: burn an attempt and, once spent, let the
                    // lexicon verdict already on the row stand as final.
                    $attempts = (int) $m['ai_attempts'] + 1;
                    db_run(
                        "UPDATE mentions
                            SET ai_attempts = ?, classify_state = ?
                          WHERE id = ? AND is_manual = 0",
                        [$attempts, $attempts >= SENT_AI_MAX_ATTEMPTS ? 'done' : 'needs_ai', $mid]
                    );
                    continue;
                }

                sent_apply($mid, $r['sentiment'], $r['score'], $r['confidence'], 'ai',
                    $r['reason'], $r['topics'], 'done');
                $done++;
            }
        }
    }
    return $done;
}

/** How many more AI calls this client may make today. */
function sent_ai_budget_left(array $client): int
{
    $cap = (int) ($client['ai_daily_cap'] ?? 0);
    if ($cap <= 0) return 0;
    $today = date('Y-m-d');
    $used  = ((string) ($client['ai_calls_day'] ?? '') === $today) ? (int) $client['ai_calls_today'] : 0;
    return max(0, $cap - $used);
}

/** Increment today's AI call counter, rolling it over at midnight. */
function sent_ai_count(int $clientId, int $n = 1): void
{
    db_run(
        "UPDATE clients
            SET ai_calls_today = CASE WHEN ai_calls_day = CURDATE() THEN ai_calls_today + ? ELSE ? END,
                ai_calls_day = CURDATE()
          WHERE id = ?",
        [$n, $n, $clientId]
    );
}

/**
 * One AI call for a batch of mentions.
 * @return array<int,array{sentiment:string,score:float,confidence:float,reason:string,topics:array}>
 */
function sent_ai_batch(array $client, array $mentions): array
{
    $brandTerms = db_all(
        "SELECT term FROM keywords WHERE client_id = ? AND kind = 'brand' AND status = 'active' LIMIT 5",
        [(int) $client['id']]
    );
    $brand = (string) ($client['brand_name'] ?: $client['name']);
    if ($brandTerms) {
        $brand .= ' (' . implode(', ', array_column($brandTerms, 'term')) . ')';
    }

    $system = "You are a brand-monitoring sentiment analyst for an Egyptian marketing agency.\n"
        . "Classify each numbered item by how it reflects on THE BRAND named by the user — not by the "
        . "general mood of the text. Egyptian Arabic dialect, Modern Standard Arabic and English all "
        . "appear, and sarcasm is common.\n"
        . "Rules: a factual news headline that merely names the brand is \"neutral\". Criticism of a "
        . "competitor is not negative for our brand. A complaint about price alone is negative only if "
        . "the writer is unhappy.\n"
        . "Reply with JSON only, no prose:\n"
        . '{"results":[{"i":1,"sentiment":"positive|negative|neutral","score":-1..1,'
        . '"confidence":0..1,"topics":["price","service"],"reason":"max 15 words"}]}';

    $lines = ["BRAND: {$brand}", ''];
    $index = [];
    $i     = 0;
    foreach ($mentions as $m) {
        $i++;
        $index[$i] = (int) $m['id'];
        $title = trim((string) $m['title']);
        $body  = trim((string) $m['content']);
        $lines[] = "--- {$i} ---";
        if ($title !== '') $lines[] = $title;
        if ($body !== '')  $lines[] = mb_substr($body, 0, 800);
        $lines[] = '';
    }

    $resp = ai_complete($client, $system, [['role' => 'user', 'content' => implode("\n", $lines)]], 1200);
    if (!$resp['ok']) {
        error_log('sentiment AI call failed: ' . $resp['error']);
        return [];
    }

    $json = ai_extract_json($resp['text']);
    if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) {
        error_log('sentiment AI returned unparseable JSON');
        return [];
    }

    $valid = ['positive', 'negative', 'neutral'];
    $out   = [];
    foreach ($json['results'] as $r) {
        $n = (int) ($r['i'] ?? 0);
        if (!isset($index[$n])) continue;

        $label = strtolower(trim((string) ($r['sentiment'] ?? '')));
        if (!in_array($label, $valid, true)) continue;

        $topics = [];
        foreach ((array) ($r['topics'] ?? []) as $tp) {
            $tp = trim((string) $tp);
            if ($tp !== '') $topics[] = mb_substr($tp, 0, 40);
        }

        $out[$index[$n]] = [
            'sentiment'  => $label,
            'score'      => max(-1.0, min(1.0, (float) ($r['score'] ?? 0))),
            'confidence' => max(0.0, min(1.0, (float) ($r['confidence'] ?? 0.8))),
            'reason'     => mb_substr(trim((string) ($r['reason'] ?? '')), 0, 300),
            'topics'     => array_slice($topics, 0, 6),
        ];
    }
    return $out;
}
