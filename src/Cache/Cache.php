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
 * from APP_ROOT, default storage/cache) or 'redis'. If Redis is unreachable or rejects the
 * password or database, the facade degrades to the file store rather than throwing.
 *
 *   $tariffs = Cache::remember('tariffs', 600, fn() => $api->tariffs());
 */
final class Cache
{
    private static ?Store $store = null;

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
        $value = self::store()->get($key);
        return $value ?? $default;
    }

    public static function set(string $key, mixed $value, int $ttl = 0): void
    {
        self::store()->set($key, $value, $ttl);
    }

    public static function has(string $key): bool
    {
        return self::store()->has($key);
    }

    public static function forget(string $key): void
    {
        self::store()->forget($key);
    }

    public static function flush(): void
    {
        self::store()->flush();
    }

    /**
     * Return the cached value for $key, or compute it with $callback, store it for $ttl seconds
     * and return it.
     *
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $store = self::store();
        $cached = $store->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $callback();
        if ($value !== null) {
            $store->set($key, $value, $ttl);
        }
        return $value;
    }

    private static function makeFile(): FileStore
    {
        $dir = (string) Config::get('app.cache.path', '');
        return new FileStore(Config::path($dir !== '' ? $dir : 'storage/cache'));
    }

    private static function makeRedis(): RedisStore
    {
        return new RedisStore(
            host: (string) Config::env('REDIS_HOST', '127.0.0.1'),
            port: (int) Config::env('REDIS_PORT', 6379),
            password: (string) Config::env('REDIS_PASSWORD', ''),
            database: (int) Config::env('REDIS_DB', 0),
            prefix: (string) Config::get('app.cache.prefix', 'weft:'),
        );
    }
}
