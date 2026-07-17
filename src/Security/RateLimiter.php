<?php

declare(strict_types=1);

namespace Golovanov\Weft\Security;

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;

/**
 * Rate limiting — ON by default (a sensible baseline for every form/API endpoint).
 *
 * Two complementary tools:
 *   - allow(): fixed-window counter keyed by bucket + client IP. Backed by the cache store when
 *     caching is enabled, otherwise by the PHP session. Fails OPEN if no backend is available,
 *     so a missing Redis never locks users out.
 *   - honeypot(): treat a filled hidden field as a bot.
 */
final class RateLimiter
{
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

        $ip = RequestContext::request()?->clientIp ?? ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $key = 'ratelimit:' . $bucket . ':' . $ip;

        if (Config::get('app.cache.enabled', false)) {
            return self::allowViaCache($key, $max, $window);
        }
        return self::allowViaSession($bucket . ':' . $ip, $max, $window);
    }

    /**
     * Minimum-interval throttle: allow at most one request per $intervalSeconds for a bucket.
     * Handy for form submits ("no faster than every 5s").
     */
    public static function throttle(string $bucket, int $intervalSeconds = 5): bool
    {
        self::startSession();
        $ip = RequestContext::request()?->clientIp ?? ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $slot = &$_SESSION['weft_throttle'][$bucket . ':' . $ip];
        $last = isset($slot) ? (int) $slot : 0;
        if (time() - $last < $intervalSeconds) {
            return false;
        }
        $slot = time();
        return true;
    }

    /** True when a honeypot field is filled — treat the sender as a bot. */
    public static function honeypot(array $body, string $field = 'website'): bool
    {
        return isset($body[$field]) && trim((string) $body[$field]) !== '';
    }

    private static function allowViaCache(string $key, int $max, int $window): bool
    {
        $store = Cache::store();
        $count = (int) ($store->get($key) ?? 0);
        if ($count >= $max) {
            return false;
        }
        // Reset TTL on the first hit of a window; subsequent hits keep counting within it.
        $store->set($key, $count + 1, $window);
        return true;
    }

    private static function allowViaSession(string $key, int $max, int $window): bool
    {
        self::startSession();
        $now = time();
        $slot = &$_SESSION['weft_rate'][$key];
        if (!isset($slot) || !is_array($slot) || ($slot['reset'] ?? 0) < $now) {
            $slot = ['count' => 0, 'reset' => $now + $window];
        }
        if ($slot['count'] >= $max) {
            return false;
        }
        $slot['count']++;
        return true;
    }

    private static function startSession(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}
