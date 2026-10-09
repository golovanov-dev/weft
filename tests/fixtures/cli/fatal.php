<?php

declare(strict_types=1);

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\Observability\ErrorHandler;

/**
 * Fixture for ErrorHandlerTest: a CLI script that dies of a fatal error (memory exhausted) while
 * the engine's error handler is registered and alerts are on. Run as a subprocess: a fatal error
 * ends the process. Logs go under the directory given as the first argument; the alert the
 * shutdown handler sends is printed as its JSON body instead of reaching a network.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('APP_ROOT', __DIR__);
define('LOGS_PATH', $argv[1]);

Config::set('app.alerts.channels', ['alertloop']);
Config::set('app.alertloop', ['base_url' => 'https://alerts.example', 'api_key' => 'k']);
Config::set('app.rate_limit.path', $argv[1] . '/counters');
(new ReflectionProperty(HttpClient::class, 'transport'))->setValue(null, static function (array $request): array {
    echo $request['body'], "\n";
    return ['status' => 202, 'headers' => [], 'body' => '', 'error' => ''];
});

ErrorHandler::run();

ini_set('memory_limit', '16M');
str_repeat('x', 64 * 1024 * 1024);
