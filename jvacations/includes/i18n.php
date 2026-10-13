<?php
declare(strict_types=1);

/**
 * Bilingual EN/AR with RTL. Strings live in lang/<locale>.php as flat arrays
 * keyed by dotted slugs. English is the fallback; a missing key renders as the
 * key itself so gaps are visible rather than silent.
 */

function locales(): array
{
    return [
        'en' => ['label' => 'English',  'dir' => 'ltr'],
        'ar' => ['label' => 'العربية', 'dir' => 'rtl'],
    ];
}

/** Session → user row → config default → 'en'. */
function locale(): string
{
    $l = (string) ($_SESSION['locale'] ?? '');
    if ($l === '') $l = (string) config('default_locale', 'en');
    return isset(locales()[$l]) ? $l : 'en';
}

/** Persist a locale choice to the session and, when logged in, to the user row. */
function set_locale(string $code): void
{
    if (!isset(locales()[$code])) return;
    $_SESSION['locale'] = $code;
    $u = current_user();
    if ($u) {
        try { db_run("UPDATE users SET locale = ? WHERE id = ?", [$code, $u['id']]); } catch (Throwable $e) {}
    }
}

function locale_dir(): string { return locales()[locale()]['dir']; }
function is_rtl(): bool       { return locale_dir() === 'rtl'; }

/** Load and memoize one locale's string table. */
function lang_table(string $code): array
{
    static $cache = [];
    if (isset($cache[$code])) return $cache[$code];
    $file = dirname(__DIR__) . '/lang/' . $code . '.php';
    $cache[$code] = is_file($file) ? (array) require $file : [];
    return $cache[$code];
}

/**
 * Translate. {placeholders} are substituted from $vars.
 * Falls back: current locale → English → the key itself.
 */
function t(string $key, array $vars = []): string
{
    $table = lang_table(locale());
    $s = $table[$key] ?? null;
    if ($s === null) {
        $en = lang_table('en');
        $s  = $en[$key] ?? $key;
    }
    $s = (string) $s;
    foreach ($vars as $k => $v) {
        $s = str_replace('{' . $k . '}', (string) $v, $s);
    }
    return $s;
}

/** Thousands-separated number. Western digits in both locales, deliberately. */
function fmt_num($n): string
{
    return number_format((float) $n, 0, '.', ',');
}

/** Format a stored date/datetime for display. */
function fmt_dt(?string $value, string $format = 'Y-m-d H:i'): string
{
    if (!$value) return '—';
    $ts = strtotime($value);
    return $ts === false ? '—' : date($format, $ts);
}

function fmt_date(?string $value): string { return fmt_dt($value, 'Y-m-d'); }

/** "14:30" → "2:30 PM" (or Arabic ص/م). */
function fmt_time(?string $value): string
{
    if (!$value) return '—';
    $ts = strtotime('1970-01-01 ' . $value);
    if ($ts === false) return '—';
    return date('g:i', $ts) . ' ' . (date('A', $ts) === 'AM' ? t('time.am') : t('time.pm'));
}
