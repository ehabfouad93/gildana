<?php
declare(strict_types=1);
/** /sitemap.xml (rewritten here by .htaccess): the public pages, with when each last changed. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
echo seo_sitemap_xml();
