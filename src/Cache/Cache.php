<?php

declare(strict_types=1);

namespace Golovanov\Weft\Cache;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\ErrorHandler;

/**
 * Cache facade. Caching is OPTIONAL — when config('app.cache.enabled') is false, a NullStore is
 * used and every read is a miss, so wrapping code in remember() is always safe.
 *
 * Driver (config 'app.cache.driver'): 'file' (default, no deps; app.cache.path, a relative one
 * from APP_ROOT, default storage/cache) or 'redis' (keys under the site prefix plus "cache:", see
 * RedisStore::fromConfig()). flush() deletes cache entries only, never rate-limit counters. Values
 * come back without their classes, so cache arrays and scalars. If Redis is unreachable or rejects the
 * password or database, or a Redis command fails on a timeout or a lost connection, the facade
 * goes on with the file store rather than throwing. A store that fails otherwise (a read-only
 * replica, maxmemory under noeviction) never fails the request either: a read is a miss, a write
 * is skipped, and the first failure of the request is logged.
 *
 *   $tariffs = Cache::remember('tariffs', 600, fn() => $api->tariffs());
 */
final class Cache
{
    private static ?Store $store = null;

    /** A store failure is logged once per request, not on every cache call of the page. */
    private static bool $failureLogged = false;

    public static function store(): Store
    {
        // After a failed Redis command the request goes on with the file store (RedisStore::failed()).
        if (self::$store !== null && !(self::$store instanceof RedisStore && self::$store->failed())) {
            return self::$store;
        }

        if (!Config::get('app.cache.enabled', false)) {
            return self::$store = new NullStore();
        }

        $driver = (string) Config::get('app.cache.driver', 'file');
        try {
            self::$store = $driver === 'redis' ? self::makeRedis() : self::makeFile();
        } catch (\Throwable $e) {
            ErrorHandler::log('cache', 500, 'Cache init failed, falling back to file: ' . $e->getMessage(), __FILE__, __LINE__);
            self::$store = self::makeFile();
        }

        return self::$store;
    }

    /** Override the store (tests / custom drivers). */
    public static function use(Store $store): void
    {
        self::$store = $store;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::attempt(static fn () => self::store()->get($key)) ?? $default;
    }

    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        self::attempt(static fn () => self::store()->set($key, $value, $ttl));
    }

    public static function has(string $key): bool
    {
        return self::attempt(static fn () => self::store()->has($key)) === true;
    }

    public static function forget(string $key): void
    {
        self::attempt(static fn () => self::store()->forget($key));
    }

    public static function flush(): void
    {
        self::attempt(static fn () => self::store()->flush());
    }

    /**
     * Return the cached value for $key, or compute it with $callback, store it for $ttl seconds
     * and return it. A throw from $callback propagates; a store failure does not.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $store = self::store();
        $cached = self::attempt(static fn () => $store->get($key));
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        if ($value !== null) {
            self::attempt(static fn () => $store->set($key, $value, $ttl));
        }
        return $value;
    }

    /**
     * Run one store call (never the remember() callback); if the store throws, log it once per
     * request, at the place it was thrown, and return null.
     */
    private static function attempt(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            if (!self::$failureLogged) {
                self::$failureLogged = true;
                ErrorHandler::log('cache', 500, 'Cache store failed, continuing without cache: ' . $e->getMessage(), $e->getFile(), $e->getLine());
            }
            return null;
        }
    }

    private static function makeFile(): FileStore
    {
        $dir = (string) Config::get('app.cache.path', '');
        return new FileStore(Config::path($dir !== '' ? $dir : 'storage/cache'));
    }

    private static function makeRedis(): RedisStore
    {
        return RedisStore::fromConfig('cache:');
    }
}
