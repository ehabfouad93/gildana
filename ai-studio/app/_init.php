<?php
declare(strict_types=1);
/** Shared prologue for every /app page: bootstrap, layout, and a login guard. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/view.php';
require dirname(__DIR__) . '/includes/render.php';
$USER = require_login();
