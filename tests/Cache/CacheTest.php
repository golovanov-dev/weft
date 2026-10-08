<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Cache;

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Cache\FileStore;
use Golovanov\Weft\Cache\NullStore;
use Golovanov\Weft\Cache\Store;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Tests\TestCase;

final class CacheTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            $this->removeDir($this->dir);
            $this->dir = '';
        }
        parent::tearDown();
    }

    public function testDisabledCacheUsesNullStore(): void
    {
        $this->assertInstanceOf(NullStore::class, Cache::store());
    }

    public function testNullStoreIsAlwaysAMiss(): void
    {
        $store = new NullStore();
        $store->set('k', 'v', 60);
        $this->assertNull($store->get('k'));
        $this->assertFalse($store->has('k'));
    }

    public function testRememberExecutesCallbackEveryTimeWhenCachingIsOff(): void
    {
        $calls = 0;
        $fn = function () use (&$calls): string {
            $calls++;
            return 'computed';
        };

        $this->assertSame('computed', Cache::remember('key', 60, $fn));
        $this->assertSame('computed', Cache::remember('key', 60, $fn));
        $this->assertSame(2, $calls);
    }

    public function testRememberComputesOnceWithARealStore(): void
    {
        $this->dir = $this->makeTempDir();
        Cache::use(new FileStore($this->dir));

        $calls = 0;
        $fn = function () use (&$calls): array {
            $calls++;
            return ['data' => 1];
        };

        $this->assertSame(['data' => 1], Cache::remember('key', 60, $fn));
        $this->assertSame(['data' => 1], Cache::remember('key', 60, $fn));
        $this->assertSame(1, $calls);
    }

    public function testGetReturnsDefaultOnMissAndFacadeDelegatesToStore(): void
    {
        $this->dir = $this->makeTempDir();
        Cache::use(new FileStore($this->dir));

        $this->assertSame('d', Cache::get('missing', 'd'));

        Cache::set('k', 'v');
        $this->assertSame('v', Cache::get('k'));
        $this->assertTrue(Cache::has('k'));

        Cache::forget('k');
        $this->assertFalse(Cache::has('k'));
    }

    public function testEnabledFileDriverBuildsFileStore(): void
    {
        $this->dir = $this->makeTempDir();
        Config::set('app.cache.enabled', true);
        Config::set('app.cache.driver', 'file');
        Config::set('app.cache.path', $this->dir);

        $this->assertInstanceOf(FileStore::class, Cache::store());
    }

    public function testRelativeCachePathIsResolvedFromAppRoot(): void
    {
        $relative = 'storage/cache-' . bin2hex(random_bytes(4));
        $this->dir = APP_ROOT . '/' . $relative;
        Config::set('app.cache.enabled', true);
        Config::set('app.cache.path', $relative);

        Cache::set('k', 'v');

        $this->assertCount(1, glob($this->dir . '/*.cache') ?: []);
    }

    /**
     * A store that accepts the connection and then fails every call, as Redis does when it is a
     * read-only replica or out of memory under noeviction.
     */
    private static function failingStore(): Store
    {
        return new class implements Store {
            public function get(string $key): mixed
            {
                throw new \RuntimeException('OOM command not allowed');
            }

            public function set(string $key, mixed $value, int $ttl = 0): void
            {
                throw new \RuntimeException('OOM command not allowed');
            }

            public function has(string $key): bool
            {
                throw new \RuntimeException('OOM command not allowed');
            }

            public function forget(string $key): void
            {
                throw new \RuntimeException('OOM command not allowed');
            }

            public function flush(): void
            {
                throw new \RuntimeException('OOM command not allowed');
            }
        };
    }

    public function testAFailingStoreIsAMissAndLogsOncePerRequest(): void
    {
        Cache::use(self::failingStore());
        $logOffset = self::errorLogSize();

        $this->assertSame('page', Cache::remember('page', 60, fn (): string => 'page'));
        $this->assertSame('d', Cache::get('k', 'd'));
        $this->assertFalse(Cache::has('k'));
        Cache::set('k', 'v');
        Cache::forget('k');
        Cache::flush();

        $log = self::errorLogSince($logOffset);
        $this->assertStringContainsString('CACHE ERRNO:500 | Cache store failed, continuing without cache: OOM command not allowed', $log);
        $this->assertSame(1, substr_count($log, "\n"));
        // The line points at the store that threw, not at the facade.
        $this->assertStringContainsString('| ' . __FILE__ . ':', $log);
    }

    public function testRememberStillThrowsWhatTheCallbackThrows(): void
    {
        // Even with a failing store, only store calls are guarded: the callback's own error is not a miss.
        Cache::use(self::failingStore());

        $this->expectExceptionMessage('callback failed');
        Cache::remember('k', 60, fn () => throw new \LogicException('callback failed'));
    }
}
