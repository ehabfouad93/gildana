<?php
declare(strict_types=1);
/** /llms.txt and /llms-full.txt (rewritten here by .htaccess): the product in plain words, for AI assistants. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/seo.php';
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');
echo seo_llms_txt(!empty($_GET['full']));
