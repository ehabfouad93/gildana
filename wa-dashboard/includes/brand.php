<?php
declare(strict_types=1);

/**
 * REVENECT by Gildana — brand constants and logo.
 *
 * Revenect is the PRODUCT; Gildana is the company that operates it. Copy that addresses
 * the agency ("ask Gildana to top up your credits") must keep saying Gildana — only the
 * product's own name became Revenect.
 *
 * These are constants rather than config keys because config.php is never shipped: relying
 * on it would leave the deployed app showing the old name until someone edited PHP on the
 * server by hand.
 */

const BRAND_NAME    = 'Revenect';
const BRAND_PARENT  = 'Gildana';
const BRAND_TAGLINE = 'Business Interaction Engine';

/* Palette — keep in sync with the :root tokens in assets/dashboard.css. */
const BRAND_INK    = '#0D1321';
const BRAND_VIOLET = '#7C3AED';
const BRAND_BLUE   = '#2563EB';
const BRAND_CYAN   = '#06B6D4';
const BRAND_CLOUD  = '#F3F4F6';

/**
 * Display name.
 *
 * A genuinely custom app_name still wins (white-labelling), but the value we used to ship
 * counts as "unset": config.php is never deployed, so every existing install still has
 * 'Gildana WhatsApp' in it and would otherwise keep showing the old name until someone
 * edited PHP on the server by hand.
 */
function brand_name(): string
{
    $n = trim((string) config('app_name', ''));
    $legacy = ['gildana whatsapp', 'whatsapp dashboard', 'gildana whatsapp dashboard'];
    if ($n === '' || in_array(strtolower($n), $legacy, true)) return BRAND_NAME;
    return $n;
}

/**
 * The logo as inline SVG — one place, so replacing it with the official artwork is a
 * single edit. Inline (not <img>) keeps the gradient crisp at any size with no extra
 * request, and lets the wordmark inherit colour from CSS.
 *
 * @param string $variant 'mark'  – the R only (square)
 *                        'full'  – mark + REVENECT wordmark
 *                        'stack' – mark + wordmark + "by Gildana" (login screens)
 * @param int    $h       Rendered height in px.
 */
function brand_logo_svg(string $variant = 'full', int $h = 28): string
{
    $uid = 'rv' . substr(md5($variant . $h . random_int(0, PHP_INT_MAX)), 0, 6);
    $grad = '<defs><linearGradient id="' . $uid . '" x1="0" y1="0" x2="1" y2="1">'
          . '<stop offset="0%" stop-color="' . BRAND_VIOLET . '"/>'
          . '<stop offset="55%" stop-color="' . BRAND_BLUE . '"/>'
          . '<stop offset="100%" stop-color="' . BRAND_CYAN . '"/>'
          . '</linearGradient></defs>';

    // The mark: a bold R on a 64x64 grid — vertical stem, rounded bowl, diagonal leg,
    // with a darker fold where the leg meets the stem for the layered ribbon look.
    $mark = '<g>'
          . '<path fill="url(#' . $uid . ')" d="M8 6h13v52H8z"/>'
          . '<path fill="url(#' . $uid . ')" d="M20 6h14a17 17 0 0 1 0 34H20v-9h14a8 8 0 0 0 0-16H20z"/>'
          . '<path fill="url(#' . $uid . ')" d="M29 38l25 20H39L21 44z"/>'
          . '<path fill="' . BRAND_INK . '" opacity=".22" d="M21 38h11L21 50z"/>'
          . '</g>';

    if ($variant === 'mark') {
        return '<svg class="brand-svg" height="' . $h . '" viewBox="0 0 64 64" role="img" aria-label="' . BRAND_NAME . '" xmlns="http://www.w3.org/2000/svg">'
             . '<title>' . BRAND_NAME . '</title>' . $grad . $mark . '</svg>';
    }

    // Wordmark: REVENECT with the second E picked out in cyan, as in the identity.
    $word = '<text x="78" y="42" font-family="Helvetica Neue,Helvetica,Arial,sans-serif" font-size="30" font-weight="700" letter-spacing="1.5" fill="currentColor">'
          . 'REVEN<tspan fill="' . BRAND_CYAN . '">E</tspan>CT</text>';

    if ($variant === 'stack') {
        return '<svg class="brand-svg" height="' . $h . '" viewBox="0 0 300 84" role="img" aria-label="' . BRAND_NAME . ' by ' . BRAND_PARENT . '" xmlns="http://www.w3.org/2000/svg">'
             . '<title>' . BRAND_NAME . ' by ' . BRAND_PARENT . '</title>' . $grad . $mark . $word
             . '<text x="80" y="66" font-family="Helvetica Neue,Helvetica,Arial,sans-serif" font-size="15" fill="currentColor" opacity=".55">by ' . BRAND_PARENT . '</text>'
             . '</svg>';
    }

    return '<svg class="brand-svg" height="' . $h . '" viewBox="0 0 300 64" role="img" aria-label="' . BRAND_NAME . '" xmlns="http://www.w3.org/2000/svg">'
         . '<title>' . BRAND_NAME . '</title>' . $grad . $mark . $word . '</svg>';
}

