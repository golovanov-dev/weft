<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Cache;

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Cache\FileStore;
use Golovanov\Weft\Cache\NullStore;
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
}
