<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\I18n;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\Tests\TestCase;

final class LocaleTest extends TestCase
{
    private function enableLocales(array $supported = ['en', 'ru'], string $default = 'en'): void
    {
        Config::set('app.locales', [
            'enabled' => true,
            'supported' => $supported,
            'default' => $default,
        ]);
    }

    public function testDisabledByDefaultAndSingleLanguage(): void
    {
        $this->assertFalse(Locale::enabled());
        $this->assertSame(['en'], Locale::supported());
        $this->assertSame('en', Locale::default());
        $this->assertSame('en', Locale::current());
    }

    public function testSupportedNormalisesToLowercaseUniqueList(): void
    {
        $this->enableLocales(['EN', 'ru', 'en']);
        $this->assertSame(['en', 'ru'], Locale::supported());
    }

    public function testSetAcceptsSupportedLocale(): void
    {
        $this->enableLocales();
        Locale::set('RU');
        $this->assertSame('ru', Locale::current());
    }

    public function testSetFallsBackToDefaultForUnsupportedLocale(): void
    {
        $this->enableLocales();
        Locale::set('de');
        $this->assertSame('en', Locale::current());
    }

    public function testIsSupportedIsCaseInsensitive(): void
    {
        $this->enableLocales();
        $this->assertTrue(Locale::isSupported('RU'));
        $this->assertFalse(Locale::isSupported('de'));
    }

    public function testNameFallsBackToUppercasedCode(): void
    {
        $this->enableLocales();
        Config::set('app.locales.names', ['ru' => 'Русский']);
        $this->assertSame('Русский', Locale::name('ru'));
        $this->assertSame('EN', Locale::name('en'));
    }
}
