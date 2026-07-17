<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Seo\Sitemap;
use Golovanov\Weft\Tests\TestCase;

final class SitemapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.url.base', 'https://example.com');
    }

    public function testSingleLocaleUrlset(): void
    {
        $xml = Sitemap::xml(['/', '/about']);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"', $xml);
        $this->assertStringContainsString('<loc>https://example.com/</loc>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/about</loc>', $xml);
        $this->assertStringNotContainsString('xhtml:link', $xml);
        $this->assertSame(2, substr_count($xml, '<url>'));
    }

    public function testRouteArraysCarryChangefreqAndPriority(): void
    {
        $xml = Sitemap::xml([['path' => '/about', 'changefreq' => 'monthly', 'priority' => '0.8']]);

        $this->assertStringContainsString('<changefreq>monthly</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.8</priority>', $xml);
    }

    public function testMultilingualSitemapEmitsPerLocaleUrlsWithAlternates(): void
    {
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);
        $xml = Sitemap::xml(['/about']);

        // One <url> per locale.
        $this->assertSame(2, substr_count($xml, '<url>'));
        $this->assertStringContainsString('<loc>https://example.com/about</loc>', $xml);
        $this->assertStringContainsString('<loc>https://example.com/ru/about</loc>', $xml);
        // Each entry lists all alternates.
        $this->assertSame(2, substr_count($xml, 'hreflang="en"'));
        $this->assertSame(2, substr_count($xml, 'hreflang="ru"'));
    }

    public function testEmptyRouteListStillProducesValidUrlset(): void
    {
        $xml = Sitemap::xml([]);
        $this->assertStringContainsString('</urlset>', $xml);
        $this->assertStringNotContainsString('<url>', $xml);
    }
}
