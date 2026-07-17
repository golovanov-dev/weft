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
        $text = Robots::text();

        $this->assertStringContainsString("User-agent: *\nAllow: /", $text);
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
