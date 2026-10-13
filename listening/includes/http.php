<?php
declare(strict_types=1);

/**
 * Outbound HTTP for the connectors.
 *
 * Built on the same raw-cURL style as the sibling apps, plus the three things
 * they don't need but a poller does: retry with backoff, honouring 429 /
 * Retry-After, and conditional GET so an unchanged feed costs nothing.
 *
 * Errors are values, never exceptions.
 */

/**
 * @return array{http:int,raw:string,ctype:string,headers:array,error:string,retry_after:int,attempts:int,ms:int,url:string}
 */
function lh_http(string $method, string $url, array $headers = [], $body = null, array $opts = []): array
{
    $timeout   = (int) ($opts['timeout'] ?? 25);
    $connectTo = (int) ($opts['connect_timeout'] ?? 10);
    $tries     = max(1, (int) ($opts['tries'] ?? 3));
    $backoffMs = (int) ($opts['backoff_ms'] ?? 400);
    $ua        = (string) ($opts['user_agent'] ?? config('user_agent', 'GildanaListening/1.0'));

    // Offline fixture mode: serve a recorded response instead of hitting the network.
    if (!empty($opts['fixture'])) {
        $fix = lh_fixture_read((string) $opts['fixture']);
        if ($fix !== null) {
            return ['http' => 200, 'raw' => $fix, 'ctype' => '', 'headers' => [],
                    'error' => '', 'retry_after' => 0, 'attempts' => 0, 'ms' => 0, 'url' => $url];
        }
    }

    $reqHeaders = $headers;
    if (!empty($opts['etag']))          $reqHeaders[] = 'If-None-Match: ' . $opts['etag'];
    if (!empty($opts['last_modified'])) $reqHeaders[] = 'If-Modified-Since: ' . $opts['last_modified'];

    $started  = microtime(true);
    $attempt  = 0;
    $last     = ['http' => 0, 'raw' => '', 'ctype' => '', 'headers' => [], 'error' => 'Not attempted', 'retry_after' => 0];

    while ($attempt < $tries) {
        $attempt++;
        lh_host_gate($url);

        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $reqHeaders,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTo,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 4,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_ENCODING       => '',      // accept gzip; feeds are large and repetitive
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders) {
                $p = strpos($line, ':');
                if ($p > 0) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $raw   = curl_exec($ch);
        $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $cerr  = curl_error($ch);
        curl_close($ch);

        $retryAfter = lh_retry_after($respHeaders);

        if ($raw === false) {
            $last = ['http' => 0, 'raw' => '', 'ctype' => '', 'headers' => $respHeaders,
                     'error' => $cerr ?: 'Network error', 'retry_after' => $retryAfter];
        } else {
            $last = ['http' => $http, 'raw' => (string) $raw, 'ctype' => $ctype, 'headers' => $respHeaders,
                     'error' => '', 'retry_after' => $retryAfter];

            // 304 Not Modified is a success: nothing changed since our last poll.
            if ($http === 304) break;
            if ($http >= 200 && $http < 300) break;

            $last['error'] = 'HTTP ' . $http . ' ' . mb_substr(trim((string) $raw), 0, 200);
        }

        // Retry only what is worth retrying. A 401/404 will not fix itself.
        $retryable = $last['http'] === 0 || $last['http'] === 429 || $last['http'] >= 500;
        if (!$retryable || $attempt >= $tries) break;

        $sleepMs = $retryAfter > 0
            ? min(10000, $retryAfter * 1000)
            : min(8000, (int) ($backoffMs * (2 ** ($attempt - 1)))) + random_int(0, 250);
        usleep($sleepMs * 1000);
    }

    $last['attempts'] = $attempt;
    $last['ms']       = (int) round((microtime(true) - $started) * 1000);
    $last['url']      = $url;
    return $last;
}

