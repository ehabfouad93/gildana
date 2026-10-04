<?php
declare(strict_types=1);

/**
 * The top bar and footer of the public pages that are not the landing page — the request form
 * on its own (request.php) and the Help Center — so they read as the same site.
 */
require_once __DIR__ . '/seo.php';

function site_page_open(array $seo): void
{
    $logoH = site_logo_height();
    $footH = max(20, (int) round($logoH * 0.8)); ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?= seo_head($seo) ?>
<meta name="theme-color" content="#0B1020">
<link rel="icon" href="assets/icons/favicon.png">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="assets/site.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/site.css') ?: '1' ?>">
</head>
<body style="--logo-h:<?= $logoH ?>px;--foot-logo-h:<?= $footH ?>px">
<header class="nav">
  <div class="wrap nav-in">
    <a class="nav-logo" href="./" aria-label="<?= e(brand_name()) ?> home"><?= brand_logo('full', $logoH, './', false) ?></a>
    <nav class="nav-links" id="nav-links">
      <a href="./#what">What it does</a>
      <a href="./#qualifier">Lead Qualifier</a>
      <a href="help-center.php">Help Center</a>
    </nav>
    <div class="nav-cta">
      <a class="signin" href="login.php">Log in</a>
      <a class="btn btn-primary btn-sm" href="request.php">Get started</a>
    </div>
  </div>
</header>
<?php
}

function site_page_close(): void
{ ?>
<footer class="foot">
  <div class="wrap">
    <div class="foot-in">
      <div style="color:#fff"><?= brand_logo('full', max(20, (int) round(site_logo_height() * 0.8)), './', true) ?></div>
      <nav class="foot-links">
        <a href="./">Home</a>
        <a href="./#what">What it does</a>
        <a href="./#pricing">Pricing</a>
        <a href="help-center.php">Help Center</a>
        <a href="request.php">Get started</a>
        <a href="login.php">Log in</a>
      </nav>
    </div>
    <div class="foot-legal">
      © <?= date('Y') ?> <?= e(BRAND_PARENT) ?>. <?= e(brand_name()) ?> is a product of <?= e(BRAND_PARENT) ?>.
      Not affiliated with or endorsed by WhatsApp or Meta. WhatsApp is a trademark of Meta Platforms, Inc.
    </div>
  </div>
</footer>
</body>
</html>
<?php
}
