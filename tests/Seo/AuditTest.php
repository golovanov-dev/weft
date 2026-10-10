<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Seo;

use Golovanov\Weft\Seo\Audit;
use Golovanov\Weft\Tests\TestCase;

/** Audit::run(), the body of vendor/bin/weft-seo-audit, against fixture pages served by a fake transport. */
final class AuditTest extends TestCase
{
    private const string GOOD = <<<'HTML'
        <!doctype html><html><head><title>Home</title>
        <meta name="description" content="What the site is about">
        <link rel="canonical" href="https://site.test/">
        <script type="application/ld+json">{"@type": "WebSite", "name": "Site"}</script>
        </head><body><h1>Home</h1></body></html>
        HTML;

    private const string BAD = <<<'HTML'
        <!doctype html><html><head><title> </title>
        <meta name="Robots" content="NOINDEX, follow">
        <link rel="alternate" href="https://site.test/ru">
        <script type="application/ld+json">{"@type": "WebSite",}</script>
        </head><body><h1>One</h1><h1>Two</h1></body></html>
        HTML;

    /** @var array<string, array<string, mixed>> URL => transport response */
    private array $site = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = [
            'https://site.test/sitemap.xml' => ['body' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'
                . '<url><loc>https://site.test/</loc><xhtml:link rel="alternate" hreflang="ru" href="https://site.test/ru"/></url>'
                . '<url><loc>https://site.test/bad</loc></url><url><loc>https://site.test/moved</loc></url></urlset>'],
            'https://site.test/' => ['body' => self::GOOD],
            'https://site.test/bad' => ['body' => self::BAD, 'headers' => ['x-robots-tag' => 'noindex']],
            'https://site.test/moved' => ['status' => 301, 'headers' => ['Location' => 'https://site.test/']],
        ];
        self::fakeHttp(fn(array $request): array => $this->site[$request['url']] ?? ['status' => 404]);
    }

    /** @param list<string> $args @return array{int, string, string} exit status, report, errors */
    private function audit(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $status = Audit::run($args, $out, $err);
        rewind($out);
        rewind($err);
        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testEveryPageOfTheSitemapIsCheckedAndProblemsFailIt(): void
    {
        [$status, $report] = $this->audit(['https://site.test/']);

        $this->assertSame(1, $status);
        $this->assertSame(
            "https://site.test/  ok\n"
            . "https://site.test/bad  noindex in X-Robots-Tag; no title; noindex in meta robots; no meta description;"
            . " no canonical; 2 h1; JSON-LD does not parse: Syntax error\n"
            . "https://site.test/moved  HTTP 301\n"
            . "\nChecked 3 pages: 2 with problems.\n",
            $report,
        );
    }

    public function testGivenPagesUpToTheLimit(): void
    {
        $this->assertSame(
            [0, "https://site.test/  ok\n\nChecked 1 of 2 pages: 0 with problems.\n", ''],
            $this->audit(['--limit=1', '--pages', 'https://site.test/', 'https://site.test/bad']),
        );
    }

    public function testASitemapWithoutPagesOrAnUnreachableOneStopsIt(): void
    {
        $this->site['https://site.test/sitemap.xml']['body'] = '<?xml version="1.0"?>'
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://site.test/a.xml</loc></sitemap></sitemapindex>';
        $this->assertSame([2, '', "weft-seo-audit: https://site.test/sitemap.xml: no <url> entries.\n"], $this->audit(['https://site.test']));

        $this->assertSame([2, '', "weft-seo-audit: https://other.test/sitemap.xml: HTTP 404.\n"], $this->audit(['https://other.test']));
    }

    /** The sitemap of a staging copy names the production address: the pages are checked on staging. */
    public function testSitemapPagesOnAnotherOriginAreCheckedOnTheGivenOne(): void
    {
        $this->site['http://staging.test:8080/sitemap.xml'] = $this->site['https://site.test/sitemap.xml'];
        $this->site['http://staging.test:8080/'] = ['body' => self::GOOD];

        $this->assertSame([0, "The sitemap lists pages on https://site.test; checking them on http://staging.test:8080.\n\n"
            . "http://staging.test:8080/  ok\n\nChecked 1 of 3 pages: 0 with problems.\n", ''], $this->audit(['--limit=1', 'http://staging.test:8080']));
    }

    /** An unknown option (or anything but an address) prints the usage and fetches nothing; so does --help. */
    public function testAnUnknownOptionPrintsUsage(): void
    {
        $requests = 0;
        self::fakeHttp(function () use (&$requests): array {
            $requests++;
            return ['status' => 404];
        });

        [$status, $output, $errors] = $this->audit(['https://site.test', '--limits=1']);
        $this->assertSame([2, ''], [$status, $output]);
        $this->assertStringStartsWith('usage: weft-seo-audit', $errors);

        [$status, $output, $errors] = $this->audit(['--help']);
        $this->assertSame([0, ''], [$status, $errors]);
        $this->assertStringStartsWith('usage: weft-seo-audit', $output);
        $this->assertSame(0, $requests);
    }
}
