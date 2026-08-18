<?php
/**
 * Generates the PWA / favicon icon set from the brand palette (gold ring + G on black).
 * One-off build script — run only when the mark changes:
 *   php assets/icons/generate.php
 * Output PNGs are committed, so production never needs GD.
 *
 * The mark is drawn from primitives at 4x and downsampled, which anti-aliases the curves.
 * (Scaling GD's built-in bitmap font instead produces a visibly pixelated letter.)
 */
declare(strict_types=1);

const GOLD  = [0xc4, 0x97, 0x3a];
const BLACK = [0x1a, 0x16, 0x12];
const SS    = 4;   // supersampling factor

/** @param bool $maskable Shrink the mark so Android's adaptive mask can't clip it. */
function gildana_icon(int $size, bool $maskable = false): GdImage
{
    $W = $size * SS;
    $im = imagecreatetruecolor($W, $W);
    $bg   = imagecolorallocate($im, ...BLACK);
    $gold = imagecolorallocate($im, ...GOLD);
    imagefilledrectangle($im, 0, 0, $W, $W, $bg);

    $c = $W / 2;
    $R = $W * ($maskable ? 0.30 : 0.40);   // outer radius of the ring
    $stroke = $W * 0.085;                   // ring thickness
    $Ri = $R - $stroke;

    // Ring = filled gold disc with a black disc punched out.
    imagefilledellipse($im, (int) $c, (int) $c, (int) ($R * 2), (int) ($R * 2), $gold);
    imagefilledellipse($im, (int) $c, (int) $c, (int) ($Ri * 2), (int) ($Ri * 2), $bg);

    // Open the ring at 3 o'clock so it reads as a G rather than an O.
    $gap = $stroke * 1.5;
    imagefilledrectangle($im, (int) $c, (int) ($c - $gap / 2), $W, (int) ($c + $gap / 2), $bg);

    // Crossbar along the bottom edge of that gap, reconnecting to the lower arc.
    $barT = $stroke * 0.92;
    $barY = $c + $gap / 2;
    imagefilledrectangle($im, (int) ($c - $R * 0.10), (int) ($barY - $barT), (int) ($c + $R + 1), (int) $barY, $gold);

    // Downsample → smooth edges.
    $out = imagecreatetruecolor($size, $size);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $W, $W);
    imagedestroy($im);
    return $out;
}

$dir = __DIR__;
$made = [];
foreach ([[192, false], [512, false], [512, true], [180, false], [32, false]] as [$size, $mask]) {
    $im = gildana_icon($size, $mask);
    $name = $mask ? "maskable-{$size}.png" : "icon-{$size}.png";
    imagepng($im, "$dir/$name", 9);
    imagedestroy($im);
    $made[] = $name;
}
copy("$dir/icon-180.png", "$dir/apple-touch-icon.png");
copy("$dir/icon-32.png",  "$dir/favicon.png");
echo "generated: " . implode(', ', $made) . ", apple-touch-icon.png, favicon.png\n";
