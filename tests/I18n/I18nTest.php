<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\I18n;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\I18n;
use Golovanov\Weft\Tests\TestCase;

final class I18nTest extends TestCase
{
    public function testTranslatesDotKeysFromCommonAndRouteFiles(): void
    {
        I18n::boot('en', 'home');
        $this->assertSame('Home', I18n::t('nav.home'));
        $this->assertSame('Welcome', I18n::t('title'));
    }

    public function testMissingKeyFallsBackToTheKeyItself(): void
    {
        I18n::boot('en', 'home');
        $this->assertSame('nav.missing', I18n::t('nav.missing'));
    }

    public function testPlaceholderInterpolation(): void
    {
        I18n::boot('en', 'home');
        $this->assertSame('Hello, Ann!', I18n::t('greeting', ['name' => 'Ann']));
    }

    public function testNonDefaultLocaleOverridesAndFallsBackToDefault(): void
    {
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);
        I18n::boot('ru', 'home');
        // Overridden in ru.
        $this->assertSame('Главная', I18n::t('nav.home'));
        // Missing in ru — falls back to the en value.
        $this->assertSame('English only', I18n::t('only_en'));
        $this->assertSame('Contact us', I18n::t('nav.contact'));
    }

    public function testHasReportsOnlyStringValues(): void
    {
        I18n::boot('en', 'home');
        $this->assertTrue(I18n::has('nav.home'));
        $this->assertFalse(I18n::has('nav'));
        $this->assertFalse(I18n::has('missing'));
    }

    public function testRawReturnsArraysAndDefault(): void
    {
        I18n::boot('en', 'home');
        $this->assertSame(['one', 'two'], I18n::raw('items'));
        $this->assertSame('d', I18n::raw('missing', 'd'));
    }

    public function testBootWithMissingRouteFileStillLoadsCommon(): void
    {
        I18n::boot('en', 'no-such-route-files');
        // common.php is loaded even when the per-route file is absent...
        $this->assertSame('Home', I18n::t('nav.home'));
        // ...and keys from another route's file are not.
        $this->assertSame('title', I18n::t('title'));
    }
}
