<?php
declare(strict_types=1);

/**
 * YouTube Data API v3.
 *
 * Two calls: search.list for the videos, then videos.list for real statistics
 * (search results carry no view or like counts).
 *
 * Quota is the thing to watch: search.list costs 100 units of a 10,000/day
 * default, so roughly 100 searches per key per day. The registry enforces a
 * 60-minute minimum interval, the key is per client so the quota is theirs,
 * and a quotaExceeded response backs off until the next Pacific midnight reset.
 */
function src_youtube_fetch(array $client, array $source, array $keyword): array
{
    $key = decrypt_secret((string) ($client['youtube_key_enc'] ?? ''));
    if ($key === '') return ingest_envelope_error('No YouTube API key is saved for this client.');

    $cfg    = source_config($source);
    $region = strtoupper((string) ($cfg['region'] ?? 'EG'));

    $q = keyword_query($keyword, 'plain');
    if ($q === '') return ingest_envelope_error('This source has no keyword to search for.');

    // Only ask for what we have not seen, to keep result sets small.
    $since = gmdate('Y-m-d\TH:i:s\Z', strtotime('-7 days') ?: time());
    if (!empty($source['last_ok_at'])) {
        $ts = strtotime((string) $source['last_ok_at'] . ' UTC');
        if ($ts !== false) $since = gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    $searchUrl = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
        'part'          => 'snippet',
        'type'          => 'video',
        'order'         => 'date',
        'maxResults'    => 25,
        'q'             => $q,
        'publishedAfter'=> $since,
        'regionCode'    => $region,
        'key'           => $key,
    ]);
    $safeUrl = str_replace(urlencode($key), '***', $searchUrl);

    $r = lh_http_json('GET', $searchUrl, [], null, ['timeout' => 25, 'fixture' => 'youtube_search.json']);

    // quotaExceeded is not a transient failure — wait for the daily reset
    // (midnight Pacific ≈ 08:00 UTC) rather than retrying all day.
    if ($r['http'] === 403 && str_contains(strtolower($r['error'] . $r['raw']), 'quota')) {
        $reset = strtotime('tomorrow 08:00 UTC') ?: (time() + 3600);
        return ingest_envelope_error('YouTube daily quota exceeded.', [
            'http' => 403, 'request_url' => $safeUrl,
            'retry_after' => max(60, $reset - time()), 'throttled' => true, 'billable' => 100,
        ]);
    }

    $env = ingest_envelope_from_http($r, $safeUrl);
    if ($env !== null) { $env['billable'] = 100; return $env; }

    $entries = $r['json']['items'] ?? [];
    if (!is_array($entries) || !$entries) {
        return ingest_envelope_ok([], ['http' => $r['http'], 'request_url' => $safeUrl, 'billable' => 100]);
    }

    $ids = [];
    foreach ($entries as $en) {
        $vid = (string) ($en['id']['videoId'] ?? '');
        if ($vid !== '') $ids[] = $vid;
    }

    // Second call: statistics for the videos we just found (1 quota unit).
    $stats = [];
    $billable = 100;
    if ($ids) {
        $statsUrl = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
            'part' => 'statistics',
            'id'   => implode(',', $ids),
            'key'  => $key,
        ]);
        $s = lh_http_json('GET', $statsUrl, [], null, ['timeout' => 20, 'fixture' => 'youtube_videos.json']);
        $billable += 1;
        foreach (($s['json']['items'] ?? []) as $row) {
            $stats[(string) ($row['id'] ?? '')] = $row['statistics'] ?? [];
        }
    }

    $items = [];
    foreach ($entries as $en) {
        $vid = (string) ($en['id']['videoId'] ?? '');
        if ($vid === '') continue;
        $sn = $en['snippet'] ?? [];
        $st = $stats[$vid] ?? [];

        $views    = (int) ($st['viewCount'] ?? 0);
        $likes    = (int) ($st['likeCount'] ?? 0);
        $comments = (int) ($st['commentCount'] ?? 0);

        $items[] = [
            'external_id'   => $vid,
            'url'           => 'https://www.youtube.com/watch?v=' . $vid,
            'title'         => (string) ($sn['title'] ?? ''),
            'content'       => (string) ($sn['description'] ?? ''),
            'author_name'   => (string) ($sn['channelTitle'] ?? ''),
            'author_handle' => (string) ($sn['channelId'] ?? ''),
            'author_url'    => !empty($sn['channelId']) ? 'https://www.youtube.com/channel/' . $sn['channelId'] : '',
            'image_url'     => (string) ($sn['thumbnails']['high']['url'] ?? $sn['thumbnails']['default']['url'] ?? ''),
            'domain'        => 'youtube.com',
            'published_at'  => to_utc((string) ($sn['publishedAt'] ?? '')),
            'country'       => $region,
            'platform'      => 'youtube',
            'metrics'       => ['views' => $views, 'likes' => $likes, 'comments' => $comments, 'reach' => $views],
        ];
    }

    return ingest_envelope_ok($items, ['http' => $r['http'], 'request_url' => $safeUrl, 'billable' => $billable]);
}
