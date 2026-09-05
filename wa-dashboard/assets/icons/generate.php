<?php
/**
 * Generates the PWA / favicon icon set: the Revenect "R" filled with the brand gradient
 * on the ink background. One-off build script — run only when the mark changes:
 *   php assets/icons/generate.php
 * Output PNGs are committed, so production never needs GD.
 *
 * GD has no gradient fill, so we paint a gradient layer, stamp the R into a mask, and
 * composite the two. Everything is drawn at 4x and downsampled, which anti-aliases the
 * diagonals (drawing at final size leaves them visibly jagged).
 */
declare(strict_types=1);

const SS = 4;
const INK    = [0x0D, 0x13, 0x21];
const STOPS  = [                        // violet → blue → cyan
    [0.00, [0x7C, 0x3A, 0xED]],
    [0.55, [0x25, 0x63, 0xEB]],
    [1.00, [0x06, 0xB6, 0xD4]],
];

/** Colour at position $t (0..1) along the brand gradient. */
function grad_at(float $t): array
{
    $t = max(0.0, min(1.0, $t));
    for ($i = 0; $i < count(STOPS) - 1; $i++) {
        [$p0, $c0] = STOPS[$i];
        [$p1, $c1] = STOPS[$i + 1];
        if ($t <= $p1) {
            $f = ($p1 - $p0) > 0 ? ($t - $p0) / ($p1 - $p0) : 0.0;
            return [
                (int) round($c0[0] + ($c1[0] - $c0[0]) * $f),
                (int) round($c0[1] + ($c1[1] - $c0[1]) * $f),
                (int) round($c0[2] + ($c1[2] - $c0[2]) * $f),
            ];
        }
    }
    return STOPS[count(STOPS) - 1][1];
}

/** Draw the R into $im in $colour, scaled to a $W-wide canvas (64-unit design grid). */
function draw_mark(GdImage $im, int $W, int $colour, bool $maskable): void
{
    $scale = $W / 64;
    $pad   = $maskable ? 0.78 : 1.0;                 // shrink for Android's adaptive crop
    $off   = (1 - $pad) * 32;
    $x = fn(float $u): int => (int) round(($u * $pad + $off) * $scale);

    // stem
    imagefilledrectangle($im, $x(8), $x(6), $x(21), $x(58), $colour);
    // bowl (outer lobe, then punch the counter back out afterwards by the caller)
    imagefilledrectangle($im, $x(20), $x(6), $x(34), $x(40), $colour);
    imagefilledellipse($im, $x(34), $x(23), (int) round(34 * $pad * $scale), (int) round(34 * $pad * $scale), $colour);
    // leg
    imagefilledpolygon($im, [$x(29), $x(38), $x(54), $x(58), $x(39), $x(58), $x(21), $x(44)], $colour);
}

function gildana_icon(int $size, bool $maskable = false): GdImage
{
    $W = $size * SS;
    $im = imagecreatetruecolor($W, $W);
    $bg = imagecolorallocate($im, ...INK);
    imagefilledrectangle($im, 0, 0, $W, $W, $bg);

    // 1. mask layer: the R in pure white on black
    $mask = imagecreatetruecolor($W, $W);
    $mblack = imagecolorallocate($mask, 0, 0, 0);
    $mwhite = imagecolorallocate($mask, 255, 255, 255);
    imagefilledrectangle($mask, 0, 0, $W, $W, $mblack);
    draw_mark($mask, $W, $mwhite, $maskable);
    // punch the bowl counter back out
    $scale = $W / 64; $pad = $maskable ? 0.78 : 1.0; $off = (1 - $pad) * 32;
    $x = fn(float $u): int => (int) round(($u * $pad + $off) * $scale);
    imagefilledellipse($mask, $x(34), $x(23), (int) round(17 * $pad * $scale), (int) round(17 * $pad * $scale), $mblack);
    imagefilledrectangle($mask, $x(8), $x(15), $x(21), $x(31), $mwhite);   // keep the stem solid

    // 2. composite gradient through the mask (diagonal sweep)
    for ($py = 0; $py < $W; $py++) {
        for ($px = 0; $px < $W; $px++) {
            if ((imagecolorat($mask, $px, $py) & 0xFF) < 128) continue;   // outside the R
            [$r, $g, $b] = grad_at(($px + $py) / (2 * $W));
            imagesetpixel($im, $px, $py, imagecolorallocate($im, $r, $g, $b));
        }
    }
    imagedestroy($mask);

    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $W, $W);
    imagedestroy($im);
    return $out;
}

/* ─────────────────────────────────────────────
   Build entry points
───────────────────────────────────────────── */

/** The five sizes the manifest, iOS and the favicon need. [size, isMaskable]. */
const ICON_SET = [[192, false], [512, false], [512, true], [180, false], [32, false]];

function icon_filename(int $size, bool $mask): string
{
    return $mask ? "maskable-{$size}.png" : "icon-{$size}.png";
}

/** Load an uploaded source image, or null if unreadable / not an image GD understands. */
function icons_load_source(string $path): ?GdImage
{
    if (!is_file($path)) return null;
    $info = @getimagesize($path);
    if (!$info) return null;
    switch ($info[2]) {
        case IMAGETYPE_PNG:  $im = @imagecreatefrompng($path);  break;
        case IMAGETYPE_JPEG: $im = @imagecreatefromjpeg($path); break;
        case IMAGETYPE_WEBP: $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null; break;
        default: return null;   // SVG et al: GD can't rasterise them
    }
    return $im ?: null;
}

/**
 * Render one icon from a source image.
 * The source is centre-cropped to a square, then flattened onto the brand ink — a
 * transparent PNG would otherwise come out black on iOS, which has no transparency support
 * for home-screen icons. Maskable variants are inset so Android's adaptive crop can't clip
 * the artwork.
 */
function icon_from_source(GdImage $src, int $size, bool $maskable): GdImage
{
    $sw = imagesx($src); $sh = imagesy($src);
    $side = min($sw, $sh);
    $sx = (int) (($sw - $side) / 2);
    $sy = (int) (($sh - $side) / 2);

    $out = imagecreatetruecolor($size, $size);
    $bg  = imagecolorallocate($out, ...INK);
    imagefilledrectangle($out, 0, 0, $size, $size, $bg);

    $inset = $maskable ? (int) round($size * 0.11) : 0;   // ~78% safe zone
    $box   = $size - 2 * $inset;
    imagecopyresampled($out, $src, $inset, $inset, $sx, $sy, $box, $box, $side, $side);
    return $out;
}

/**
 * Write the whole icon set.
 * With $sourcePath, the icons are made from that image; otherwise the built-in Revenect
 * mark is drawn. Returns the filenames written.
 */
function icons_build(?string $sourcePath = null): array
{
    $dir = __DIR__;
    $src = $sourcePath !== null ? icons_load_source($sourcePath) : null;
    $made = [];
    foreach (ICON_SET as [$size, $mask]) {
        $im = $src !== null ? icon_from_source($src, $size, $mask) : gildana_icon($size, $mask);
        $name = icon_filename($size, $mask);
        imagepng($im, "$dir/$name", 9);
        imagedestroy($im);
        $made[] = $name;
    }
    if ($src !== null) imagedestroy($src);
    copy("$dir/icon-180.png", "$dir/apple-touch-icon.png");
    copy("$dir/icon-32.png",  "$dir/favicon.png");
    return array_merge($made, ['apple-touch-icon.png', 'favicon.png']);
}

// CLI only — including this file just defines the functions.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $arg = $argv[1] ?? null;
    echo 'generated: ' . implode(', ', icons_build($arg)) . "\n";
}
