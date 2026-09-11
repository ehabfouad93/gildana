<?php
declare(strict_types=1);

/**
 * Any RSS 2.0 / Atom / RDF feed the client wants followed.
 *
 * This connector is not keyword-bound: it pulls the whole feed and lets
 * ingest_store() decide which items actually mention a tracked term. That keeps
 * one feed one HTTP request no matter how many keywords are being tracked.
 */
function src_rss_fetch(array $client, array $source, array $keyword): array
{
    $cfg = source_config($source);
    $url = trim((string) ($cfg['feed_url'] ?? ''));
    if ($url === '') return ingest_envelope_error('No feed URL is configured for this source.');
    if (!preg_match('#^https?://#i', $url)) {
        return ingest_envelope_error('The feed URL must start with http:// or https://');
    }

    $fetch = function (string $u) use ($source) {
        return lh_http('GET', $u, [], null, [
            'timeout'       => 25,
            'browser_ua'    => true,
            'etag'          => (string) ($source['etag'] ?? ''),
            'last_modified' => (string) ($source['last_modified'] ?? ''),
            'fixture'       => 'rss.xml',
        ]);
    };

    $r = $fetch($url);

    $env = ingest_envelope_from_http($r, $url);
    if ($env !== null) return $env;

    $parsed = lh_parse_feed($r['raw']);

    // Given a site's ordinary address rather than its feed — which is what people
    // naturally paste — ask the page where its feed is and use that instead of
    // failing. The resolved address is handed back so the caller can store it and
    // skip the extra request next time.
    $resolved = '';
    if (!$parsed['ok']) {
        $found = lh_discover_feed($url);
        if ($found['ok']) {
            $resolved = $found['url'];
            $r        = $fetch($resolved);
            $env      = ingest_envelope_from_http($r, $resolved);
            if ($env !== null) return $env;
            $parsed = lh_parse_feed($r['raw']);
        }
    }

    if (!$parsed['ok']) {
        return ingest_envelope_error($parsed['error'], ['http' => $r['http'], 'request_url' => $url]);
    }
    if ($resolved !== '') $url = $resolved;

    $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
    $items = [];
    foreach ($parsed['items'] as $it) {
        $items[] = [
            'external_id'  => (string) $it['external_id'],
            'url'          => (string) $it['url'],
            'title'        => (string) $it['title'],
            'content'      => (string) $it['content'],
            'author_name'  => (string) $it['author_name'],
            'domain'       => (string) (parse_url((string) $it['url'], PHP_URL_HOST) ?: $host),
            'published_at' => $it['published_at'],
            'platform'     => 'blog',
        ];
    }

    return ingest_envelope_ok($items, ['http' => $r['http'], 'request_url' => $url,
                                       'resolved_feed_url' => $resolved,
                                       'etag' => (string) ($r['headers']['etag'] ?? ''),
                                       'last_modified' => (string) ($r['headers']['last-modified'] ?? '')]);
}
