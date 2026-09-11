<?php
declare(strict_types=1);

/**
 * Google News RSS search.
 *
 * https://news.google.com/rss/search?q=…&hl=ar&gl=EG&ceid=EG:ar
 *
 * Note on URLs: `link` is a news.google.com/rss/articles/CBM… redirect, not the
 * publisher's URL. The real outlet only appears in <source url="…">, so that is
 * where `domain` and the author name come from.
 */
function src_google_news_fetch(array $client, array $source, array $keyword): array
{
    $cfg  = source_config($source);
    $hl   = (string) ($cfg['hl'] ?? 'ar');
    $gl   = strtoupper((string) ($cfg['gl'] ?? 'EG'));
    $when = (string) ($cfg['when'] ?? '1d');

    $q = keyword_query($keyword);
    if ($q === '') return ingest_envelope_error('This source has no keyword to search for.');
    if ($when !== '') $q .= ' when:' . $when;

    $url = 'https://news.google.com/rss/search?q=' . urlencode($q)
         . '&hl=' . urlencode($hl)
         . '&gl=' . urlencode($gl)
         . '&ceid=' . urlencode($gl . ':' . $hl);

    $r = lh_http('GET', $url, [], null, [
        'timeout'       => 25,
        'browser_ua'    => true,
        'etag'          => (string) ($source['etag'] ?? ''),
        'last_modified' => (string) ($source['last_modified'] ?? ''),
        'fixture'       => 'google_news.xml',
    ]);

    $env = ingest_envelope_from_http($r, $url);
    if ($env !== null) return $env;

    $parsed = lh_parse_feed($r['raw']);
    if (!$parsed['ok']) {
        return ingest_envelope_error($parsed['error'], ['http' => $r['http'], 'request_url' => $url]);
    }

    $items = [];
    foreach ($parsed['items'] as $it) {
        $publisher = (string) ($it['source_name'] ?? '');
        $srcUrl    = (string) ($it['source_url'] ?? '');

        // Google appends " - Publisher" to every headline; drop it once we have
        // the publisher from <source>, otherwise every title ends in noise.
        $title = (string) $it['title'];
        if ($publisher !== '' && str_ends_with($title, ' - ' . $publisher)) {
            $title = substr($title, 0, -strlen(' - ' . $publisher));
        }

        $items[] = [
            'external_id'  => (string) $it['external_id'],
            'url'          => (string) $it['url'],
            'title'        => trim($title),
            'content'      => (string) $it['content'],
            'author_name'  => $publisher,
            'domain'       => $srcUrl !== '' ? (string) (parse_url($srcUrl, PHP_URL_HOST) ?: '') : '',
            'published_at' => $it['published_at'],
            'lang'         => $hl,
            'country'      => $gl,
            'platform'     => 'news',
        ];
    }

    return ingest_envelope_ok($items, ['http' => $r['http'], 'request_url' => $url,
                                       'etag' => (string) ($r['headers']['etag'] ?? ''),
                                       'last_modified' => (string) ($r['headers']['last-modified'] ?? '')]);
}
