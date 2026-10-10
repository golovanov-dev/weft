<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Seo\Robots;
use Golovanov\Weft\Seo\Seo;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class SeoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.url.base', 'https://example.com');
        Config::set('app.seo', [
            'site_name' => 'Example',
            'title_template' => '%s — Example',
            'description' => 'Default description',
        ]);
        $_SERVER['REQUEST_URI'] = '/about';
    }

    public function testDocumentTitleAppliesTemplate(): void
    {
        Seo::set(['title' => 'About']);
        $this->assertSame('About — Example', Seo::documentTitle());
    }

    public function testDocumentTitleFallsBackToDefaultWhenUnset(): void
    {
        Config::set('app.seo.default_title', 'Example — Home of Examples');
        $this->assertSame('Example — Home of Examples', Seo::documentTitle());
    }

    public function testLaterPageWithoutTitleGetsTheConfiguredSuffixOnTheDefault(): void
    {
        Config::set('app.seo.default_title', 'Example');
        Config::set('app.seo.page_suffix', ', стр. %d');
        Seo::set(['page' => 2]);
        $this->assertSame('Example, стр. 2', Seo::documentTitle());
    }

    /** A suffix without %d (a translation that lost it) still numbers the page, after a space. */
    public function testPageSuffixWithoutPlaceholderGetsTheNumberAfterASpace(): void
    {
        Seo::set(['title' => 'Blog', 'page_suffix' => ' (more)', 'page' => 3]);
        $this->assertSame('Blog (more) 3 — Example', Seo::documentTitle());
    }

    public function testDocumentTitleFallsBackToSiteNameWithoutDefaultTitle(): void
    {
        $this->assertSame('Example', Seo::documentTitle());
    }

    public function testDocumentTitleIgnoresTemplateWithoutPlaceholder(): void
    {
        Config::set('app.seo.title_template', 'Static Title');
        Seo::set(['title' => 'About']);
        $this->assertSame('About', Seo::documentTitle());
    }

    public function testRenderEmitsTitleDescriptionCanonicalAndOpenGraph(): void
    {
        Seo::set(['title' => 'About', 'description' => 'About the site.']);
        $html = Seo::render();

        $this->assertStringContainsString('<title>About — Example</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="About the site." />', $html);
        $this->assertStringContainsString('<meta name="robots" content="index, follow" />', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/about" />', $html);
        $this->assertStringContainsString('<meta property="og:type" content="website" />', $html);
        $this->assertStringContainsString('<meta property="og:title" content="About — Example" />', $html);
        $this->assertStringContainsString('<meta property="og:url" content="https://example.com/about" />', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Example" />', $html);
        // No image configured — the plain summary card is used.
        $this->assertStringContainsString('<meta name="twitter:card" content="summary" />', $html);
        $this->assertStringNotContainsString('og:image', $html);
    }

    public function testRenderEscapesHtmlInPageValues(): void
    {
        Seo::set(['title' => '<script>alert(1)</script>']);
        $html = Seo::render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderUsesLargeImageCardAndAbsoluteImageUrl(): void
    {
        Seo::set(['title' => 'About', 'image' => 'img/og.png']);
        $html = Seo::render();
        $this->assertStringContainsString('<meta property="og:image" content="https://example.com/img/og.png" />', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image" />', $html);
    }

    public function testPageImageAltIsEmittedAsOpenGraphAlt(): void
    {
        Seo::set(['title' => 'About', 'image' => 'og/about.png', 'image_alt' => 'The about page card']);
        $this->assertStringContainsString(
            '<meta property="og:image:alt" content="The about page card" />',
            Seo::render(),
        );
    }

    public function testConfiguredImageAltAppliesOnlyToTheConfiguredImage(): void
    {
        Config::set('app.seo.og_image', 'og/default.png');
        Config::set('app.seo.og_image_alt', 'Default social card');

        // The page inherits the configured image, so the configured alt describes it.
        Seo::set(['title' => 'Home']);
        $this->assertStringContainsString('content="Default social card"', Seo::render());

        // A page pointing somewhere else gets no alt rather than the wrong one.
        self::resetEngineState();
        Config::set('app.url.base', 'https://example.com');
        Config::set('app.seo.og_image', 'og/default.png');
        Config::set('app.seo.og_image_alt', 'Default social card');
        Seo::set(['title' => 'About', 'image' => 'og/about.png']);
        $this->assertStringNotContainsString('og:image:alt', Seo::render());
    }

    public function testImageDimensionsAreEmittedOnlyAsAPair(): void
    {
        Config::set('app.seo.og_image_width', 2400);
        Config::set('app.seo.og_image_height', 1260);
        Seo::set(['title' => 'About', 'image' => 'og/about.png']);
        $html = Seo::render();
        $this->assertStringContainsString('<meta property="og:image:width" content="2400" />', $html);
        $this->assertStringContainsString('<meta property="og:image:height" content="1260" />', $html);

        // A lone dimension is unusable, so neither tag is emitted.
        self::resetEngineState();
        Config::set('app.url.base', 'https://example.com');
        Config::set('app.seo.og_image_width', 2400);
        Seo::set(['title' => 'About', 'image' => 'og/about.png']);
        $this->assertStringNotContainsString('og:image:width', Seo::render());
    }

    public function testImageMetaIsAbsentWithoutAnImage(): void
    {
        Config::set('app.seo.og_image_alt', 'Default social card');
        Config::set('app.seo.og_image_width', 2400);
        Config::set('app.seo.og_image_height', 1260);
        Seo::set(['title' => 'About']);
        $html = Seo::render();
        $this->assertStringNotContainsString('og:image', $html);
    }

    public function testRenderEmitsHreflangAlternatesWhenLocalesEnabled(): void
    {
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);
        Seo::set(['title' => 'About']);
        $html = Seo::render();

        $this->assertStringContainsString('hreflang="en" href="https://example.com/about"', $html);
        $this->assertStringContainsString('hreflang="ru" href="https://example.com/ru/about"', $html);
        $this->assertStringContainsString('hreflang="x-default" href="https://example.com/about"', $html);
    }

    public function testRenderOmitsHreflangWhenLocalesDisabled(): void
    {
        Seo::set(['title' => 'About']);
        $this->assertStringNotContainsString('hreflang', Seo::render());
    }

    public function testNoindexOverridesRobots(): void
    {
        Seo::noindex();
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow" />', Seo::render());
    }

    public function testJsonLdValueCannotCloseTheScriptElement(): void
    {
        $value = '</script><script>alert(1)</script><!--';
        Seo::jsonLd(['@type' => 'WebSite', 'name' => $value]);
        $html = Seo::render();

        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $blocks);
        $this->assertCount(1, $blocks[1]);
        $this->assertStringNotContainsString('<', $blocks[1][0]);
        $this->assertSame($value, json_decode($blocks[1][0], true)['name']);
    }

    /** A site mounted under BASE_URI serves its images and sitemap there too. */
    #[RunInSeparateProcess]
    public function testRelativeImageAndSitemapCarryTheBaseUri(): void
    {
        define('BASE_URI', '/site');
        Config::set('app.sitemap', ['/']);
        Seo::set(['image' => 'img/og.png']);

        $this->assertStringContainsString('<meta property="og:image" content="https://example.com/site/img/og.png" />', Seo::render());
        $this->assertStringContainsString('Sitemap: https://example.com/site/sitemap.xml', Robots::text());
    }

    public function testJsonLdBlocksGetSchemaContextInjected(): void
    {
        Seo::jsonLd(['@type' => 'WebSite', 'name' => 'Example']);
        $html = Seo::render();
        $this->assertStringContainsString('<script type="application/ld+json">', $html);
        $this->assertStringContainsString('"@context": "https://schema.org"', $html);
        $this->assertStringContainsString('"@type": "WebSite"', $html);
    }

    public function testDefaultJsonLdFromConfigSupportsSingleSchema(): void
    {
        Config::set('app.seo.json_ld', ['@type' => 'Person', 'name' => 'Roman']);
        Seo::set(['title' => 'About']);
        $this->assertStringContainsString('"@type": "Person"', Seo::render());
    }

    /**
     * Page 2+ of a list adds ?page=N to the canonical the page set (made absolute) or to the route,
     * whichever comes first: Seo::set() before or after Pagination (Seo::set(['page' => N])).
     */
    #[DataProvider('canonicalsOnALaterPage')]
    public function testLaterPageAddsItsNumberToTheCanonicalWhateverTheOrder(?string $set, string $expected): void
    {
        foreach ([[['canonical' => $set], ['page' => 2]], [['page' => 2], ['canonical' => $set]]] as $order) {
            self::resetEngineState();
            Config::set('app.url.base', 'https://example.com');
            Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);
            $_SERVER['REQUEST_URI'] = '/blog';
            Seo::set($order[0]);
            Seo::set($order[1]);
            $html = Seo::render();

            $this->assertStringContainsString('<link rel="canonical" href="' . $expected . '" />', $html);
            $this->assertStringContainsString('<meta property="og:url" content="' . $expected . '" />', $html);
            $this->assertStringContainsString('<link rel="alternate" hreflang="ru" href="https://example.com/ru/blog?page=2" />', $html);
        }
    }

    /** @return array<string, array{?string, string}> */
    public static function canonicalsOnALaterPage(): array
    {
        return [
            'from the route' => [null, 'https://example.com/blog?page=2'],
            'set as a site link' => ['/blog', 'https://example.com/blog?page=2'],
            'set absolute' => ['https://example.com/posts', 'https://example.com/posts?page=2'],
            'set with its own page=' => ['/blog?page=5', 'https://example.com/blog?page=2'],
            'set with a query' => ['/blog?sort=new', 'https://example.com/blog?sort=new&amp;page=2'],
            'set with a fragment' => ['https://example.com/blog#list', 'https://example.com/blog?page=2#list'],
            'set protocol-relative' => ['//example.com/blog', 'https://example.com/blog?page=2'],
        ];
    }

    /**
     * A canonical the page set as a site link (from url(): base URI and locale prefix in it) is
     * absolute on page 1 and outside lists too, and so is og:url.
     */
    public function testCanonicalSetAsASiteLinkIsAlwaysAbsolute(): void
    {
        $_SERVER['REQUEST_URI'] = '/blog';
        $cases = [
            '/sub/ru/blog' => 'https://example.com/sub/ru/blog',
            'blog' => 'https://example.com/blog',
            '//cdn.test/x' => 'https://cdn.test/x',
        ];
        foreach ($cases as $set => $expected) {
            Seo::set(['canonical' => $set]);
            $html = Seo::render();

            $this->assertStringContainsString('<link rel="canonical" href="' . $expected . '" />', $html);
            $this->assertStringContainsString('<meta property="og:url" content="' . $expected . '" />', $html);
        }
    }

    public function testCanonicalCanBeOverriddenPerPage(): void
    {
        Seo::set(['canonical' => 'https://example.com/custom']);
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/custom" />', Seo::render());
    }
}
