<?php
declare(strict_types=1);

/**
 * The fetch pipeline: schedule → connector → normalize → match → dedupe → store.
 *
 * `sources` doubles as the queue (next_fetch_at), so there is no jobs table to
 * leak rows, and a source is *claimed before it runs* — a crashed tick can
 * never come back and hammer the same API a minute later.
 */

/* ── the connector envelope ────────────────────────────────────────────── */

function ingest_envelope_ok(array $items, array $meta = []): array
{
    return array_merge([
        'ok' => true, 'error' => '', 'http' => 200, 'items' => $items,
        'retry_after' => 0, 'throttled' => false, 'billable' => 0,
        'request_url' => '', 'etag' => '', 'last_modified' => '',
    ], $meta, ['ok' => true, 'items' => $items]);
}

function ingest_envelope_error(string $error, array $meta = []): array
{
    return array_merge([
        'ok' => false, 'error' => $error, 'http' => 0, 'items' => [],
        'retry_after' => 0, 'throttled' => false, 'billable' => 0,
        'request_url' => '', 'etag' => '', 'last_modified' => '',
    ], $meta, ['ok' => false, 'error' => $error, 'items' => []]);
}

/**
 * Turn a transport-level problem into an envelope, or return null when the
 * response is usable. Every connector calls this immediately after its request.
 */
function ingest_envelope_from_http(array $r, string $requestUrl): ?array
{
    // 304: the feed has not changed since our last poll. A success, not an error.
    if ((int) $r['http'] === 304) {
        return ingest_envelope_ok([], ['http' => 304, 'request_url' => $requestUrl, 'not_modified' => true]);
    }
    if ((int) $r['http'] === 429) {
        return ingest_envelope_error('Rate limited by the source.', [
            'http' => 429, 'request_url' => $requestUrl,
            'retry_after' => max(60, (int) $r['retry_after']), 'throttled' => true,
        ]);
    }
    if ($r['error'] !== '') {
        return ingest_envelope_error($r['error'], [
            'http' => (int) $r['http'], 'request_url' => $requestUrl,
            'retry_after' => (int) $r['retry_after'],
        ]);
    }
    return null;
}

/* ── scheduling ────────────────────────────────────────────────────────── */

/** Sources that are due, with their client row joined on. */
function sources_due(int $limit = 12): array
{
    $limit = max(1, $limit);
    return db_all(
        "SELECT s.*, c.id AS c_id
           FROM sources s
           JOIN clients c ON c.id = s.client_id
          WHERE s.status = 'active'
            AND c.status = 'active'
            AND (s.next_fetch_at IS NULL OR s.next_fetch_at <= NOW())
          ORDER BY s.next_fetch_at IS NULL DESC, s.next_fetch_at ASC
          LIMIT " . (int) $limit
    );
}

/**
 * Push a source's next check out before running it. Claiming first means a
 * crash mid-fetch costs one skipped cycle, not a retry storm.
 */
function source_claim(int $sourceId, int $intervalMin): void
{
    db_run(
        "UPDATE sources
            SET next_fetch_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), last_fetched_at = NOW()
          WHERE id = ?",
        [max(1, $intervalMin), $sourceId]
    );
}

/**
 * Record the outcome, including exponential backoff on repeated failures.
 *
 * $itemsSeen is passed in rather than read from $env['items'] because a source
 * may have run once per keyword, and what matters here is the whole run.
 */
