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

/** Read a trimmed string from POST. */
function post_str(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

/** A Y-m-d date from input, or null when empty/invalid. */
function valid_date(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if ($raw === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    return ($d && $d->format('Y-m-d') === $raw) ? $raw : null;
}

/** An H:i time from input, or null. */
function valid_time(?string $raw): ?string
{
    $raw = trim((string) $raw);
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $raw)) return null;
    return substr($raw, 0, 5);
}

/** CSV cell: escape quotes and neutralize spreadsheet formula injection. */
function csv_cell(?string $value): string
{
    $v = (string) $value;
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $v = "'" . $v;
    }
    return '"' . str_replace('"', '""', $v) . '"';
}
