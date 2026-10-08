<?php

declare(strict_types=1);

namespace Golovanov\Weft\Security;

use Golovanov\Weft\Cache\FileStore;
use Golovanov\Weft\Cache\RedisStore;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Observability\ErrorHandler;

/**
 * Rate limiting — ON by default (a sensible baseline for every form/API endpoint).
 *
 *   - allow(): fixed-window counter keyed by bucket + client IP.
 *   - throttle(): at most one request per interval for a bucket + client IP.
 *   - honeypot(): treat a filled hidden field as a bot.
 *
 * Counters live in Redis when app.cache.driver is 'redis', otherwise in files under
 * app.rate_limit.path (default: app.cache.path) — whether or not caching is enabled. Each
 * increment is atomic and the window starts at the first hit; later hits never extend it. If the
 * counter cannot be written, the request is allowed (fail-open) and the failure is logged.
 */
final class RateLimiter
{
    private static FileStore|RedisStore|null $store = null;

    /**
     * @return bool true if the request is allowed (under the limit)
     */
    public static function allow(string $bucket, ?int $max = null, ?int $window = null): bool
    {
        $max = $max ?? (int) Config::get('app.rate_limit.max', 10);
        $window = $window ?? (int) Config::get('app.rate_limit.window', 60);
        if ($max <= 0) {
            return true;
        }

        $count = self::hit('ratelimit:' . $bucket, $window);
        return $count === null || $count <= $max;
    }

    /**
     * Minimum-interval throttle: allow at most one request per $intervalSeconds for a bucket.
     * Handy for form submits ("no faster than every 5s").
     */
    public static function throttle(string $bucket, int $intervalSeconds = 5): bool
    {
        if ($intervalSeconds <= 0) {
            return true;
        }

        $count = self::hit('throttle:' . $bucket, $intervalSeconds);
        return $count === null || $count <= 1;
    }

    /** True when a honeypot field is filled — treat the sender as a bot. */
    public static function honeypot(array $body, string $field = 'website'): bool
    {
        return isset($body[$field]) && trim((string) $body[$field]) !== '';
    }

    /** Count one hit for the current client; null when the counter is unavailable. */
    private static function hit(string $prefix, int $window): ?int
    {
        $ip = RequestContext::request()?->clientIp ?? ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        try {
            $count = self::store()->increment($prefix . ':' . $ip, $window);
            $error = 'counter not writable';
        } catch (\Throwable $e) {
            $count = null;
            $error = $e->getMessage();
        }
        if ($count === null) {
            ErrorHandler::log('rate_limit', 500, 'Rate limit failed open: ' . $error, __FILE__, __LINE__);
        }
        return $count;
    }

    private static function store(): FileStore|RedisStore
    {
        if (self::$store !== null) {
            return self::$store;
        }

        if ((string) Config::get('app.cache.driver', 'file') === 'redis') {
            try {
                return self::$store = new RedisStore(
                    host: (string) Config::env('REDIS_HOST', '127.0.0.1'),
                    port: (int) Config::env('REDIS_PORT', 6379),
                    password: (string) Config::env('REDIS_PASSWORD', ''),
                    database: (int) Config::env('REDIS_DB', 0),
                    prefix: (string) Config::get('app.cache.prefix', 'weft:'),
                );
            } catch (\Throwable $e) {
                ErrorHandler::log('rate_limit', 500, 'Redis unavailable, rate limits fall back to files: ' . $e->getMessage(), __FILE__, __LINE__);
            }
        }

        $dir = (string) Config::get('app.rate_limit.path', '');
        if ($dir === '') {
            $dir = (string) Config::get('app.cache.path', '');
        }
        if ($dir === '') {
            $dir = (string) Config::env('APP_ROOT', sys_get_temp_dir()) . '/storage/cache';
        }
        return self::$store = new FileStore($dir);
    }
}
