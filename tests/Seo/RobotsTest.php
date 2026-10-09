<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Seo\Robots;
use Golovanov\Weft\Tests\TestCase;

final class RobotsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.url.base', 'https://example.com');
    }

    public function testAllowsEverythingAndAdvertisesSitemapByDefault(): void
    {
        Config::set('app.sitemap', ['/']);
        $text = Robots::text();

        $this->assertStringContainsString("User-agent: *\nAllow: /", $text);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $text);
    }

    /** /sitemap.xml without a route is an empty urlset: not worth pointing a crawler at. */
    public function testNoSitemapLineWhenTheSitemapListsNoRoute(): void
    {
        $text = Robots::text();

        $this->assertStringContainsString("User-agent: *\nAllow: /", $text);
        $this->assertStringNotContainsString('Sitemap:', $text);
    }

    /** robots.txt never runs the sitemap generator: one that throws must not make it a 500. */
    public function testSitemapCallableIsNotCalled(): void
    {
        $called = false;
        Config::set('app.sitemap', static function () use (&$called): array {
            $called = true;
            throw new \RuntimeException('sitemap generator failed');
        });
        $text = Robots::text();

        $this->assertFalse($called);
        $this->assertStringContainsString('Sitemap: https://example.com/sitemap.xml', $text);
    }

    public function testDisallowAllWhenRobotsAllowIsFalse(): void
    {
        Config::set('app.seo.robots_allow', false);
        $text = Robots::text();

        $this->assertStringContainsString("User-agent: *\nDisallow: /", $text);
        // A blocked site does not advertise its sitemap.
        $this->assertStringNotContainsString('Sitemap:', $text);
    }

    public function testExtraDisallowPathsAreListed(): void
    {
        Config::set('app.seo.robots_disallow', ['/admin', '/drafts']);
        $text = Robots::text();

        $this->assertStringContainsString('Disallow: /admin', $text);
        $this->assertStringContainsString('Disallow: /drafts', $text);
        // The site itself stays allowed.
        $this->assertStringContainsString('Allow: /', $text);
    }
}
