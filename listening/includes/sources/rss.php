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

    // The conditional-GET headers belong to the address they were collected from;
    // sending them on to a newly discovered feed would ask the wrong question.
    $fetch = function (string $u, bool $conditional = true) use ($source) {
        return lh_http('GET', $u, [], null, [
            'timeout'       => 25,
            'browser_ua'    => true,
            'etag'          => $conditional ? (string) ($source['etag'] ?? '') : '',
            'last_modified' => $conditional ? (string) ($source['last_modified'] ?? '') : '',
            'fixture'       => 'rss.xml',
        ]);
    };

    $r   = $fetch($url);
    $env = ingest_envelope_from_http($r, $url);

    // 304 and 429 mean what they say, and a DNS or TLS failure will fail the same
    // way on a second look. A 403/404/410 on a feed address is different: it is
    // what a publisher moving their feed looks like from here, so it is worth
    // asking the site where the feed went rather than failing every check from now
    // on. Same for a body that is not a feed — usually someone pasted a homepage.
    $moved = $env !== null && in_array((int) $r['http'], [403, 404, 410, 451], true);
    if ($env !== null && !$moved) return $env;

    $parsed = $moved
        ? ['ok' => false, 'error' => 'HTTP ' . (int) $r['http'], 'items' => []]
        : lh_parse_feed($r['raw']);

    // Searching a site costs up to nine requests, so a site that has already been
    // searched without success is left alone for a day rather than being swept
    // again on every check — a handful of dead sources would otherwise dominate
    // the worker's tick. Saving the source from the form rebuilds its config from
    // the declared fields, which drops this marker: editing means try again now.
    $lastSweep = (string) ($cfg['discovery_failed_at'] ?? '');
    $sweptToday = $lastSweep !== '' && strtotime($lastSweep . ' UTC') > time() - 86400;

    // The resolved address is handed back so the caller can store it and skip the
    // extra request next time.
    $resolved = '';
    $swept    = false;
    if (!$parsed['ok'] && !$sweptToday) {
        $swept = true;
        $found = lh_discover_feed($url);
        if ($found['ok']) {
            $resolved = $found['url'];
            $r        = $fetch($resolved, false);
            $env      = ingest_envelope_from_http($r, $resolved);
            if ($env !== null) return $env;
            $parsed = lh_parse_feed($r['raw']);
        }
    }

    if (!$parsed['ok']) {
        // Nothing found: report the original failure, which says more than the
        // discovery attempt does.
        $fail = $env ?? ingest_envelope_error($parsed['error'], ['http' => $r['http'], 'request_url' => $url]);
        if ($swept) $fail['config_patch'] = ['discovery_failed_at' => gmdate('Y-m-d H:i:s')];
        return $fail;
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

    // On a resolve, store the feed address and drop the failure marker: the search
    // that just worked should not be skipped if this feed ever moves again.
    $patch = $resolved !== '' ? ['feed_url' => $resolved, 'discovery_failed_at' => null] : [];

    return ingest_envelope_ok($items, ['http' => $r['http'], 'request_url' => $url,
                                       'config_patch' => $patch,
                                       'etag' => (string) ($r['headers']['etag'] ?? ''),
                                       'last_modified' => (string) ($r['headers']['last-modified'] ?? '')]);
}
