<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

/**
 * Self-contained image CAPTCHA (GD). Renders a distorted 5-character code,
 * stores it in the session, and streams a PNG. No third-party service required.
 */

// Unambiguous alphabet (no 0/O, 1/I/L).
$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
$code = '';
for ($i = 0; $i < 5; $i++) {
    $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}
$_SESSION['captcha_code'] = $code;
$_SESSION['captcha_time'] = time();

if (!function_exists('imagecreatetruecolor')) {
    // GD unavailable — send a valid 1x1 PNG. The login check auto-disables (see captcha_enabled()).
    header('Content-Type: image/png');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC');
    exit;
}

$w = 200; $h = 70;

// Draw the code small, then scale up for chunky, resample-blurred glyphs.
$txt = imagecreatetruecolor(96, 24);
$paper = imagecolorallocate($txt, 250, 243, 227);
imagefilledrectangle($txt, 0, 0, 96, 24, $paper);
for ($i = 0; $i < strlen($code); $i++) {
    $col = imagecolorallocate($txt, random_int(60, 150), random_int(45, 110), random_int(15, 60));
    imagestring($txt, 5, 6 + $i * 18, random_int(1, 4), $code[$i], $col);
}
$big = imagescale($txt, $w - 20, $h - 24); // bicubic upscale
imagedestroy($txt);

// Canvas with paper bg + noise.
$img = imagecreatetruecolor($w, $h);
$bg = imagecolorallocate($img, 250, 243, 227);
imagefilledrectangle($img, 0, 0, $w, $h, $bg);
for ($i = 0; $i < 500; $i++) {
    $c = imagecolorallocate($img, random_int(205, 235), random_int(190, 220), random_int(150, 195));
    imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
}
for ($i = 0; $i < 6; $i++) {
    $c = imagecolorallocate($img, random_int(190, 220), random_int(150, 185), random_int(90, 140));
    imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
}
imagecopy($img, $big, 10, 12, 0, 0, imagesx($big), imagesy($big));
imagedestroy($big);

// Vertical wave distortion so the glyphs aren't cleanly template-matchable.
$out   = imagecreatetruecolor($w, $h);
imagefilledrectangle($out, 0, 0, $w, $h, $bg);
$amp   = random_int(3, 5);
$per   = random_int(24, 40);
$phase = (random_int(0, 100) / 100) * 2 * M_PI;
for ($x = 0; $x < $w; $x++) {
    $dy = (int) round($amp * sin($x / $per * 2 * M_PI + $phase));
    imagecopy($out, $img, $x, $dy, $x, 0, 1, $h);
}
imagedestroy($img);

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
imagepng($out);
imagedestroy($out);
