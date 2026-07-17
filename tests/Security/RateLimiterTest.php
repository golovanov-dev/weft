<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Cache\FileStore;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Security\RateLimiter;
use Golovanov\Weft\Tests\TestCase;

final class RateLimiterTest extends TestCase
{
    private string $cacheDir = '';

    protected function tearDown(): void
    {
        if ($this->cacheDir !== '') {
            $this->removeDir($this->cacheDir);
            $this->cacheDir = '';
        }
        parent::tearDown();
    }

    public function testAllowEnforcesLimitViaSessionBackend(): void
    {
        // Caching disabled -> the session-backed fixed window is used.
        $this->assertTrue(RateLimiter::allow('form', 2, 60));
        $this->assertTrue(RateLimiter::allow('form', 2, 60));
        $this->assertFalse(RateLimiter::allow('form', 2, 60));
    }

    public function testBucketsAreIndependent(): void
    {
        $this->assertTrue(RateLimiter::allow('a', 1, 60));
        $this->assertFalse(RateLimiter::allow('a', 1, 60));
        $this->assertTrue(RateLimiter::allow('b', 1, 60));
    }

    public function testAllowEnforcesLimitViaCacheBackend(): void
    {
        $this->cacheDir = $this->makeTempDir();
        Config::set('app.cache.enabled', true);
        Cache::use(new FileStore($this->cacheDir));

        $this->assertTrue(RateLimiter::allow('api', 2, 60));
        $this->assertTrue(RateLimiter::allow('api', 2, 60));
        $this->assertFalse(RateLimiter::allow('api', 2, 60));
    }

    public function testNonPositiveMaxDisablesTheLimit(): void
    {
        $this->assertTrue(RateLimiter::allow('open', 0, 60));
        $this->assertTrue(RateLimiter::allow('open', 0, 60));
    }

    public function testDefaultsComeFromConfig(): void
    {
        Config::set('app.rate_limit', ['max' => 1, 'window' => 60]);
        $this->assertTrue(RateLimiter::allow('cfg'));
        $this->assertFalse(RateLimiter::allow('cfg'));
    }

    public function testThrottleAllowsOncePerInterval(): void
    {
        $this->assertTrue(RateLimiter::throttle('submit', 5));
        $this->assertFalse(RateLimiter::throttle('submit', 5));
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
