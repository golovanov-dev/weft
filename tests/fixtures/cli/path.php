<?php

declare(strict_types=1);

/**
 * Fixture for ConfigTest: prints Config::path('storage/cache') with APP_ROOT defined empty and
 * the working directory set to the first argument.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('APP_ROOT', '');
chdir($argv[1]);

echo Golovanov\Weft\Config\Config::path('storage/cache'), "\n";
