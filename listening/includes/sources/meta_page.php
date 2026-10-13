<?php
declare(strict_types=1);

/**
 * Facebook Page via the official Graph API.
 *
 * Covers exactly two things, and it is worth being precise about the limit:
 * posts that TAG the page (/{page-id}/tagged) and comments left on the page's
 * own posts. The Graph API cannot search public Facebook — no product decision
 * changes that, so the Sources screen says so in plain language.
 */
function src_meta_page_fetch(array $client, array $source, array $keyword): array
{
    $token  = decrypt_secret((string) ($client['meta_token_enc'] ?? ''));
    $pageId = trim((string) ($client['meta_page_id'] ?? ''));
    if ($token === '' || $pageId === '') {
        return ingest_envelope_error('Facebook page ID or access token is not set for this client.');
    }

    $v     = (string) config('graph_version', 'v21.0');
    $items = [];
    $lastHttp = 0;
    $safeUrl  = '';

    /* ── 1. Posts that tagged the page ── */
    $taggedUrl = "https://graph.facebook.com/{$v}/" . rawurlencode($pageId) . '/tagged?' . http_build_query([
        'fields'       => 'id,message,story,created_time,permalink_url,from{id,name},likes.summary(true),comments.summary(true)',
        'limit'        => 50,
        'access_token' => $token,
    ]);
    $safeUrl = str_replace(urlencode($token), '***', $taggedUrl);

    $r = lh_http_json('GET', $taggedUrl, [], null, ['timeout' => 30, 'fixture' => 'meta_page_tagged.json']);
    $lastHttp = (int) $r['http'];

    $fail = meta_graph_failure($r, $safeUrl);
    if ($fail !== null) return $fail;

    foreach (($r['json']['data'] ?? []) as $post) {
        $msg = (string) ($post['message'] ?? $post['story'] ?? '');
        if (trim($msg) === '') continue;
        $items[] = [
            'external_id'   => (string) ($post['id'] ?? ''),
            'url'           => (string) ($post['permalink_url'] ?? ''),
            'title'         => '',
            'content'       => $msg,
            'author_name'   => (string) ($post['from']['name'] ?? ''),
            'author_handle' => (string) ($post['from']['id'] ?? ''),
            'domain'        => 'facebook.com',
            'published_at'  => to_utc((string) ($post['created_time'] ?? '')),
            'platform'      => 'facebook',
            'metrics'       => [
                'likes'    => (int) ($post['likes']['summary']['total_count'] ?? 0),
                'comments' => (int) ($post['comments']['summary']['total_count'] ?? 0),
            ],
        ];
    }

    /* ── 2. Comments on the page's own recent posts ── */
    $feedUrl = "https://graph.facebook.com/{$v}/" . rawurlencode($pageId) . '/feed?' . http_build_query([
        'fields'       => 'id,permalink_url,created_time,comments.limit(25){id,message,created_time,from{id,name},like_count,permalink_url}',
        'limit'        => 10,
        'access_token' => $token,
    ]);
    $rf = lh_http_json('GET', $feedUrl, [], null, ['timeout' => 30, 'fixture' => 'meta_page_feed.json']);

    if ($rf['error'] === '' && is_array($rf['json'] ?? null)) {
        foreach (($rf['json']['data'] ?? []) as $post) {
            foreach (($post['comments']['data'] ?? []) as $c) {
                $msg = trim((string) ($c['message'] ?? ''));
                if ($msg === '') continue;
                $items[] = [
                    'external_id'   => (string) ($c['id'] ?? ''),
                    'url'           => (string) ($c['permalink_url'] ?? $post['permalink_url'] ?? ''),
                    'title'         => '',
                    'content'       => $msg,
                    'author_name'   => (string) ($c['from']['name'] ?? ''),
                    'author_handle' => (string) ($c['from']['id'] ?? ''),
                    'domain'        => 'facebook.com',
                    'published_at'  => to_utc((string) ($c['created_time'] ?? '')),
                    'platform'      => 'facebook',
                    'metrics'       => ['likes' => (int) ($c['like_count'] ?? 0)],
                ];
            }
        }
    }

    return ingest_envelope_ok($items, ['http' => $lastHttp, 'request_url' => $safeUrl]);
}

/**
 * Map a Graph error to an envelope, or null when the call was fine.
 * Shared by both Meta connectors.
 */
function meta_graph_failure(array $r, string $safeUrl): ?array
{
    if ($r['error'] === '' && is_array($r['json'] ?? null)) return null;

    $code = (int) ($r['json']['error']['code'] ?? 0);
    $msg  = (string) ($r['json']['error']['message'] ?? $r['error'] ?: 'Graph API error');

    // 190 = token expired or revoked. Not transient: the client must re-connect.
    if ($code === 190) {
        return ingest_envelope_error('The Meta access token has expired — reconnect it in Settings.',
            ['http' => (int) $r['http'], 'request_url' => $safeUrl]);
    }
    // 4/17/32/613 are Meta's rate-limit family.
    if (in_array($code, [4, 17, 32, 613], true)) {
        return ingest_envelope_error('Meta rate limit reached — backing off.',
            ['http' => (int) $r['http'], 'request_url' => $safeUrl, 'retry_after' => 900, 'throttled' => true]);
    }
    return ingest_envelope_error($msg, ['http' => (int) $r['http'], 'request_url' => $safeUrl]);
}
