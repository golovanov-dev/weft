<?php

declare(strict_types=1);

/**
 * CLI health check:  php scripts/health.php
 * Reports PHP version, writable storage, and whether optional integrations are wired.
 * Exits with 1 when something leaves the site broken or unprotected: logs or rate-limit counters
 * not writable, counters in files while app.cache.driver is redis (rate_limit_error says why),
 * PROXY_IPS not an array or with an entry that is invalid or too wide, or (outside DEBUG) a
 * captcha enabled without its keys.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Security\Captcha;
use Golovanov\Weft\Security\RateLimiter;

$logs = (string) Config::env('LOGS_PATH', '');
$rateLimit = RateLimiter::storeStatus();

$report = [
    'ok' => true,
    'php' => PHP_VERSION,
    'timezone' => date_default_timezone_get(),
    'debug' => (bool) Config::env('DEBUG', false),
    'dev_mode' => (bool) Config::env('DEV_MODE', false),
    'logs_writable' => $logs !== '' && (is_dir($logs) ? is_writable($logs) : @mkdir($logs, 0770, true)),
    'cache_driver' => (string) Config::get('app.cache.driver', 'file'),
    'rate_limit_store' => $rateLimit['store'],
    'rate_limit_path' => $rateLimit['path'],
    'rate_limit_writable' => $rateLimit['writable'],
    'rate_limit_error' => $rateLimit['error'],
    'proxy_ips_error' => Request::proxyIpsError(),
    'proxy_ips_invalid' => Request::invalidProxyIps(),
    'proxy_ips_trust_all' => Request::trustAllProxyIps(),
    'traceloom' => class_exists(\Golovanov\Traceloom\Tracer::class),
    'multilingual' => (bool) Config::get('app.locales.enabled', false),
    'cache_enabled' => (bool) Config::get('app.cache.enabled', false),
    'captcha_enabled' => (bool) Config::get('app.captcha.enabled', false),
    'captcha_missing_keys' => Captcha::missingKeys(),
    'alertloop_enabled' => (bool) Config::get('app.alertloop.enabled', false),
];

$report['ok'] = $report['logs_writable']
    && $report['rate_limit_writable']
    && !($report['cache_driver'] === 'redis' && $report['rate_limit_store'] === 'file')
    && $report['proxy_ips_error'] === null
    && $report['proxy_ips_invalid'] === []
    && $report['proxy_ips_trust_all'] === []
    && ($report['captcha_missing_keys'] === [] || $report['debug']);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($report['ok'] ? 0 : 1);
