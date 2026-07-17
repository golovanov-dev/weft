<?php

declare(strict_types=1);

/**
 * CLI health check:  php scripts/health.php
 * Reports PHP version, writable storage, and whether optional integrations are wired.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Golovanov\Weft\Config\Config;

$logs = (string) Config::env('LOGS_PATH', '');
$cache = (string) Config::get('app.cache.path', '');

$report = [
    'ok' => true,
    'php' => PHP_VERSION,
    'timezone' => date_default_timezone_get(),
    'debug' => (bool) Config::env('DEBUG', false),
    'dev_mode' => (bool) Config::env('DEV_MODE', false),
    'logs_writable' => $logs !== '' && (is_dir($logs) ? is_writable($logs) : @mkdir($logs, 0770, true)),
    'traceloom' => class_exists(\Golovanov\Traceloom\Tracer::class),
    'multilingual' => (bool) Config::get('app.locales.enabled', false),
    'cache_enabled' => (bool) Config::get('app.cache.enabled', false),
    'captcha_enabled' => (bool) Config::get('app.captcha.enabled', false),
    'alertloop_enabled' => (bool) Config::get('app.alertloop.enabled', false),
];

$report['ok'] = $report['logs_writable'];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($report['ok'] ? 0 : 1);
