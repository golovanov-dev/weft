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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class RateLimiterTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->makeTempDir();
        Config::set('app.rate_limit.path', $this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

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
        $notADir = $this->dir . '/file';
        file_put_contents($notADir, '');
        Config::set('app.rate_limit.path', $notADir);

        $this->assertTrue(RateLimiter::allow('form', 1, 60));
        $this->assertTrue(RateLimiter::allow('form', 1, 60));
    }

    public function testStoreStatusReportsTheCounterDirectory(): void
    {
        $this->assertSame(
            ['store' => 'file', 'path' => $this->dir, 'writable' => true, 'error' => null],
            RateLimiter::storeStatus(),
        );
    }

    public function testStoreStatusReportsAnUnwritableCounterDirectory(): void
    {
        $notADir = $this->dir . '/file';
        file_put_contents($notADir, '');
        Config::set('app.rate_limit.path', $notADir);

        $this->assertSame(
            ['store' => 'file', 'path' => $notADir, 'writable' => false, 'error' => 'PHP cannot write ' . $notADir],
            RateLimiter::storeStatus(),
        );
    }

    public function testStoreStatusReportsUnwritableCounterFiles(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write a read-only file');
        }
        $this->assertTrue(RateLimiter::allow('form', 5, 60));
        $files = glob($this->dir . '/*.cache') ?: [];
        $this->assertCount(1, $files);
        chmod($files[0], 0444);

        try {
            $this->assertSame(
                [
                    'store' => 'file',
                    'path' => $this->dir,
                    'writable' => false,
                    'error' => 'PHP cannot write 1 file(s) in ' . $this->dir . ', e.g. ' . basename($files[0]),
                ],
                RateLimiter::storeStatus(),
            );
        } finally {
            chmod($files[0], 0644);
        }
    }

    public function testRelativeCounterPathIsResolvedFromAppRoot(): void
    {
        $relative = 'storage/ratelimit-' . bin2hex(random_bytes(4));
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

    #[RunInSeparateProcess]
    public function testStoreStatusFallsBackToFilesWhenRedisIsUnavailable(): void
    {
        // Nothing listens on port 1; without ext-redis the store fails even earlier.
        define('REDIS_PORT', 1);
        Config::set('app.cache.driver', 'redis');

        $status = RateLimiter::storeStatus();
        $this->assertSame(['store' => 'file', 'path' => $this->dir, 'writable' => true], array_slice($status, 0, 3));
        $this->assertStringStartsWith('Redis not used: ', (string) $status['error']);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function redisProbeResults(): array
    {
        return [
            'write accepted' => [1, true],
            'error reply (read-only replica, OOM, NOAUTH)' => [false, false],
            'exception' => [new \RedisException('READONLY'), false],
        ];
    }

    #[DataProvider('redisProbeResults')]
    public function testStoreStatusProbesRedisWithARealWrite(mixed $evalResult, bool $writable): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // No Redis server in tests: the client is replaced, the store is built without connecting.
        $redis = new class extends \Redis {
            public mixed $evalResult = null;
            /** @var list<string> */
            public array $written = [];
            /** @var list<string> */
            public array $deleted = [];

            public function eval(string $script, array $args = [], int $num_keys = 0): mixed
            {
                $this->written[] = $args[0] . ' ttl ' . $args[1];
                if ($this->evalResult instanceof \Throwable) {
                    throw $this->evalResult;
                }
                return $this->evalResult;
            }

            public function del(array|string $key, string ...$other_keys): \Redis|int|false
            {
                $this->deleted[] = (string) $key;
                return 1;
            }
        };
        $redis->evalResult = $evalResult;
        $store = (new \ReflectionClass(RedisStore::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RedisStore::class, 'redis'))->setValue($store, $redis);
        (new \ReflectionProperty(RedisStore::class, 'prefix'))->setValue($store, 'site:');
        (new \ReflectionProperty(RateLimiter::class, 'store'))->setValue(null, $store);

        $status = RateLimiter::storeStatus();
        $this->assertSame(['store' => 'redis', 'path' => null, 'writable' => $writable], array_slice($status, 0, 3));
        if ($writable) {
            $this->assertNull($status['error']);
        } else {
            $this->assertStringStartsWith('Redis rejected the probe write: ', (string) $status['error']);
        }
        $this->assertSame(['site:health:probe ttl 10'], $redis->written);
        $this->assertSame($evalResult instanceof \Throwable ? [] : ['site:health:probe'], $redis->deleted);
    }

    public function testRedisStoreCountsWithItsPrefixAndTheWindow(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // No Redis server in tests: the client is replaced and the store is built without
        // connecting. This covers the wiring (prefixed key, window as TTL, count to verdict),
        // not the Lua script itself.
        $redis = new class extends \Redis {
            /** @var array<string, int> */
            public array $counts = [];
            /** @var array<string, int> */
            public array $ttls = [];

            public function eval(string $script, array $args = [], int $num_keys = 0): mixed
            {
                [$key, $ttl] = $args;
                $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
                $this->ttls[$key] = $ttl;
                return $this->counts[$key];
            }
        };
        $store = (new \ReflectionClass(RedisStore::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RedisStore::class, 'redis'))->setValue($store, $redis);
        (new \ReflectionProperty(RedisStore::class, 'prefix'))->setValue($store, 'site:');
        (new \ReflectionProperty(RateLimiter::class, 'store'))->setValue(null, $store);

        $this->assertTrue(RateLimiter::allow('api', 2, 30));
        $this->assertTrue(RateLimiter::allow('api', 2, 30));
        $this->assertFalse(RateLimiter::allow('api', 2, 30));
        $this->assertSame(['site:ratelimit:api:cli' => 30], $redis->ttls);
    }

    public function testFailsOpenAndLogsWhenRedisRejectsTheIncrement(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // phpredis 6 throws on an error reply to EVAL (read-only replica, maxmemory under noeviction).
        $redis = new class extends \Redis {
            public function eval(string $script, array $args = [], int $num_keys = 0): mixed
            {
                throw new \RedisException("READONLY You can't write against a read only replica.");
            }
        };
        $store = (new \ReflectionClass(RedisStore::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RedisStore::class, 'redis'))->setValue($store, $redis);
        (new \ReflectionProperty(RedisStore::class, 'prefix'))->setValue($store, 'site:');
        (new \ReflectionProperty(RateLimiter::class, 'store'))->setValue(null, $store);
        $logOffset = self::errorLogSize();

        $this->assertTrue(RateLimiter::allow('form', 1, 60));
        $this->assertTrue(RateLimiter::allow('form', 1, 60));
        $this->assertStringContainsString(
            "RATE_LIMIT ERRNO:500 | Rate limit failed open: READONLY You can't write against a read only replica.",
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
