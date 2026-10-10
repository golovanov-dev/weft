<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Cache;

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Security\RateLimiter;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The cache and the rate limiter against a real Redis, on port 6379 of WEFT_TEST_REDIS_HOST
 * (CONTRIBUTING.md). Without that variable every test here is skipped.
 */
#[Group('integration')]
final class RedisIntegrationTest extends TestCase
{
    /** Keys of the running test; "[" checks that flush() takes the prefix literally. */
    private string $prefix = '';

    /** A key of another site that $prefix would match as a pattern: "[abc]" is one of a, b, c. */
    private string $sibling = '';

    private \Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $host = (string) getenv('WEFT_TEST_REDIS_HOST');
        if ($host === '') {
            $this->markTestSkipped('Set WEFT_TEST_REDIS_HOST to run the Redis integration tests.');
        }
        if (!defined('REDIS_HOST')) {
            define('REDIS_HOST', $host);
        }
        $id = bin2hex(random_bytes(4));
        $this->prefix = 'weft-test[' . $id . ']:';
        $this->sibling = 'weft-test' . $id[0] . ':cache:page';
        $this->redis = new \Redis();
        $this->redis->connect($host, 6379, 2.0);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $keys = $this->redis->keys(addcslashes(rtrim($this->prefix, ':'), '\*?[]') . '*');
            $this->redis->del([...(is_array($keys) ? $keys : []), $this->sibling]);
        }
        parent::tearDown();
    }

    public function testCacheAndCountersShareOneRedisAndFlushLeavesCounters(): void
    {
        Config::set('app.cache', ['enabled' => true, 'driver' => 'redis', 'prefix' => $this->prefix]);
        $this->redis->set($this->sibling, 'other site');

        Cache::set('page', ['title' => 'Home'], 60);
        $this->assertSame(['title' => 'Home'], Cache::get('page'));
        $this->assertTrue(Cache::has('page'));
        $ttl = $this->redis->ttl($this->prefix . 'cache:page');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(60, $ttl);

        $this->assertTrue(RateLimiter::allow('form', 2, 60));
        $this->redis->expire($this->prefix . 'ratelimit:form:cli', 30);
        $this->assertTrue(RateLimiter::allow('form', 2, 60));
        $this->assertFalse(RateLimiter::allow('form', 2, 60));
        // The window is set by the first hit only: later hits do not extend it.
        $this->assertLessThanOrEqual(30, $this->redis->ttl($this->prefix . 'ratelimit:form:cli'));
        $this->assertSame('redis', RateLimiter::storeStatus()['store']);

        Cache::flush();

        $this->assertNull(Cache::get('page'));
        $this->assertSame('3', $this->redis->get($this->prefix . 'ratelimit:form:cli'));
        $this->assertSame('other site', $this->redis->get($this->sibling));
    }
}