function source_record(array $source, array $env, int $itemsNew, ?int $itemsSeen = null): void
{
    $id   = (int) $source['id'];
    $seen = $itemsSeen ?? count($env['items']);

    if ($env['ok']) {
        $status = $seen === 0 ? 'empty' : 'ok';
        db_run(
            "UPDATE sources
                SET last_status = ?, last_error = '', last_ok_at = NOW(),
                    last_item_count = ?, consecutive_failures = 0,
                    status = CASE WHEN status = 'error' THEN 'active' ELSE status END,
                    etag = ?, last_modified = ?
              WHERE id = ?",
            [$status, $itemsNew, (string) ($env['etag'] ?? ''), (string) ($env['last_modified'] ?? ''), $id]
        );
        return;
    }

    $fails = (int) $source['consecutive_failures'] + 1;

    // Back off: the source's own interval doubled per failure, capped at 6 hours.
    // An explicit Retry-After always wins.
    $interval = max(1, (int) $source['fetch_interval_min']);
    $delayMin = (int) min(360, $interval * (2 ** min(6, $fails)));
    if (!empty($env['retry_after'])) {
        $delayMin = max($delayMin, (int) ceil((int) $env['retry_after'] / 60));
    }

    // Five failures in a row is a broken source, not a bad afternoon — surface it.
    $newStatus = $fails >= 5 ? 'error' : (string) $source['status'];

    db_run(
        "UPDATE sources
            SET last_status = ?, last_error = ?, consecutive_failures = ?,
                next_fetch_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), status = ?
          WHERE id = ?",
        [
            !empty($env['throttled']) ? 'throttled' : 'error',
            mb_substr((string) $env['error'], 0, 500),
            $fails, $delayMin, $newStatus, $id,
        ]
    );
}

/* ── running one source ────────────────────────────────────────────────── */

/**
 * Fetch one source across the keywords it applies to and store what matches.
 * Returns ['fetched'=>int, 'new'=>int, 'errors'=>int].
 */