/** JSON convenience wrapper. Returns the same shape plus a decoded 'json'. */
function lh_http_json(string $method, string $url, array $headers = [], ?array $payload = null, array $opts = []): array
{
    $headers = array_merge(['Accept: application/json'], $headers);
    $body    = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $body      = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $r = lh_http($method, $url, $headers, $body, $opts);
    $r['json'] = null;

    if ($r['raw'] !== '') {
        $decoded = json_decode($r['raw'], true);
        if (is_array($decoded)) {
            $r['json'] = $decoded;
            // Surface an API-level error even on a 200.
            if (isset($decoded['error']) && $r['error'] === '') {
                $r['error'] = is_array($decoded['error'])
                    ? (string) ($decoded['error']['message'] ?? 'API error')
                    : (string) $decoded['error'];
            }
        }
    }
    if ($r['error'] === '' && $r['json'] === null && $r['http'] !== 304) {
        $r['error'] = 'Response was not JSON';
    }
    return $r;
}

/** Seconds to wait, from a Retry-After header given as seconds or an HTTP date. */
function lh_retry_after(array $headers): int
{
    $v = trim((string) ($headers['retry-after'] ?? ''));
    if ($v === '') return 0;
    if (ctype_digit($v)) return min(3600, (int) $v);
    $ts = strtotime($v);
    if ($ts === false) return 0;
    return max(0, min(3600, $ts - time()));
}

/**
 * Politeness gate: never fire two requests at the same host back to back within
 * one worker run. Cheap insurance against tripping a rate limiter mid-tick.
 */
function lh_host_gate(string $url, int $minGapMs = 1200): void
{
    static $last = [];
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if ($host === '') return;
    $now = (int) (microtime(true) * 1000);
    if (isset($last[$host])) {
        $wait = $minGapMs - ($now - $last[$host]);
        if ($wait > 0) usleep($wait * 1000);
    }
    $last[$host] = (int) (microtime(true) * 1000);
}

/** Read a recorded response body, if fixture mode is configured. */
function lh_fixture_read(string $name): ?string
{
    $dir = (string) config('fixtures_dir', '');
    if ($dir === '') return null;
    $file = rtrim($dir, '/') . '/' . basename($name);
    if (!is_file($file)) return null;
    $raw = @file_get_contents($file);
    return $raw === false ? null : $raw;
}

/**
 * Parse an RSS 2.0, Atom or RDF feed into normalized items.
 *
 * Atom's default namespace is the trap here: $xml->entry finds nothing on an
 * Atom feed, because every element sits in the Atom namespace. Each shape has
 * to be addressed explicitly.
 *
 * @return array{ok:bool,error:string,items:array}
 */
