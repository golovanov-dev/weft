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

    public function testRoutesResolvesAConfiguredCallable(): void
    {
        // The defect this covers: only the kernel unwrapped the callable, so a CLI generator
        // received [0 => Closure] and died casting it to a string.
        Config::set('app.sitemap', static fn(): array => [['path' => '/from-content']]);

        $routes = Sitemap::routes();
        $this->assertSame([['path' => '/from-content']], $routes);
        $this->assertStringContainsString('<loc>https://example.com/from-content</loc>', Sitemap::xml($routes));
    }

    public function testRoutesReturnsAPlainListUnchanged(): void
    {
        Config::set('app.sitemap', ['/', '/about']);
        $this->assertSame(['/', '/about'], Sitemap::routes());
    }

    public function testRoutesDefaultsToAnEmptyListWhenUnconfigured(): void
    {
        $this->assertSame([], Sitemap::routes());
    }

    public function testLastmodIsEmittedWhenGivenAndOmittedOtherwise(): void
    {
        $xml = Sitemap::xml([
            ['path' => '/notes/one', 'lastmod' => '2026-08-24', 'changefreq' => 'monthly'],
            ['path' => '/about', 'changefreq' => 'monthly'],
        ]);

        $this->assertStringContainsString('<lastmod>2026-08-24</lastmod>', $xml);
        $this->assertSame(1, substr_count($xml, '<lastmod>'));
        // Schema order is loc, lastmod, changefreq, priority.
        $this->assertMatchesRegularExpression(
            '~<loc>[^<]+</loc>\s*<lastmod>2026-08-24</lastmod>\s*<changefreq>~',
            $xml,
        );
    }

    public function testEmptyRouteListStillProducesValidUrlset(): void
    {
        $xml = Sitemap::xml([]);
        $this->assertStringContainsString('</urlset>', $xml);
        $this->assertStringNotContainsString('<url>', $xml);
    }
}
