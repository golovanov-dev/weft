<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\I18n;
use Golovanov\Weft\Security\Csrf;

final class HelpersTest extends TestCase
{
    public function testEEscapesForHtml(): void
    {
        $this->assertSame('&lt;b&gt;&quot;x&quot;&lt;/b&gt;', e('<b>"x"</b>'));
        $this->assertSame('', e(null));
    }

    public function testTTranslatesWithPlaceholders(): void
    {
        I18n::boot('en', 'home');
        $this->assertSame('Home', t('nav.home'));
        $this->assertSame('Hello, Bob!', t('greeting', ['name' => 'Bob']));
        $this->assertSame('missing.key', t('missing.key'));
    }

    public function testUrlBuildsLocaleAwareLinks(): void
    {
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);
        $this->assertSame('/about', url('/about'));
        $this->assertSame('/ru/about', url('/about', 'ru'));
    }

    public function testAssetAndApiUrl(): void
    {
        $this->assertSame('/img/x.png', asset('img/x.png'));
        $this->assertSame('/api/contact', api_url('/contact'));
    }

    public function testConfigReadsDotKeysWithDefault(): void
    {
        Config::set('app.flag', true);
        $this->assertTrue(config('app.flag'));
        $this->assertSame('d', config('app.missing', 'd'));
    }

    public function testLocaleReturnsCurrentLocale(): void
    {
        $this->assertSame('en', locale());
    }

    public function testSeoHelperSetsAndRenders(): void
    {
        Config::set('app.url.base', 'https://example.com');
        $html = seo(['title' => 'Helper Page']);
        $this->assertStringContainsString('<title>Helper Page</title>', $html);
    }

    public function testCsrfFieldMatchesCsrfClass(): void
    {
        $field = csrf_field();
        $this->assertStringContainsString('name="_csrf"', $field);
        $this->assertStringContainsString(Csrf::token(), $field);
    }

    public function testIsActiveReturnsClassOnlyForActiveRoute(): void
    {
        $_SERVER['REQUEST_URI'] = '/about';
        $this->assertSame('active', is_active('about'));
        $this->assertSame('current', is_active('about', 'current'));
        $this->assertSame('', is_active('contact'));
    }
}