/**
 * Height of the logo on the public landing page, in px.
 *
 * Separate from the top bar's logo_height (includes/view.php) on purpose: the two sit on
 * different grounds at different sizes, and artwork that reads well in a 56px app bar is
 * usually too small at the head of a marketing page. Tying them together would mean
 * every fix to one broke the other.
 *
 * Lives here rather than in view.php because the landing page never loads view.php —
 * exactly the trap the top bar's own setting fell into once already.
 */
function site_logo_height(): int
{
    static $h = null;
    if ($h === null) {
        try { $h = (int) db_val("SELECT v FROM app_settings WHERE k='site_logo_height'"); }
        catch (Throwable $e) { $h = 0; }
    }
    return $h > 0 ? max(20, min(120, $h)) : 42;
}

/* ─────────────────────────────────────────────
   Custom artwork
   Files dropped in assets/brand/ override the built-in SVG above. They can arrive either
   through Admin → Settings → Branding or by uploading straight to the folder in cPanel —
   both work, because this only ever looks at what is on disk.
───────────────────────────────────────────── */

const BRAND_DIR      = 'assets/brand';
const BRAND_LOGO_EXT = ['svg', 'png', 'webp', 'jpg', 'jpeg'];

/** Absolute path of assets/brand. */
function brand_dir_path(): string { return dirname(__DIR__) . '/' . BRAND_DIR; }

/** First existing file for a base name, e.g. 'logo-full' → 'logo-full.png'. Null if none. */
function brand_find(string $base): ?string
{
    foreach (BRAND_LOGO_EXT as $ext) {
        $f = brand_dir_path() . '/' . $base . '.' . $ext;
        if (is_file($f)) return $base . '.' . $ext;
    }
    return null;
}

/**
 * Resolve which uploaded file to use for a variant, most specific first.
 * $onDark picks the light-artwork variant used on the ink login/setup screens, where a
 * dark logo would be invisible.
 */
function brand_logo_file(string $variant, bool $onDark = false): ?string
{
    $tries = [];
    if ($onDark) { $tries[] = "logo-{$variant}-light"; $tries[] = 'logo-light'; }
    $tries[] = "logo-{$variant}";
    $tries[] = 'logo';
    foreach ($tries as $base) {
        $hit = brand_find($base);
        if ($hit !== null) return $hit;
    }
    return null;
}

/**
 * The logo for a surface: an uploaded file when one exists, otherwise the built-in SVG.
 *
 * Uploaded artwork is emitted as <img>, never inlined. An SVG can legally carry script, and
 * inlining a customer-supplied file into every page would be an XSS hole — as an <img> the
 * browser renders it inertly, and it still scales cleanly.
 *
 * @param string $base Path prefix back to the app root ('./' from the root, '../' from
 *                     client/ and admin/), matching pwa_head().
 */
function brand_logo(string $variant = 'full', int $h = 28, string $base = './', bool $onDark = false): string
{
    $file = brand_logo_file($variant, $onDark);
    if ($file === null) return brand_logo_svg($variant, $h);

    $b = rtrim($base, '/') . '/';
    $v = @filemtime(brand_dir_path() . '/' . $file) ?: '1';
    return '<img class="brand-img" src="' . e($b . BRAND_DIR . '/' . $file) . '?v=' . $v
         . '" height="' . (int) $h . '" alt="' . e(brand_name()) . '">';
}

/** True when the install is using its own artwork rather than the built-in mark. */
function brand_has_custom_logo(): bool
{
    return brand_logo_file('full') !== null || brand_logo_file('stack') !== null
        || brand_logo_file('mark') !== null;
}