function lh_parse_feed(string $xmlRaw): array
{
    $xmlRaw = trim($xmlRaw);
    if ($xmlRaw === '') return ['ok' => false, 'error' => 'Empty response', 'items' => []];

    // A feed URL that quietly returns an HTML error page is a common failure —
    // say so rather than reporting an opaque parse error.
    if (stripos(ltrim($xmlRaw), '<!doctype html') === 0 || stripos(ltrim($xmlRaw), '<html') === 0) {
        return ['ok' => false, 'error' => 'That URL returned a web page, not a feed.', 'items' => []];
    }

    $prevErrors = libxml_use_internal_errors(true);
    // LIBXML_NONET blocks external entity fetches; never pass LIBXML_NOENT.
    $xml = simplexml_load_string($xmlRaw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    $err = libxml_get_last_error();
    libxml_clear_errors();
    libxml_use_internal_errors($prevErrors);

    if ($xml === false) {
        return ['ok' => false, 'error' => 'Could not parse the feed' . ($err ? ': ' . trim($err->message) : ''), 'items' => []];
    }

    $ATOM    = 'http://www.w3.org/2005/Atom';
    $RSS1    = 'http://purl.org/rss/1.0/';
    $CONTENT = 'http://purl.org/rss/1.0/modules/content/';
    $DC      = 'http://purl.org/dc/elements/1.1/';

    $items = [];

    if (isset($xml->channel->item)) {                       // RSS 2.0
        foreach ($xml->channel->item as $it) {
            $items[] = lh_feed_item_rss($it, $CONTENT, $DC);
        }
    } elseif (count($xml->children($ATOM)->entry ?? [])) {  // Atom
        foreach ($xml->children($ATOM)->entry as $entry) {
            $items[] = lh_feed_item_atom($entry, $ATOM);
        }
    } elseif (count($xml->children($RSS1)->item ?? [])) {   // RSS 1.0 / RDF
        foreach ($xml->children($RSS1)->item as $it) {
            $items[] = lh_feed_item_rss($it, $CONTENT, $DC);
        }
    } else {
        return ['ok' => false, 'error' => 'No items found in the feed', 'items' => []];
    }

    return ['ok' => true, 'error' => '', 'items' => array_values(array_filter($items))];
}

function lh_feed_item_rss(SimpleXMLElement $it, string $CONTENT, string $DC): ?array
{
    $title = trim((string) ($it->title ?? ''));
    $link  = trim((string) ($it->link ?? ''));
    if ($title === '' && $link === '') return null;

    $encoded = (string) ($it->children($CONTENT)->encoded ?? '');
    $body    = $encoded !== '' ? $encoded : (string) ($it->description ?? '');

    $date = (string) ($it->pubDate ?? '');
    if ($date === '') $date = (string) ($it->children($DC)->date ?? '');

    $author = trim((string) ($it->author ?? ''));
    if ($author === '') $author = trim((string) ($it->children($DC)->creator ?? ''));

    // Google News wraps the publisher in <source url="…">Publisher</source>.
    $srcName = '';
    $srcUrl  = '';
    if (isset($it->source)) {
        $srcName = trim((string) $it->source);
        $srcUrl  = trim((string) ($it->source['url'] ?? ''));
    }

    $guid = trim((string) ($it->guid ?? ''));

    return [
        'title'        => plain_text($title),
        'url'          => $link,
        'content'      => plain_text($body),
        'published_at' => to_utc($date),
        'author_name'  => $author !== '' ? plain_text($author) : $srcName,
        'external_id'  => $guid !== '' ? $guid : $link,
        'source_name'  => $srcName,
        'source_url'   => $srcUrl,
    ];
}

function lh_feed_item_atom(SimpleXMLElement $entry, string $ATOM): ?array
{
    $a     = $entry->children($ATOM);
    $title = trim((string) ($a->title ?? ''));

    // Prefer rel="alternate" (or a link with no rel at all) — rel="self" points
    // back at the feed, not the article.
    //
    // Atom attributes are unprefixed, but after children($ATOM) the array access
    // $l['href'] looks for an attribute *in the Atom namespace* and finds nothing.
    // attributes() is what actually reads them.
    $link     = '';
    $fallback = '';
    foreach ($a->link as $l) {
        $attr = $l->attributes();
        $href = (string) ($attr['href'] ?? '');
        if ($href === '') continue;
        if ($fallback === '') $fallback = $href;
        $rel = (string) ($attr['rel'] ?? '');
        if ($rel === '' || $rel === 'alternate') { $link = $href; break; }
    }
    if ($link === '') $link = $fallback;
    if ($title === '' && $link === '') return null;

    $body = (string) ($a->content ?? '');
    if ($body === '') $body = (string) ($a->summary ?? '');

    $date = (string) ($a->updated ?? '');
    if ($date === '') $date = (string) ($a->published ?? '');

    $author = '';
    if (isset($a->author)) $author = trim((string) ($a->author->children($ATOM)->name ?? ''));

    $id = trim((string) ($a->id ?? ''));

    return [
        'title'        => plain_text($title),
        'url'          => $link,
        'content'      => plain_text($body),
        'published_at' => to_utc($date),
        'author_name'  => $author,
        'external_id'  => $id !== '' ? $id : $link,
        'source_name'  => '',
        'source_url'   => '',
    ];
}
