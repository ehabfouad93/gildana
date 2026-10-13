<?php
declare(strict_types=1);

/**
 * Reddit public search JSON.
 *
 * https://www.reddit.com/search.json?q=…&sort=new&t=week
 *
 * Reddit refuses requests that arrive with a default or missing User-Agent —
 * lh_http() always sends config('user_agent'), which is why this works at all.
 * Anonymous access is rate limited; on a 429 we surface retry_after so the
 * scheduler backs the source off instead of hammering it.
 */
function src_reddit_fetch(array $client, array $source, array $keyword): array
{
    $cfg = source_config($source);
    $sub = trim((string) ($cfg['subreddit'] ?? ''), " \t/r");
    $t   = (string) ($cfg['t'] ?? 'week');

    $q = keyword_query($keyword);
    if ($q === '') return ingest_envelope_error('This source has no keyword to search for.');

    $params = ['q' => $q, 'sort' => 'new', 'limit' => '50', 't' => $t, 'raw_json' => '1'];
    if ($sub !== '') {
        $params['restrict_sr'] = '1';
        $url = 'https://www.reddit.com/r/' . rawurlencode($sub) . '/search.json?' . http_build_query($params);
    } else {
        $url = 'https://www.reddit.com/search.json?' . http_build_query($params);
    }

    $r = lh_http_json('GET', $url, [], null, ['timeout' => 25, 'fixture' => 'reddit.json']);

    $env = ingest_envelope_from_http($r, $url);
    if ($env !== null) return $env;

    $children = $r['json']['data']['children'] ?? null;
    if (!is_array($children)) {
        return ingest_envelope_error('Unexpected response from Reddit.', ['http' => $r['http'], 'request_url' => $url]);
    }

    $items = [];
    foreach ($children as $child) {
        $d = $child['data'] ?? null;
        if (!is_array($d)) continue;

        $permalink = (string) ($d['permalink'] ?? '');
        $score     = (int) ($d['score'] ?? 0);
        $comments  = (int) ($d['num_comments'] ?? 0);

        $items[] = [
            'external_id'    => (string) ($d['name'] ?? $d['id'] ?? ''),
            'url'            => $permalink !== '' ? 'https://www.reddit.com' . $permalink : (string) ($d['url'] ?? ''),
            'title'          => (string) ($d['title'] ?? ''),
            'content'        => (string) ($d['selftext'] ?? ''),
            'author_name'    => (string) ($d['author'] ?? ''),
            'author_handle'  => 'u/' . (string) ($d['author'] ?? ''),
            'author_url'     => 'https://www.reddit.com/user/' . rawurlencode((string) ($d['author'] ?? '')),
            'domain'         => 'r/' . (string) ($d['subreddit'] ?? ''),
            'published_at'   => !empty($d['created_utc'])
                                ? gmdate('Y-m-d H:i:s', (int) $d['created_utc'])
                                : null,
            'platform'       => 'reddit',
            'metrics'        => [
                'likes'    => max(0, $score),
                'comments' => $comments,
                // Score is the only audience proxy Reddit gives anonymously.
                'reach'    => max(0, $score) * 10,
            ],
        ];
    }

    // Back off pre-emptively when we are near the anonymous rate limit.
    $remaining = $r['headers']['x-ratelimit-remaining'] ?? null;
    $retry     = 0;
    if ($remaining !== null && (float) $remaining < 5) {
        $retry = (int) ($r['headers']['x-ratelimit-reset'] ?? 60);
    }

    return ingest_envelope_ok($items, [
        'http' => $r['http'], 'request_url' => $url, 'retry_after' => $retry,
    ]);
}