function source_run(array $source, ?array $client = null, bool $claim = true): array
{
    $cid    = (int) $source['client_id'];
    $client = $client ?: db_row("SELECT * FROM clients WHERE id = ?", [$cid]);
    if (!$client) return ['fetched' => 0, 'new' => 0, 'errors' => 1];

    $connector = (string) $source['connector'];
    $meta      = listen_connector($connector);
    if (!$meta || !listen_connector_load($connector)) {
        source_record($source, ingest_envelope_error('Unknown connector: ' . $connector), 0);
        return ['fetched' => 0, 'new' => 0, 'errors' => 1];
    }

    if ($claim) source_claim((int) $source['id'], (int) $source['fetch_interval_min']);

    if (!listen_connector_ready($connector, $client)) {
        source_record($source, ingest_envelope_error('Missing credentials for ' . $meta['label']), 0);
        return ['fetched' => 0, 'new' => 0, 'errors' => 1];
    }

    // A keyword-bound source runs once per keyword; a feed-style source runs once
    // and is matched against every keyword afterwards.
    $keywords = keywords_active($cid);
    if (!empty($source['keyword_id'])) {
        $keywords = array_values(array_filter($keywords, function ($k) use ($source) {
            return (int) $k['id'] === (int) $source['keyword_id'];
        }));
    }
    if (!$keywords) {
        source_record($source, ingest_envelope_error('No active keywords to search for.'), 0);
        return ['fetched' => 0, 'new' => 0, 'errors' => 1];
    }

    $runs = !empty($meta['per_keyword']) ? $keywords : [[]];
    $fn   = 'src_' . $connector . '_fetch';

    $totalFetched = 0;
    $totalNew     = 0;
    $errors       = 0;
    $lastEnv      = null;

    foreach ($runs as $kw) {
        $startedAt = date('Y-m-d H:i:s');
        $t0        = microtime(true);

        try {
            $env = $fn($client, $source, $kw ?: []);
        } catch (Throwable $ex) {
            error_log('connector ' . $connector . ' threw: ' . $ex->getMessage());
            $env = ingest_envelope_error('Connector failed: ' . $ex->getMessage());
        }
        $lastEnv = $env;

        // A connector may learn something about its own source while running — an
        // RSS source pointed at a homepage discovers the real feed address, for
        // instance. Persist that so the next check starts from what was learned
        // rather than repeating the work. A null value removes the key.
        if (!empty($env['config_patch']) && is_array($env['config_patch'])) {
            $cfg  = source_config($source);
            $next = $cfg;
            foreach ($env['config_patch'] as $k => $v) {
                if ($v === null) unset($next[$k]); else $next[$k] = $v;
            }
            if ($next !== $cfg) {
                $json = json_encode($next, JSON_UNESCAPED_UNICODE);
                db_run("UPDATE sources SET config_json = ? WHERE id = ?", [$json, (int) $source['id']]);
                $source['config_json'] = $json;
            }
        }

        $stored = ['new' => 0, 'filtered' => 0];
        if ($env['ok']) {
            $stored = ingest_store($client, $source, $env['items'], $kw ?: null, $keywords);
        } else {
            $errors++;
        }

        $totalFetched += count($env['items']);
        $totalNew     += $stored['new'];

        db_run(
            "INSERT INTO fetch_runs
                (client_id, source_id, connector, keyword_id, status, http_code, items_seen,
                 items_new, items_filtered, billable, duration_ms, request_url, error, started_at, finished_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
            [
                $cid, (int) $source['id'], $connector,
                !empty($kw['id']) ? (int) $kw['id'] : null,
                $env['ok'] ? (empty($env['items']) ? 'empty' : 'ok') : (!empty($env['throttled']) ? 'throttled' : 'error'),
                (int) $env['http'], count($env['items']), $stored['new'], $stored['filtered'],
                (int) $env['billable'], (int) round((microtime(true) - $t0) * 1000),
                mb_substr((string) $env['request_url'], 0, 1000),
                mb_substr((string) $env['error'], 0, 500),
                $startedAt,
            ]
        );

        // A throttled source should stop trying its remaining keywords this tick.
        if (!empty($env['throttled'])) break;
    }

    // Record the source's state once, for the run as a whole: only a run where
    // every keyword failed counts as a failed source.
    if ($lastEnv !== null) {
        $outcome = $errors === count($runs)
            ? $lastEnv
            : ingest_envelope_ok([], [
                'items_seen'    => $totalFetched,
                'etag'          => (string) ($lastEnv['etag'] ?? ''),
                'last_modified' => (string) ($lastEnv['last_modified'] ?? ''),
            ]);
        source_record($source, $outcome, $totalNew, $totalFetched);
    }

    return ['fetched' => $totalFetched, 'new' => $totalNew, 'errors' => $errors];
}

/* ── storing ───────────────────────────────────────────────────────────── */

/**
 * Normalize and insert items, skipping anything that does not actually mention
 * a tracked keyword and anything already stored.
 *
 * @param array|null $boundKeyword the keyword this fetch was for, when the
 *                                 connector is keyword-bound
 * @param array      $allKeywords  every active keyword, for feed-style sources
 * @return array{new:int,filtered:int}
 */
function ingest_store(array $client, array $source, array $items, ?array $boundKeyword, array $allKeywords): array
{
    $cid       = (int) $client['id'];
    $connector = (string) $source['connector'];
    $new       = 0;
    $filtered  = 0;

    foreach ($items as $raw) {
        $title   = trim((string) ($raw['title'] ?? ''));
        $content = trim((string) ($raw['content'] ?? ''));
        if ($title === '' && $content === '') { $filtered++; continue; }

        // Which keyword does this belong to? A bound fetch still gets verified,
        // because news search engines happily return loosely related results.
        $keyword = null;
        if ($boundKeyword) {
            if (keyword_matches($boundKeyword, $title, $content)) {
                $keyword = $boundKeyword;
            }
        } else {
            foreach ($allKeywords as $k) {
                if (keyword_matches($k, $title, $content)) { $keyword = $k; break; }
            }
        }
        if (!$keyword) { $filtered++; continue; }

        $url  = trim((string) ($raw['url'] ?? ''));
        $ext  = trim((string) ($raw['external_id'] ?? ''));
        $hash = ingest_hash($connector, $url, $ext);
        if ($hash === '') { $filtered++; continue; }

        $metrics  = (array) ($raw['metrics'] ?? []);
        $likes    = (int) ($metrics['likes'] ?? 0);
        $comments = (int) ($metrics['comments'] ?? 0);
        $shares   = (int) ($metrics['shares'] ?? 0);
        $views    = (int) ($metrics['views'] ?? 0);
        $reach    = (int) ($metrics['reach'] ?? 0);

        $body = $content !== '' ? $content : $title;
        $lang = (string) ($raw['lang'] ?? '');
        if ($lang === '' || $lang === 'both') $lang = sent_detect_lang($body);

        $published = $raw['published_at'] ?? null;

        // INSERT IGNORE + the unique key is what makes a re-fetch a no-op.
        // The sentiment columns are never touched here, so re-seeing an item
        // can't undo a classification or a manual override.
        $affected = db_run(
            "INSERT IGNORE INTO mentions
                (client_id, source_id, keyword_id, connector, platform, external_id, url, url_hash,
                 domain, title, content, snippet, search_text, image_url, author_name, author_handle,
                 author_url, author_followers, lang, country, published_at, fetched_at, reach, likes,
                 comments_count, shares, views, engagement, classify_state, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?, 'pending', NOW())",
            [
                $cid,
                (int) $source['id'],
                (int) $keyword['id'],
                $connector,
                mb_substr((string) ($raw['platform'] ?? 'web'), 0, 24),
                mb_substr($ext, 0, 190),
                mb_substr($url, 0, 1000),
                $hash,
                mb_substr((string) ($raw['domain'] ?? ''), 0, 190),
                mb_substr($title, 0, 500),
                $content,
                mb_substr(excerpt($body, 400), 0, 600),
                sent_normalize($title . ' ' . $content),
                mb_substr((string) ($raw['image_url'] ?? ''), 0, 1000),
                mb_substr((string) ($raw['author_name'] ?? ''), 0, 190),
                mb_substr((string) ($raw['author_handle'] ?? ''), 0, 190),
                mb_substr((string) ($raw['author_url'] ?? ''), 0, 500),
                (int) ($raw['author_followers'] ?? 0),
                mb_substr($lang, 0, 8),
                mb_substr((string) ($raw['country'] ?? ''), 0, 8),
                $published,
                $reach,
                $likes,
                $comments,
                $shares,
                $views,
                $likes + $comments + $shares,
            ]
        );

        if ($affected > 0) {
            $new++;
        } else {
            // Already stored: refresh the engagement counters only.
            db_run(
                "UPDATE mentions
                    SET likes = ?, comments_count = ?, shares = ?, views = ?,
                        engagement = ?, reach = GREATEST(reach, ?)
                  WHERE client_id = ? AND connector = ? AND url_hash = ?",
                [$likes, $comments, $shares, $views, $likes + $comments + $shares, $reach, $cid, $connector, $hash]
            );
        }
    }

    return ['new' => $new, 'filtered' => $filtered];
}

/**
 * Dedupe key. A stable platform id is the most reliable signal; otherwise the
 * canonical URL, so the same article arriving with different tracking params
 * is recognised as one mention.
 */
function ingest_hash(string $connector, string $url, string $externalId): string
{
    // Platform-native ids (Reddit t3_, YouTube videoId, Graph ids) are stable.
    if ($externalId !== '' && !preg_match('#^https?://#i', $externalId)) {
        return sha1($connector . ':' . $externalId);
    }
    $canonical = canonical_url($url !== '' ? $url : $externalId);
    if ($canonical !== '') return sha1($canonical);
    return $externalId !== '' ? sha1($connector . ':' . $externalId) : '';
}

/**
 * Fill search_text for rows stored before the column existed (migration 002).
 * Normalization lives in PHP, not SQL, so this cannot be done in the migration.
 * Runs as a bounded batch from the worker until nothing is left.
 */
function ingest_backfill_search(int $limit = 200): int
{
    $rows = db_all(
        "SELECT id, title, content FROM mentions
          WHERE search_text IS NULL ORDER BY id LIMIT " . (int) max(1, $limit)
    );
    foreach ($rows as $r) {
        db_run("UPDATE mentions SET search_text = ? WHERE id = ?", [
            sent_normalize((string) $r['title'] . ' ' . (string) $r['content']),
            (int) $r['id'],
        ]);
    }
    return count($rows);
}
