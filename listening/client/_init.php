<?php
declare(strict_types=1);

/** Shared prologue for every /client page: bootstrap, chrome, tenancy guard. */
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

/** @var array $ME, array $CLIENT */
[$ME, $CLIENT] = require_client();

/** Client-scoped header: adds the impersonation banner when an admin is inside. */
function client_header(string $title, string $active, array $client): void
{
    layout_header($title, 'client', $active);
    if (is_impersonating()) {
        echo '<div class="impersonate-bar">'
           . e(t('ad.impersonating', ['name' => (string) $client['name']]))
           . ' <a href="../admin/exit_workspace.php">' . e(t('ad.exit_ws')) . ' →</a>'
           . '</div>';
    }
}
