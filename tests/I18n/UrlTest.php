<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\I18n;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Tests\TestCase;

final class UrlTest extends TestCase
{
    private function enableLocales(): void
    {
        Config::set('app.locales', [
            'enabled' => true,
            'supported' => ['en', 'ru'],
            'default' => 'en',
        ]);
    }

    public function testLinkBuildsSiteRelativePath(): void
    {
        $this->assertSame('/about', Url::link('/about'));
        $this->assertSame('/', Url::link('/'));
        $this->assertSame('/', Url::link(''));
    }

    public function testLinkCollapsesTrailingSlashOnNonRootPaths(): void
    {
        $this->assertSame('/about', Url::link('/about/'));
    }

    public function testLinkPreservesQueryStringAndFragment(): void
    {
        $this->assertSame('/about?x=1#top', Url::link('/about?x=1#top'));
    }

    public function testLinkAddsLocalePrefixForNonDefaultLocale(): void
    {
        $this->enableLocales();
        $this->assertSame('/ru/about', Url::link('/about', 'ru'));
        // A locale root has no trailing slash either: the kernel redirects /ru/ to /ru.
        $this->assertSame('/ru', Url::link('/', 'ru'));
        // The default locale never gets a prefix.
        $this->assertSame('/about', Url::link('/about', 'en'));
    }

    public function testLinkUsesCurrentLocaleWhenNoneGiven(): void
    {
        $this->enableLocales();
        Locale::set('ru');
        $this->assertSame('/ru/contact', Url::link('/contact'));
    }

    public function testApiLinksNeverCarryLocalePrefix(): void
    {
        $this->enableLocales();
        Locale::set('ru');
        $this->assertSame('/api/contact', Url::api('/contact'));
    }

    public function testAssetIsBaseUriRelative(): void
    {
        $this->assertSame('/img/logo.svg', Url::asset('img/logo.svg'));
    }

    public function testAbsolutePrefixesConfiguredOrigin(): void
    {
        Config::set('app.url.base', 'https://example.com/');
        $this->assertSame('https://example.com/about', Url::absolute('/about'));
    }

    public function testCurrentPathStripsLocalePrefix(): void
    {
        $this->enableLocales();
        $_SERVER['REQUEST_URI'] = '/ru/about?x=1';
        $this->assertSame('/about', Url::currentPath());

        $_SERVER['REQUEST_URI'] = '/about';
        $this->assertSame('/about', Url::currentPath());

        $_SERVER['REQUEST_URI'] = '/';
        $this->assertSame('/', Url::currentPath());
    }

    public function testIsActiveTreatsRootAsHomeRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertTrue(Url::isActive('home'));
        $this->assertFalse(Url::isActive('about'));

        $_SERVER['REQUEST_URI'] = '/about';
        $this->assertTrue(Url::isActive('about'));
    }
}
