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
