<?php
declare(strict_types=1);
/** /robots.txt (rewritten here by .htaccess): what crawlers, including AI ones, may read. Admin → SEO. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
echo seo_robots_txt();
