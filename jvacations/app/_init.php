<?php
declare(strict_types=1);

/** Shared prologue for every /app page: bootstrap, chrome, login guard. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/view.php';

/** @var array $ME */
$ME = require_role();
