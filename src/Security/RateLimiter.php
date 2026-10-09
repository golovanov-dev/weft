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
 *   - allow(): fixed-window counter keyed by bucket + client IP (an IPv6 client by its /64).
 *   - throttle(): at most one request per interval for a bucket + client IP.
 *   - honeypot(): treat a filled hidden field as a bot.
 *
 * Counters live in Redis when app.cache.driver is 'redis' (on the cache's connection, under the
 * site prefix but outside the cache's keys), otherwise in files under app.rate_limit.path
 * (relative to APP_ROOT; default: ratelimit/ in the cache directory) — whether or not caching is
 * enabled. Cache::flush() leaves them. Each increment is atomic and the window starts at the first
 * hit; later hits never extend it. One file increment in PRUNE_CHANCE deletes the expired counter
 * files. If the counter cannot be written, the request is allowed (fail-open) and the failure is
 * logged.
 *
 * Alert keeps its "already reported" window in the same store (claim()).
 */
final class RateLimiter
{
    /** One file increment in this many also deletes the expired counter files. */
    private const PRUNE_CHANCE = 100;

    private static FileStore|RedisStore|null $store = null;

    /** Why Redis was not used, when store() fell back to files. */
    private static ?string $redisError = null;

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

    /**
     * True when a honeypot field is filled — treat the sender as a bot. The field is taken as it
     * came: a non-empty array (`website[]=…`) is filled too.
     */
    public static function honeypot(array $body, string $field = 'website'): bool
    {
        $value = $body[$field] ?? null;
        if (is_array($value)) {
            return $value !== [];
        }
        return is_scalar($value) && trim((string) $value) !== '';
    }

    /**
     * Where counters are kept and whether they can be written, for health checks: the store
     * allow() and throttle() use, with the same fallback from Redis to files. `path` is the
     * counter directory, null for Redis; `writable` is false when PHP cannot write the store. For
     * Redis it is a real write: a probe counter with a short TTL, deleted afterwards. For files it
     * only checks, creating nothing: existing files in the directory must be writable too, and a
     * missing directory counts as writable when PHP can create it. `error` says what is wrong: why
     * Redis was not used or rejected the probe, or what PHP cannot write.
     *
     * @return array{store: 'redis'|'file', path: ?string, writable: bool, error: ?string}
     */
    public static function storeStatus(): array
    {
        $store = self::store();
        if ($store instanceof RedisStore) {
            try {
                $error = $store->increment('health:probe', 10) === null
                    ? 'Redis rejected the probe write: ' . ($store->client()->getLastError() ?? 'no reason given')
                    : null;
                $store->forget('health:probe');
            } catch (\Throwable $e) {
                $error = 'Redis rejected the probe write: ' . $e->getMessage();
            }
            return ['store' => 'redis', 'path' => null, 'writable' => $error === null, 'error' => $error];
        }

        $dir = self::directory();
        // A counter file PHP cannot overwrite fails open just like an unwritable directory.
        $unwritable = ErrorHandler::unwritable($dir, array_map('basename', glob($dir . '/*.cache') ?: []));
        $errors = array_filter([
            self::$redisError !== null ? 'Redis not used: ' . self::$redisError : null,
            match (true) {
                $unwritable === [] => null,
                $unwritable === [$dir] => 'PHP cannot write ' . $dir,
                default => 'PHP cannot write ' . count($unwritable) . ' file(s) in ' . $dir
                    . ', e.g. ' . basename($unwritable[0]),
            },
        ]);
        return [
            'store' => 'file',
            'path' => $dir,
            'writable' => $unwritable === [],
            'error' => $errors !== [] ? implode('; ', $errors) : null,
        ];
    }

    /**
     * True the first time $key is claimed within $seconds, and when the store is unavailable
     * (fail-open, logged). Not per client: one claim for the whole site.
     *
     * @internal Used by Alert for its "already reported" window.
     */
    public static function claim(string $key, int $seconds): bool
    {
        $count = self::increment('claim:' . $key, $seconds, 'Claim failed open');
        return $count === null || $count <= 1;
    }

    /**
     * Drop a claim, so the next claim() of $key succeeds. Best-effort: a store error is ignored.
     *
     * @internal Used by Alert.
     */
    public static function release(string $key): void
    {
        try {
            self::store()->forget('claim:' . $key);
        } catch (\Throwable) {
            // The claim then simply expires with its window.
        }
    }

    /** Count one hit for the current client; null when the counter is unavailable. */
    private static function hit(string $prefix, int $window): ?int
    {
        $ip = RequestContext::request()?->clientIp ?? ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        return self::increment($prefix . ':' . self::client($ip), $window, 'Rate limit failed open');
    }

    /**
     * The counted client: an IPv4 address as is, an IPv6 one as its /64 network ("2001:db8:1:2::/64"),
     * since one host gets a whole /64; an IPv4-mapped IPv6 address as its IPv4 address.
     */
    private static function client(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }
        $packed = (string) inet_pton($ip);
        if (bin2hex(substr($packed, 0, 12)) === '00000000000000000000ffff') {
            return (string) inet_ntop(substr($packed, 12));
        }
        return inet_ntop(substr($packed, 0, 8) . str_repeat(chr(0), 8)) . '/64';
    }

    /** Increment a counter; null, with a line in the error log, when it is unavailable. */
    private static function increment(string $key, int $window, string $failure): ?int
    {
        try {
            $store = self::store();
            $count = $store->increment($key, $window);
            $error = 'counter not writable';
            if ($store instanceof FileStore && random_int(1, self::PRUNE_CHANCE) === 1) {
                $store->prune();
            }
        } catch (\Throwable $e) {
            $count = null;
            $error = $e->getMessage();
        }
        if ($count === null) {
            ErrorHandler::log('rate_limit', 500, $failure . ': ' . $error, __FILE__, __LINE__);
        }
        return $count;
    }

    private static function store(): FileStore|RedisStore
    {
        // After a failed Redis command the request goes on with files (RedisStore::failed()).
        if (self::$store !== null && !(self::$store instanceof RedisStore && self::$store->failed())) {
            return self::$store;
        }

        if ((string) Config::get('app.cache.driver', 'file') === 'redis') {
            try {
                return self::$store = RedisStore::fromConfig();
            } catch (\Throwable $e) {
                self::$redisError = $e->getMessage();
                ErrorHandler::log('rate_limit', 500, 'Redis unavailable, rate limits fall back to files: ' . $e->getMessage(), __FILE__, __LINE__);
            }
        }

        return self::$store = new FileStore(self::directory());
    }

    /**
     * Counter directory: app.rate_limit.path, else ratelimit/ in the cache directory (app.cache.path,
     * else storage/cache; see Config::path()), where Cache::flush() does not reach.
     */
    private static function directory(): string
    {
        $dir = (string) Config::get('app.rate_limit.path', '');
        if ($dir !== '') {
            return Config::path($dir);
        }
        $cache = (string) Config::get('app.cache.path', '');
        return rtrim(Config::path($cache !== '' ? $cache : 'storage/cache'), '/\\') . '/ratelimit';
    }
}
