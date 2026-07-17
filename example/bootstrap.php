<?php

declare(strict_types=1);

/**
 * Site bootstrap — the single initialisation point shared by the web entry point
 * (public/index.php) and CLI scripts (scripts/*.php).
 *
 * Order matters:
 *   1. define APP_ROOT (site root)
 *   2. load the environment config (constants)
 *   3. Composer autoload (engine + site)
 *   4. apply the application timezone (before any dates/logs)
 *   5. load business config into Golovanov\Weft\Config\Config
 *   6. register error handlers
 *   7. site-specific wiring (App\Bootstrap)
 */

define('APP_ROOT', __DIR__);

// 1) Environment config: prefer real config.php, then local dev, then the template.
foreach (['/config/config.php', '/config/config.dev.php', '/config/config.example.php'] as $candidate) {
    if (is_file(APP_ROOT . $candidate)) {
        require APP_ROOT . $candidate;
        break;
    }
}

// 2) Autoloader.
require APP_ROOT . '/vendor/autoload.php';

use App\Bootstrap;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Support\Clock;

// 3) Timezone → 4) business config → 5) error handlers.
Clock::applyDefaultTimezone();
Config::load(APP_ROOT . '/app/Configs');
ErrorHandler::run();

// 6) Site wiring (DB error notifier, custom services, ...).
if (class_exists(Bootstrap::class)) {
    Bootstrap::init();
}
