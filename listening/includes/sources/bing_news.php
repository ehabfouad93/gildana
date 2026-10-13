<?php
declare(strict_types=1);

/**
 * Bing News RSS search.
 *
 * https://www.bing.com/news/search?q=…&format=RSS&cc=EG
 *
 * Unlike Google News, Bing returns the publisher's real article URL, so its
 * mentions dedupe correctly against RSS and aggregator results.
 */
function src_bing_news_fetch(array $client, array $source, array $keyword): array
{
    $cfg = source_config($source);
    $cc  = strtoupper((string) ($cfg['cc'] ?? 'EG'));

    $q = keyword_query($keyword);
    if ($q === '') return ingest_envelope_error('This source has no keyword to search for.');

    $url = 'https://www.bing.com/news/search?q=' . urlencode($q)
         . '&format=RSS&cc=' . urlencode($cc);

    $r = lh_http('GET', $url, [], null, [
        'timeout'       => 25,
        'etag'          => (string) ($source['etag'] ?? ''),
        'last_modified' => (string) ($source['last_modified'] ?? ''),
        'fixture'       => 'bing_news.xml',
    ]);

    $env = ingest_envelope_from_http($r, $url);
    if ($env !== null) return $env;

    $parsed = lh_parse_feed($r['raw']);
    if (!$parsed['ok']) {
        return ingest_envelope_error($parsed['error'], ['http' => $r['http'], 'request_url' => $url]);
    }

    $items = [];
    foreach ($parsed['items'] as $it) {
        $items[] = [
            'external_id'  => (string) $it['external_id'],
            'url'          => (string) $it['url'],
            'title'        => (string) $it['title'],
            'content'      => (string) $it['content'],
            'author_name'  => (string) ($it['source_name'] ?: $it['author_name']),
            'domain'       => (string) (parse_url((string) $it['url'], PHP_URL_HOST) ?: ''),
            'published_at' => $it['published_at'],
            'country'      => $cc,
            'platform'     => 'news',
        ];
    }

    return ingest_envelope_ok($items, ['http' => $r['http'], 'request_url' => $url,
                                       'etag' => (string) ($r['headers']['etag'] ?? ''),
                                       'last_modified' => (string) ($r['headers']['last-modified'] ?? '')]);
}
