<?php
declare(strict_types=1);

/**
 * Turning a tracked keyword into a search query, and deciding whether a fetched
 * item really matches it.
 *
 * Both halves matter: the query narrows what a source returns, and the local
 * match filters what the query over-returns (news search engines are generous
 * with "related" results).
 */

/**
 * Build the query string sent to a search-style connector.
 * $style 'quoted' wraps phrases and appends exclusions with a minus, which is
 * the syntax Google/Bing/SerpApi share; 'plain' is for APIs that take raw text.
 */
function keyword_query(array $keyword, string $style = 'quoted'): string
{
    $term = trim((string) ($keyword['term'] ?? ''));
    if ($term === '') return '';
    if ($style === 'plain') return $term;

    $q = (string) ($keyword['match_mode'] ?? 'phrase') === 'all_words'
        ? $term
        : '"' . str_replace('"', '', $term) . '"';

    foreach (keyword_list($keyword, 'required_json') as $w) {
        $q .= ' "' . str_replace('"', '', $w) . '"';
    }
    foreach (keyword_list($keyword, 'excluded_json') as $w) {
        $q .= ' -"' . str_replace('"', '', $w) . '"';
    }
    return trim($q);
}

/** Decode one of the JSON list columns into a clean array of strings. */
function keyword_list(array $keyword, string $column): array
{
    $raw = json_decode((string) ($keyword[$column] ?? ''), true);
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
        $v = trim((string) $v);
        if ($v !== '') $out[] = $v;
    }
    return $out;
}

/** Turn a textarea of one-per-line words into the JSON stored in the column. */
function keyword_list_encode(string $textarea): ?string
{
    $lines = preg_split('/\R/u', $textarea) ?: [];
    $out   = [];
    foreach ($lines as $l) {
        $l = trim($l);
        if ($l !== '') $out[] = $l;
    }
    return $out ? json_encode(array_values(array_unique($out)), JSON_UNESCAPED_UNICODE) : null;
}

/**
 * Does this fetched item actually mention the keyword?
 *
 * Arabic is normalized on both sides first, so "جيلدانا" matches "جِيلدانا"
 * and "الجيلدانا" alike. Latin comparison is case-insensitive.
 */
function keyword_matches(array $keyword, string $title, string $content): bool
{
    $hay = sent_normalize(($title . ' ' . $content));
    if ($hay === '') return false;

    $term = sent_normalize((string) ($keyword['term'] ?? ''));
    if ($term === '') return false;

    $mode = (string) ($keyword['match_mode'] ?? 'phrase');
    $hit  = false;

    if ($mode === 'all_words') {
        $hit = true;
        foreach (preg_split('/\s+/u', $term) ?: [] as $w) {
            if ($w !== '' && !str_contains($hay, $w)) { $hit = false; break; }
        }
    } elseif ($mode === 'exact') {
        // Whole word only — stops "AMER" matching inside "camera".
        $hit = (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u', $hay);
    } else {
        $hit = str_contains($hay, $term);
    }
    if (!$hit) return false;

    // Required words: at least one must appear.
    $required = keyword_list($keyword, 'required_json');
    if ($required) {
        $any = false;
        foreach ($required as $w) {
            if (str_contains($hay, sent_normalize($w))) { $any = true; break; }
        }
        if (!$any) return false;
    }

    // Excluded words: any hit drops the item.
    foreach (keyword_list($keyword, 'excluded_json') as $w) {
        if (str_contains($hay, sent_normalize($w))) return false;
    }

    return true;
}

/**
 * Wrap keyword hits in <mark> for the feed. Operates on already-escaped HTML,
 * so it only ever inserts markup of its own.
 */
function keyword_highlight(string $escapedHtml, array $terms): string
{
    foreach ($terms as $term) {
        $term = trim((string) $term);
        if ($term === '' || mb_strlen($term) < 2) continue;
        $escapedHtml = preg_replace(
            '/(' . preg_quote(e($term), '/') . ')/iu',
            '<mark>$1</mark>',
            $escapedHtml,
            3   // a few highlights help; highlighting every occurrence is noise
        ) ?? $escapedHtml;
    }
    return $escapedHtml;
}

/** Active keywords for a client, newest brand terms first. */
function keywords_active(int $clientId): array
{
    return db_all(
        "SELECT * FROM keywords WHERE client_id = ? AND status = 'active'
          ORDER BY FIELD(kind,'brand','competitor','hashtag','keyword'), id",
        [$clientId]
    );
}
