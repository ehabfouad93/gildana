<?php
declare(strict_types=1);

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
function e(?string $value): string
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

/** The app's public base URL (no trailing slash), used to build public asset URLs. */
function base_url(): string
{
    $base = rtrim((string) config('base_url', ''), '/');
    if ($base !== '') return $base;
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir  = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
    $root = preg_replace('#/(app|admin|cron)$#', '', $dir);
    return ($https ? 'https' : 'http') . '://' . $host . ($root === '/' ? '' : $root);
}

/** Human-readable file size. */
function human_size(int $bytes): string
{
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float) $bytes;
    while ($n >= 1024 && $i < count($u) - 1) { $n /= 1024; $i++; }
    return round($n, $n < 10 && $i > 0 ? 1 : 0) . ' ' . $u[$i];
}
