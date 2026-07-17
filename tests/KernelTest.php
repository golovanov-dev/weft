<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Http\Response;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\Kernel;

/**
 * Routing tests drive Kernel::route() directly (via reflection — it is private on purpose)
 * against the fixture site in tests/fixtures/site, without a web server. Kernel::handle()
 * itself (tracing, header emission, output) is exercised through the example site, not here.
 */
final class KernelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // dispatchPage() honours a non-200 http_response_code() left by a page; make sure
        // no earlier test (or a previous route) leaks one into this process-wide state.
        http_response_code(200);
    }

    private function route(string $path, string $method = 'GET'): Response
    {
        $request = new Request(
            method: $method,
            uri: $path,
            path: $path,
            clientIp: '203.0.113.7',
            headers: [],
            query: [],
            post: [],
            rawBody: null,
        );
        $m = new \ReflectionMethod(Kernel::class, 'route');
        return $m->invoke(null, $request);
    }

    public function testExactPageRouteRendersInsideLayout(): void
    {
        $response = $this->route('/about');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('<layout>', $response->body);
        $this->assertStringContainsString('about-page', $response->body);
        $this->assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testRootFallsBackToDefaultRoute(): void
    {
        $response = $this->route('/');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('home-page', $response->body);
    }

    public function testDirectoryIndexRoute(): void
    {
        $response = $this->route('/blog');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('blog-index', $response->body);
    }

    public function testDynamicSegmentRouteExposesParam(): void
    {
        $response = $this->route('/notes/hello-world');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('note:hello-world', $response->body);
    }

    public function testUnknownRouteRendersCustom404Page(): void
    {
        $response = $this->route('/no-such-page');
        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('custom-404', $response->body);
    }

    public function testTraversalPathsAreSanitisedNotServed(): void
    {
        // "../.." segments are stripped, the remaining route simply does not exist.
        $response = $this->route('/../../etc/passwd');
        $this->assertSame(404, $response->status);
    }

    public function testSitemapXmlIsServedFromConfig(): void
    {
        Config::set('app.url.base', 'https://example.com');
        Config::set('app.sitemap', ['/', '/about']);

        $response = $this->route('/sitemap.xml');
        $this->assertSame(200, $response->status);
        $this->assertSame('application/xml; charset=utf-8', $response->headers['Content-Type']);
        $this->assertStringContainsString('<loc>https://example.com/about</loc>', $response->body);
    }

    public function testSitemapConfigMayBeACallable(): void
    {
        Config::set('app.url.base', 'https://example.com');
        Config::set('app.sitemap', static fn(): array => ['/dynamic']);

        $response = $this->route('/sitemap.xml');
        $this->assertStringContainsString('<loc>https://example.com/dynamic</loc>', $response->body);
    }

    public function testRobotsTxtIsServed(): void
    {
        Config::set('app.url.base', 'https://example.com');
        $response = $this->route('/robots.txt');

        $this->assertSame(200, $response->status);
        $this->assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
        $this->assertStringContainsString('User-agent: *', $response->body);
    }

    public function testApiEndpointOutputIsCaptured(): void
    {
        $response = $this->route('/api/ping');
        $this->assertSame(200, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame(['ok' => true, 'method' => 'GET'], json_decode($response->body, true));
    }

    public function testUnknownApiRouteReturnsJson404(): void
    {
        $response = $this->route('/api/missing');
        $this->assertSame(404, $response->status);
        $data = json_decode($response->body, true);
        $this->assertFalse($data['success']);
        $this->assertSame('Not Found', $data['error']);
    }

    public function testLocalePrefixSelectsLocale(): void
    {
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);

        $response = $this->route('/ru/about');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('about-page', $response->body);
        $this->assertSame('ru', Locale::current());
    }

    public function testDefaultLocalePrefixRedirectsToUnprefixedUrl(): void
    {
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);

        $response = $this->route('/en/about');
        $this->assertSame(301, $response->status);
        $this->assertSame('/about', $response->headers['Location']);
    }
}
