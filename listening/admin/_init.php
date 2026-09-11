<?php
declare(strict_types=1);

/** Shared prologue for every /admin page: bootstrap, chrome, admin guard. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/view.php';
require dirname(__DIR__) . '/includes/connectors.php';
require dirname(__DIR__) . '/includes/keywords.php';
require dirname(__DIR__) . '/includes/sentiment.php';
require dirname(__DIR__) . '/includes/render.php';
require dirname(__DIR__) . '/includes/chart.php';
require dirname(__DIR__) . '/includes/metrics.php';
require dirname(__DIR__) . '/includes/http.php';
require dirname(__DIR__) . '/includes/ingest.php';
require dirname(__DIR__) . '/includes/ai.php';
require dirname(__DIR__) . '/includes/notify.php';
require dirname(__DIR__) . '/includes/alerts.php';

/** @var array $ME */
$ME = require_admin();

/** True when the background worker has run recently enough to trust. */
function worker_alive(int $maxAgeSeconds = 900): bool
{
    $hb = dirname(__DIR__) . '/cron/.heartbeat';
    $ts = @filemtime($hb);
    return $ts !== false && $ts > time() - $maxAgeSeconds;
}
