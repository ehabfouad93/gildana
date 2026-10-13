<?php
declare(strict_types=1);

/**
 * SerpApi — the paid aggregator, and the reliable fallback when a free source
 * starts failing. Every call spends a search credit, so the API key is stored
 * per client and the spend is recorded in fetch_runs.billable.
 */
function src_serpapi_fetch(array $client, array $source, array $keyword): array
{
    $key = decrypt_secret((string) ($client['aggregator_key_enc'] ?? ''));
    if ($key === '') return ingest_envelope_error('No aggregator API key is saved for this client.');

    $cfg    = source_config($source);
    $engine = (string) ($cfg['engine'] ?? 'google_news');

    $q = keyword_query($keyword);
    if ($q === '') return ingest_envelope_error('This source has no keyword to search for.');

    $lang    = (string) ($client['default_lang'] ?? 'ar');
    if ($lang === 'both') $lang = 'ar';
    $country = strtolower((string) ($client['default_country'] ?? 'eg'));

    $url = 'https://serpapi.com/search.json?' . http_build_query([
        'engine'  => $engine,
        'q'       => $q,
        'hl'      => $lang,
        'gl'      => $country,
        'api_key' => $key,
    ]);
    $safeUrl = str_replace(urlencode($key), '***', $url);

    $r = lh_http_json('GET', $url, [], null, ['timeout' => 40, 'fixture' => 'serpapi.json']);

    $env = ingest_envelope_from_http($r, $safeUrl);
    if ($env !== null) { $env['billable'] = 1; return $env; }

    // google_news returns news_results; google returns organic_results.
    $rows = $r['json']['news_results'] ?? $r['json']['organic_results'] ?? [];
    if (!is_array($rows)) $rows = [];

    $items = [];
    foreach ($rows as $row) {
        // Some engines nest a "stories" array under one topic entry.
        $stories = isset($row['stories']) && is_array($row['stories']) ? $row['stories'] : [$row];
        foreach ($stories as $s) {
            $link = (string) ($s['link'] ?? '');
            if ($link === '') continue;
            $items[] = [
                'external_id'  => (string) ($s['position'] ?? '') . ':' . $link,
                'url'          => $link,
                'title'        => (string) ($s['title'] ?? ''),
                'content'      => (string) ($s['snippet'] ?? ''),
                'author_name'  => (string) ($s['source']['name'] ?? $s['source'] ?? ''),
                'image_url'    => (string) ($s['thumbnail'] ?? ''),
                'domain'       => (string) (parse_url($link, PHP_URL_HOST) ?: ''),
                'published_at' => to_utc((string) ($s['date'] ?? '')),
                'country'      => strtoupper($country),
                'platform'     => 'news',
            ];
        }
    }

    return ingest_envelope_ok($items, ['http' => $r['http'], 'request_url' => $safeUrl, 'billable' => 1]);
}
