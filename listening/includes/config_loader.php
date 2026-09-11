<?php
declare(strict_types=1);

/**
 * Loads config.php once and returns the config array.
 */
function config(?string $key = null, $default = null)
{
    static $config = null;
    if ($config === null) {
        $file = dirname(__DIR__) . '/config.php';
        if (!file_exists($file)) {
            http_response_code(500);
            exit('Missing config.php — copy config.sample.php to config.php and fill it in.');
        }
        $config = require $file;
    }
    if ($key === null) return $config;
    return $config[$key] ?? $default;
}
