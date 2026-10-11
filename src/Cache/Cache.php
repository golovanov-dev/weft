<?php

declare(strict_types=1);

namespace Golovanov\Weft\Cache;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\ErrorHandler;

/**
 * Cache facade. Caching is OPTIONAL — when config('app.cache.enabled') is false, a NullStore is
 * used and every read is a miss, so wrapping code in remember() is always safe.
 *
 * Driver (config 'app.cache.driver'): 'file' (default, in directory()) or 'redis' (keys under the
 * site prefix plus "cache:", see RedisStore::fromConfig()). flush() deletes cache entries only,
 * never rate-limit counters. Values come back without their classes, so cache arrays and scalars.
 * A store failure never fails the request: the read is a miss, the write is skipped, the first
 * failure of the request is logged, and when Redis fails the rest of the request uses the file store.
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
        if (self::$store !== null) {
            return self::$store;
        }

        if (!Config::get('app.cache.enabled', false)) {
            return self::$store = new NullStore();
        }

        $driver = (string) Config::get('app.cache.driver', 'file');
        try {
            self::$store = $driver === 'redis' ? RedisStore::fromConfig('cache:') : self::makeFile();
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
        $cached = self::attempt(static fn () => self::store()->get($key));
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        if ($value !== null) {
            self::attempt(static fn () => self::store()->set($key, $value, $ttl));
        }
        return $value;
    }

    /**
     * Run one store call (never the remember() callback); if the store throws, log it once per
     * request, at the place it was thrown, and return null. A failed Redis store is replaced with
     * the file store for the rest of the request.
     */
    private static function attempt(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            if (self::$store instanceof RedisStore) {
                self::$store = self::makeFile();
            }
            if (!self::$failureLogged) {
                self::$failureLogged = true;
                ErrorHandler::log('cache', 500, 'Cache store failed, continuing without cache: ' . $e->getMessage(), $e->getFile(), $e->getLine());
            }
            return null;
        }
    }

    /**
     * The file store's directory: app.cache.path (relative to APP_ROOT, see Config::path()), else
     * storage/cache.
     *
     * @internal Also used by RateLimiter.
     */
    public static function directory(): string
    {
        $dir = (string) Config::get('app.cache.path', '');
        return Config::path($dir !== '' ? $dir : 'storage/cache');
    }

    private static function makeFile(): FileStore
    {
        return new FileStore(self::directory());
    }
}
