<?php
declare(strict_types=1);

/**
 * Instagram via the official Graph API.
 *
 * Same shape and the same hard limit as the Facebook connector: /{ig-user-id}/tags
 * returns media that tagged the account, and comments come from the account's
 * own media. Public Instagram cannot be searched through the Graph API.
 */
require_once __DIR__ . '/meta_page.php';   // meta_graph_failure()

function src_meta_ig_fetch(array $client, array $source, array $keyword): array
{
    $token = decrypt_secret((string) ($client['meta_token_enc'] ?? ''));
    $igId  = trim((string) ($client['meta_ig_user_id'] ?? ''));
    if ($token === '' || $igId === '') {
        return ingest_envelope_error('Instagram user ID or access token is not set for this client.');
    }

    $v     = (string) config('graph_version', 'v21.0');
    $items = [];

    /* ── 1. Media that tagged this account ── */
    $tagsUrl = "https://graph.facebook.com/{$v}/" . rawurlencode($igId) . '/tags?' . http_build_query([
        'fields'       => 'id,caption,media_url,permalink,timestamp,username,like_count,comments_count',
        'limit'        => 50,
        'access_token' => $token,
    ]);
    $safeUrl = str_replace(urlencode($token), '***', $tagsUrl);

    $r = lh_http_json('GET', $tagsUrl, [], null, ['timeout' => 30, 'fixture' => 'meta_ig_tags.json']);

    $fail = meta_graph_failure($r, $safeUrl);
    if ($fail !== null) return $fail;

    foreach (($r['json']['data'] ?? []) as $m) {
        $caption = trim((string) ($m['caption'] ?? ''));
        if ($caption === '') continue;
        $user = (string) ($m['username'] ?? '');
        $items[] = [
            'external_id'   => (string) ($m['id'] ?? ''),
            'url'           => (string) ($m['permalink'] ?? ''),
            'title'         => '',
            'content'       => $caption,
            'author_name'   => $user,
            'author_handle' => $user !== '' ? '@' . $user : '',
            'author_url'    => $user !== '' ? 'https://www.instagram.com/' . rawurlencode($user) : '',
            'image_url'     => (string) ($m['media_url'] ?? ''),
            'domain'        => 'instagram.com',
            'published_at'  => to_utc((string) ($m['timestamp'] ?? '')),
            'platform'      => 'instagram',
            'metrics'       => [
                'likes'    => (int) ($m['like_count'] ?? 0),
                'comments' => (int) ($m['comments_count'] ?? 0),
            ],
        ];
    }

    /* ── 2. Comments on this account's own recent media ── */
    $mediaUrl = "https://graph.facebook.com/{$v}/" . rawurlencode($igId) . '/media?' . http_build_query([
        'fields'       => 'id,permalink,comments.limit(25){id,text,timestamp,username,like_count}',
        'limit'        => 10,
        'access_token' => $token,
    ]);
    $rm = lh_http_json('GET', $mediaUrl, [], null, ['timeout' => 30, 'fixture' => 'meta_ig_media.json']);

    if ($rm['error'] === '' && is_array($rm['json'] ?? null)) {
        foreach (($rm['json']['data'] ?? []) as $media) {
            foreach (($media['comments']['data'] ?? []) as $c) {
                $text = trim((string) ($c['text'] ?? ''));
                if ($text === '') continue;
                $user = (string) ($c['username'] ?? '');
                $items[] = [
                    'external_id'   => (string) ($c['id'] ?? ''),
                    'url'           => (string) ($media['permalink'] ?? ''),
                    'title'         => '',
                    'content'       => $text,
                    'author_name'   => $user,
                    'author_handle' => $user !== '' ? '@' . $user : '',
                    'author_url'    => $user !== '' ? 'https://www.instagram.com/' . rawurlencode($user) : '',
                    'domain'        => 'instagram.com',
                    'published_at'  => to_utc((string) ($c['timestamp'] ?? '')),
                    'platform'      => 'instagram',
                    'metrics'       => ['likes' => (int) ($c['like_count'] ?? 0)],
                ];
            }
        }
    }

    return ingest_envelope_ok($items, ['http' => (int) $r['http'], 'request_url' => $safeUrl]);
}
