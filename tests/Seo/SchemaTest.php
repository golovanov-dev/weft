<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\Seo\Schema;
use Golovanov\Weft\Seo\Seo;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.url.base', 'https://example.com');
    }

    /** @return list<array<string, mixed>> the JSON-LD blocks Seo::render() prints */
    private static function blocks(): array
    {
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', Seo::render(), $m);
        return array_map(static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);
    }

    public function testBuilderAddsTheBlockWithItsTypeOrTheGivenSubtype(): void
    {
        $this->assertTrue(Schema::organization(['name' => 'Acme', 'url' => 'https://acme.test']));
        $this->assertTrue(Schema::article(['@type' => 'BlogPosting', 'headline' => 'Hello']));

        $this->assertSame([
            ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Acme', 'url' => 'https://acme.test'],
            ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => 'Hello'],
        ], self::blocks());
    }

    public function testMissingFieldLeavesTheBlockOutAndLogsWhere(): void
    {
        $offset = self::errorLogSize();

        $this->assertFalse(Schema::localBusiness(['name' => 'Cafe', 'address' => '']));

        $this->assertSame([], self::blocks());
        $log = self::errorLogSince($offset);
        $this->assertStringContainsString('JSON-LD LocalBusiness left out: missing address', $log);
        $this->assertStringContainsString(__FILE__, $log);
    }

    #[RunInSeparateProcess]
    public function testMissingFieldThrowsInDebug(): void
    {
        define('DEBUG', true);
        $this->expectExceptionObject(new \InvalidArgumentException('JSON-LD WebSite left out: missing url'));
        Schema::webSite(['name' => 'Acme']);
    }

    public function testProductChecksEveryOffer(): void
    {
        $this->assertTrue(Schema::product(['name' => 'Mug', 'offers' => ['price' => '9.50', 'priceCurrency' => 'EUR']]));
        $this->assertSame(['@type' => 'Offer', 'price' => '9.50', 'priceCurrency' => 'EUR'], self::blocks()[0]['offers']);

        $this->assertFalse(Schema::product(['name' => 'Mug', 'offers' => [['price' => '9.50', 'priceCurrency' => 'EUR'], ['price' => '12']]]));
        $this->assertFalse(Schema::product(['name' => 'Mug']));
        $this->assertCount(1, self::blocks());
    }

    public function testFaqFromQuestionsAndAnswers(): void
    {
        $this->assertTrue(Schema::faq(['Is it free?' => 'Yes.']));

        $this->assertSame([
            ['@type' => 'Question', 'name' => 'Is it free?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes.']],
        ], self::blocks()[0]['mainEntity']);
        $this->assertFalse(Schema::faq([]));
    }

    /** A crumb holds the link the page already built with url(): only the scheme and host are added. */
    #[RunInSeparateProcess]
    public function testBreadcrumbsTakeLinksFromUrlWithLocaleAndBaseUriAsTheyAre(): void
    {
        define('BASE_URI', '/sub');
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);
        Locale::set('ru');

        $this->assertTrue(Schema::breadcrumbs([['Home', url('/')], ['Blog', url('/blog')], ['Other', 'https://blog.example.com/'], ['Post', null]]));

        $this->assertSame([
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://example.com/sub/ru'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => 'https://example.com/sub/ru/blog'],
            ['@type' => 'ListItem', 'position' => 3, 'name' => 'Other', 'item' => 'https://blog.example.com/'],
            ['@type' => 'ListItem', 'position' => 4, 'name' => 'Post'],
        ], self::blocks()[0]['itemListElement']);
    }

    public function testBreadcrumbWithoutUrlBeforeTheLastIsMissing(): void
    {
        $this->assertFalse(Schema::breadcrumbs(['Home' => '', 'Post' => '/post']));
        $this->assertSame([], self::blocks());
    }
}
