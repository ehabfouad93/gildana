<?php
declare(strict_types=1);

/**
 * Arabic, right to left.
 *
 * Each person picks English or Arabic (Profile, or the button in the top bar). In Arabic the page
 * is laid out right to left, and its words are translated on the way out: every piece of text
 * that is exactly a phrase in the dictionary (includes/lang/ar.php) — a menu item, a heading, a
 * button, a column, a placeholder — is swapped. Text people typed (lead names, notes, messages)
 * is never touched unless it happens to be exactly one of those phrases, and scripts are left
 * alone. A phrase not in the dictionary simply stays in English, so a new screen never breaks.
 */

function ui_lang(): string
{
    static $lang = null;
    if ($lang !== null) return $lang;
    $u = function_exists('current_user_full') ? (current_user_full() ?: []) : [];
    $l = (string) ($u['lang'] ?? '');
    return $lang = $l === 'ar' ? 'ar' : 'en';
}

function ui_rtl(): bool { return ui_lang() === 'ar'; }

/** Light, dark or auto (follow the device). */
function ui_theme(): string
{
    $u = function_exists('current_user_full') ? (current_user_full() ?: []) : [];
    $t = (string) ($u['theme'] ?? '');
    return in_array($t, ['light', 'dark'], true) ? $t : 'auto';
}

function i18n_dict(): array
{
    static $d = null;
    if ($d === null) $d = ui_lang() === 'ar' && is_file(__DIR__ . '/lang/ar.php') ? (array) require __DIR__ . '/lang/ar.php' : [];
    return $d;
}

/** One phrase, for code that builds text itself. */
function t(string $en): string
{
    return i18n_dict()[$en] ?? $en;
}

/**
 * Translate a finished page: text between tags, and placeholder / title / aria-label values, when
 * the whole of it is a known phrase. Scripts and styles are skipped.
 */
function i18n_translate_html(string $html): string
{
    $d = i18n_dict();
    if (!$d) return $html;
    $parts = preg_split('~(<script\b.*?</script>|<style\b.*?</style>|<textarea\b.*?</textarea>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $part) {
        if ($i % 2 === 1) continue;                                   // a script, style or textarea: as it is
        $part = preg_replace_callback('~>(\s*)([^<>]{1,160}?)(\s*)<~u', function ($m) use ($d) {
            $k = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($d[$k])) return '>' . $m[1] . htmlspecialchars($d[$k], ENT_QUOTES, 'UTF-8') . $m[3] . '<';
            // "Leads given" and "Leads given ↓" — a sortable heading keeps its arrow.
            return $m[0];
        }, $part);
        $part = preg_replace_callback('~\b(placeholder|title|aria-label)="([^"]{1,160})"~u', function ($m) use ($d) {
            $k = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return isset($d[$k]) ? $m[1] . '="' . htmlspecialchars($d[$k], ENT_QUOTES, 'UTF-8') . '"' : $m[0];
        }, $part);
        $parts[$i] = $part;
    }
    return implode('', $parts);
}

/** The starred pages, as [['u' => 'crm_reports.php?r=roi', 't' => 'Return on ad spend'], …]. */
function nav_favs_decode(string $json): array
{
    $out = [];
    foreach ((array) json_decode($json, true) as $f) {
        if (is_array($f) && is_string($f['u'] ?? null) && preg_match('~^[A-Za-z0-9_\-]+\.php(\?[^\s#]*)?$~', $f['u']))
            $out[] = ['u' => $f['u'], 't' => (string) ($f['t'] ?? $f['u'])];
    }
    return $out;
}

function nav_favs(): array
{
    $u = function_exists('current_user_full') ? (current_user_full() ?: []) : [];
    return nav_favs_decode((string) ($u['nav_favs'] ?? ''));
}

/** This page, written the way a favourite stores it: the file and its query, relative to its folder. */
function nav_here(): string
{
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $q = $_GET;
    unset($q['saved'], $q['ok'], $q['err'], $q['t']);
    return $page . ($q ? '?' . http_build_query($q) : '');
}

/** Start translating this page's output (called by the layout when the person reads Arabic). */
function i18n_begin(): void
{
    if (ui_lang() === 'ar' && !defined('I18N_ON')) { define('I18N_ON', true); ob_start('i18n_translate_html'); }
}
