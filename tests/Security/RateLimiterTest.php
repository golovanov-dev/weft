<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Cache\RedisStore;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Security\RateLimiter;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class RateLimiterTest extends TestCase
{
    public function testAllowLimitsAClientWithoutASessionWhileCachingIsOff(): void
    {
        Config::set('app.cache.enabled', false);

        $this->assertTrue(RateLimiter::allow('form', 2, 60));
        $_SESSION = [];
        $this->assertTrue(RateLimiter::allow('form', 2, 60));
        $_SESSION = [];
        $this->assertFalse(RateLimiter::allow('form', 2, 60));
        $this->assertTrue(RateLimiter::allow('other', 2, 60));
    }

    public function testCountersDefaultToASubdirectoryOfTheCacheThatAFlushLeaves(): void
    {
        $cacheDir = $this->makeTempDir();
        Config::set('app.rate_limit', ['max' => 1, 'window' => 60]);
        Config::set('app.cache', ['enabled' => true, 'driver' => 'file', 'path' => $cacheDir]);

        try {
            $this->assertTrue(RateLimiter::allow('cfg'));
            $this->assertCount(1, glob($cacheDir . '/ratelimit/*.cache') ?: []);
            Cache::flush();
            $this->assertFalse(RateLimiter::allow('cfg'), 'cache-clear must not reset the limits');
        } finally {
            $this->removeDir($cacheDir);
        }
    }

    public function testCountsPerClientIpAndAnIpv6ClientByItsSlash64(): void
    {
        $allow = static function (string $ip): bool {
            RequestContext::setRequest(new Request('POST', '/api/contact', '/api/contact', $ip, [], [], [], null));
            return RateLimiter::allow('form', 1, 60);
        };

        $this->assertTrue($allow('2001:db8:1:2::1'));
        $this->assertFalse($allow('2001:db8:1:2:ffff:ffff:ffff:9'), 'the same /64 is one client');
        $this->assertTrue($allow('2001:db8:1:3::1'));
        $this->assertTrue($allow('203.0.113.5'));
        $this->assertFalse($allow('::ffff:203.0.113.5'), 'an IPv4-mapped address is its IPv4 client');
        $this->assertTrue($allow('203.0.113.6'));
    }

    #[RunInSeparateProcess]
    public function testBehindATrustedProxyCountsTheClientFromXForwardedFor(): void
    {
        define('PROXY_IPS', ['10.0.0.1']);
        $allow = static function (string $forwardedFor): bool {
            $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
            RequestContext::setRequest(Request::fromGlobals());
            return RateLimiter::allow('form', 1, 60);
        };

        $this->assertTrue($allow('198.51.100.7'));
        $this->assertFalse($allow('198.51.100.7'));
        $this->assertTrue($allow('198.51.100.8'), 'another visitor through the same proxy has its own counter');
    }

    public function testNonPositiveMaxDisablesTheLimit(): void
    {
        $this->assertTrue(RateLimiter::allow('open', 0, 60));
        $this->assertTrue(RateLimiter::allow('open', 0, 60));
    }

    public function testThrottleAllowsOncePerInterval(): void
    {
        $this->assertTrue(RateLimiter::throttle('submit', 5));
        $_SESSION = [];
        $this->assertFalse(RateLimiter::throttle('submit', 5));
        $this->assertTrue(RateLimiter::throttle('other', 5));
    }

    public function testFailsOpenWhenTheCounterCannotBeWritten(): void
    {
        // A file where the counter directory should be.
        Config::set('app.rate_limit.path', __FILE__);

        $this->assertTrue(RateLimiter::allow('form', 1, 60));
        $this->assertTrue(RateLimiter::allow('form', 1, 60));
    }

    public function testStoreStatusReportsTheCounterDirectory(): void
    {
        $this->assertSame(
            ['store' => 'file', 'path' => Config::get('app.rate_limit.path'), 'writable' => true, 'error' => null],
            RateLimiter::storeStatus(),
        );
    }

    public function testStoreStatusReportsAnUnwritableCounterDirectory(): void
    {
        // A file where the counter directory should be.
        Config::set('app.rate_limit.path', __FILE__);

        $this->assertSame(
            ['store' => 'file', 'path' => __FILE__, 'writable' => false, 'error' => 'PHP cannot write ' . __FILE__],
            RateLimiter::storeStatus(),
        );
    }

    public function testRelativeCounterPathIsResolvedFromAppRoot(): void
    {
        $relative = 'ratelimit-' . bin2hex(random_bytes(4));
        Config::set('app.rate_limit.path', $relative);

        try {
            $this->assertSame(
                ['store' => 'file', 'path' => APP_ROOT . '/' . $relative, 'writable' => true, 'error' => null],
                RateLimiter::storeStatus(),
            );
            $this->assertDirectoryDoesNotExist(APP_ROOT . '/' . $relative, 'a health check creates nothing');
            $this->assertTrue(RateLimiter::allow('rel', 1, 60));
            $this->assertCount(1, glob(APP_ROOT . '/' . $relative . '/*.cache') ?: []);
        } finally {
            $this->removeDir(APP_ROOT . '/' . $relative);
        }
    }

    public function testFailsOpenAndLogsWhenRedisRejectsTheIncrement(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // phpredis 6 throws on an error reply to EVAL (read-only replica, maxmemory under noeviction).
        // A client that reports itself connected is used as is; close() must not reach for a server.
        $redis = new class extends \Redis {
            public function isConnected(): bool
            {
                return true;
            }

            public function close(): bool
            {
                return true;
            }

            public function eval(string $script, array $args = [], int $num_keys = 0): mixed
            {
                throw new \RedisException("READONLY You can't write against a read only replica.");
            }
        };
        (new \ReflectionProperty(RedisStore::class, 'connection'))->setValue(null, $redis);
        Config::set('app.cache.driver', 'redis');
        $logOffset = self::errorLogSize();

        $this->assertTrue(RateLimiter::allow('form', 1, 60));
        $this->assertTrue(RateLimiter::allow('form', 1, 60));
        $this->assertStringContainsString(
            "Rate limit failed open: READONLY You can't write against a read only replica.",
            self::errorLogSince($logOffset),
        );
    }

    public function testHoneypotDetectsFilledHiddenField(): void
    {
        $this->assertTrue(RateLimiter::honeypot(['website' => 'spam']));
        $this->assertFalse(RateLimiter::honeypot(['website' => '   ']));
        $this->assertFalse(RateLimiter::honeypot(['website' => '']));
        $this->assertFalse(RateLimiter::honeypot([]));
        $this->assertTrue(RateLimiter::honeypot(['custom' => 'x'], 'custom'));
    }

    /** A field posted as an array (website[]=…) is filled when it holds anything, with no warning. */
    public function testHoneypotTakesAnArrayFieldWithoutAWarning(): void
    {
        $logOffset = self::errorLogSize();

        $this->assertTrue(RateLimiter::honeypot(['website' => ['x']]));
        $this->assertTrue(RateLimiter::honeypot(['website' => ['']]));
        $this->assertFalse(RateLimiter::honeypot(['website' => []]));
        $this->assertSame('', self::errorLogSince($logOffset));
    }
}
