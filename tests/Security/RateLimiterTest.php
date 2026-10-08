<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Golovanov\Weft\Cache\RedisStore;
use Golovanov\Weft\Config\Config;
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

    public function testCountersDefaultToTheCachePath(): void
    {
        $cacheDir = $this->makeTempDir();
        Config::set('app.rate_limit', ['max' => 1, 'window' => 60]);
        Config::set('app.cache.path', $cacheDir);

        try {
            $this->assertTrue(RateLimiter::allow('cfg'));
            $this->assertFalse(RateLimiter::allow('cfg'));
            $this->assertCount(1, glob($cacheDir . '/*.cache') ?: []);
        } finally {
            $this->removeDir($cacheDir);
        }
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
            ['store' => 'file', 'path' => $this->dir, 'writable' => true],
            RateLimiter::storeStatus(),
        );
    }

    public function testStoreStatusReportsAnUnwritableCounterDirectory(): void
    {
        $notADir = $this->dir . '/file';
        file_put_contents($notADir, '');
        Config::set('app.rate_limit.path', $notADir);

        $this->assertSame(
            ['store' => 'file', 'path' => $notADir, 'writable' => false],
            RateLimiter::storeStatus(),
        );
    }

    public function testRelativeCounterPathIsResolvedFromAppRoot(): void
    {
        $relative = 'storage/ratelimit-' . bin2hex(random_bytes(4));
        Config::set('app.rate_limit.path', $relative);

        try {
            $this->assertSame(
                ['store' => 'file', 'path' => APP_ROOT . '/' . $relative, 'writable' => true],
                RateLimiter::storeStatus(),
            );
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

        $this->assertSame(
            ['store' => 'file', 'path' => $this->dir, 'writable' => true],
            RateLimiter::storeStatus(),
        );
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

        $this->assertSame(['store' => 'redis', 'path' => null, 'writable' => $writable], RateLimiter::storeStatus());
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

    public function testHoneypotDetectsFilledHiddenField(): void
    {
        $this->assertTrue(RateLimiter::honeypot(['website' => 'spam']));
        $this->assertFalse(RateLimiter::honeypot(['website' => '   ']));
        $this->assertFalse(RateLimiter::honeypot(['website' => '']));
        $this->assertFalse(RateLimiter::honeypot([]));
        $this->assertTrue(RateLimiter::honeypot(['custom' => 'x'], 'custom'));
    }
}
