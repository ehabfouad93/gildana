<?php
declare(strict_types=1);

/**
 * Small shared helpers: escaping, redirects, JSON, flashes, CSRF, CSV.
 * Deliberately dependency-free so cron scripts can require it on its own.
 */

/* ── PHP 7.4 polyfills (shared-hosting safety) ── */
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

/* ── escaping ── */
/**
 * Escape for HTML output.
 *
 * Takes a scalar rather than ?string on purpose. Under strict_types an int
 * argument is a fatal TypeError, and ints arrive here more easily than you would
 * expect — PHP silently converts numeric-string array keys to integers, so
 * `foreach (['7' => …] as $k => $v)` hands you int 7, not '7'. An escaping
 * helper refusing to escape a number is all cost and no benefit.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* ── redirect + exit ── */
function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/* ── JSON response + exit ── */
function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── flash messages (one-shot, session-backed) ── */
function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ── CSRF ── */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }
}

/** Accept a CSRF token from a JSON/AJAX request header or body. */
function verify_csrf_soft(): bool
{
    $token = (string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

/* ── form repopulation ── */
function old(string $key, string $default = ''): string
{
    return e((string) ($_POST[$key] ?? $default));
}

/** The app's public base URL (no trailing slash). */
function base_url(): string
{
    $base = rtrim((string) config('base_url', ''), '/');
    if ($base !== '') return $base;
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir  = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $root = preg_replace('#/(client|admin|cron)$#', '', $dir);
    return ($https ? 'https' : 'http') . '://' . $host . ($root === '/' ? '' : $root);
}

/* ── CSV cell: escape quotes AND neutralize spreadsheet formula injection ──
   A leading =, +, -, @, tab or CR is prefixed with a single quote so Excel/Sheets
   treats it as text, not a formula. */
function csv_cell(?string $value): string
{
    $v = (string) $value;
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}

/** Human-readable relative time, e.g. "3h ago". Locale-aware via t(). */
function time_ago(?string $datetime): string
{
    if (!$datetime) return '—';
    $ts = strtotime($datetime);
    if ($ts === false) return '—';
    $d = time() - $ts;
    if ($d < 60)    return t('time.now');
    if ($d < 3600)  return t('time.m_ago', ['n' => (string) (int) floor($d / 60)]);
    if ($d < 86400) return t('time.h_ago', ['n' => (string) (int) floor($d / 3600)]);
    if ($d < 2592000) return t('time.d_ago', ['n' => (string) (int) floor($d / 86400)]);
    return date('Y-m-d', $ts);
}

/** Trim to a length on a word boundary, appending an ellipsis. */
function excerpt(?string $text, int $len = 220): string
{
    $t = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    if ($t === '' || mb_strlen($t) <= $len) return $t;
    $cut = mb_substr($t, 0, $len);
    $sp  = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $len * 0.6) $cut = mb_substr($cut, 0, $sp);
    return $cut . '…';
}

/** Strip HTML and decode entities — feed descriptions arrive as markup. */
function plain_text(?string $html): string
{
    $s = html_entity_decode((string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = strip_tags($s);
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

/**
 * Canonical form of a URL for dedupe: lowercase scheme+host, drop tracking
 * params, drop the fragment and any trailing slash.
 */
function canonical_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    $p = @parse_url($url);
    if (!$p || empty($p['host'])) return $url;

    $scheme = strtolower((string) ($p['scheme'] ?? 'https'));
    $host   = strtolower($p['host']);
    if (str_starts_with($host, 'www.')) $host = substr($host, 4);
    $path   = rtrim((string) ($p['path'] ?? ''), '/');

    $query = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        foreach (array_keys($q) as $k) {
            $lk = strtolower((string) $k);
            if (str_starts_with($lk, 'utm_') || in_array($lk, ['fbclid', 'gclid', 'igshid', 'ref', 'ref_src', 'mc_cid', 'mc_eid'], true)) {
                unset($q[$k]);
            }
        }
        ksort($q);
        if ($q) $query = '?' . http_build_query($q);
    }
    $port = isset($p['port']) ? ':' . (int) $p['port'] : '';
    return $scheme . '://' . $host . $port . $path . $query;
}

/** Fire-and-forget kick of the cron worker from a web request. */
function trigger_worker(): void
{
    $token = (string) config('worker_token', '');
    if ($token === '') return;
    $url = base_url() . '/cron/worker.php?token=' . urlencode($token);
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 400,
            CURLOPT_NOSIGNAL       => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) { /* non-fatal — cron will still run it */ }
}
