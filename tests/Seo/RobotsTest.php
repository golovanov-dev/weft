<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Seo\Robots;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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

    /** Without app.seo.robots_groups the file is what it was before groups existed. */
    public function testWithoutGroupsTheOutputIsUnchanged(): void
    {
        Config::set('app.seo.robots_disallow', ['/api/']);
        Config::set('app.sitemap', ['/']);

        $this->assertSame(
            "User-agent: *\nAllow: /\nDisallow: /api/\n\nSitemap: https://example.com/sitemap.xml\n",
            Robots::text(),
        );
    }

    /** Each group names its crawlers and repeats robots_disallow: a crawler obeys only its own group. */
    public function testGroupsFollowTheDefaultGroupWithTheCommonDisallows(): void
    {
        Config::set('app.seo.robots_disallow', ['/api/']);
        Config::set('app.seo.robots_groups', [
            ['agents' => ['Bingbot', 'Yandex'], 'allow' => ['/api/docs'], 'disallow' => ['/drafts']],
            ['agents' => 'Baiduspider', 'disallow' => ['/']],
            ['agents' => [], 'disallow' => ['/']],
        ]);
        Config::set('app.sitemap', ['/']);

        $this->assertSame(
            "User-agent: *\nAllow: /\nDisallow: /api/\n"
            . "\nUser-agent: Bingbot\nUser-agent: Yandex\nDisallow: /drafts\nDisallow: /api/\n"
            . "\nUser-agent: Baiduspider\nDisallow: /\nDisallow: /api/\n"
            . "\nSitemap: https://example.com/sitemap.xml\n",
            Robots::text(),
        );
    }

    /**
     * A group's allow inside a path closed to every crawler is left out: it would win over the
     * Disallow. A line break in a configured value stays inside its line, it adds no rule.
     */
    public function testAGroupCannotReopenACommonDisallowOrAddLines(): void
    {
        Config::set('app.sitemap', []);
        Config::set('app.seo.robots_disallow', ["/api/\n"]);
        Config::set('app.seo.robots_groups', [
            ['agents' => ["Bot\nDisallow: /x"], 'allow' => ['/api/', '/api/docs', '/blog'], 'disallow' => ["/drafts\r\nAllow: /"]],
        ]);

        $this->assertSame(
            "User-agent: *\nAllow: /\nDisallow: /api/\n"
            . "\nUser-agent: Bot Disallow: /x\nAllow: /blog\nDisallow: /drafts Allow: /\nDisallow: /api/\n",
            Robots::text(),
        );
    }

    /** A preset name expands to its crawlers; a group with no rule at all still allows. */
    public function testPresetNamesExpandToTheirCrawlers(): void
    {
        Config::set('app.seo.robots_groups', [
            ['agents' => ['ai-training', 'Applebot'], 'disallow' => ['/']],
            ['agents' => ['ai-user']],
        ]);
        $text = Robots::text();

        $this->assertStringContainsString("\nUser-agent: GPTBot\nUser-agent: ClaudeBot\n", $text);
        $this->assertStringContainsString("User-agent: CCBot\nUser-agent: Applebot\nDisallow: /\n", $text);
        $this->assertStringContainsString("User-agent: Perplexity-User\nAllow: /\n", $text);
        $this->assertStringNotContainsString('ai-training', $text);
    }

    /** A preset name or a group key with a typo is reported (health fails on it); robots.txt is written as it is. */
    public function testATypoInAGroupIsReportedAndWrittenAsItIs(): void
    {
        Config::set('app.seo.robots_groups', [
            ['agents' => ['AI-Training', 'ai-search', 'Aidan'], 'disallow' => ['/']],
            ['agents' => 'ai_training', 'dissallow' => ['/']],
        ]);

        $this->assertSame([
            'app.seo.robots_groups: AI-Training is not a preset',
            'app.seo.robots_groups: dissallow is not a group key (agents, allow, disallow)',
            'app.seo.robots_groups: ai_training is not a preset',
        ], Robots::errors());
        $this->assertStringEndsWith("\nUser-agent: ai_training\nAllow: /\n", Robots::text());
        $this->assertStringContainsString("\nUser-agent: AI-Training\nUser-agent: OAI-SearchBot\n", Robots::text());
    }

    /** DEBUG closes the site for every crawler: no group reopens it. */
    #[RunInSeparateProcess]
    public function testDebugIgnoresGroups(): void
    {
        define('DEBUG', true);
        Config::set('app.seo.robots_groups', [['agents' => 'ai-search', 'allow' => ['/']]]);

        $this->assertSame("User-agent: *\nDisallow: /\n", Robots::text());
    }
}
