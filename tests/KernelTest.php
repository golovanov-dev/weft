<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Http\Response;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\Kernel;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Security\RateLimiter;
use Golovanov\Weft\Seo\Seo;
use Golovanov\Weft\View\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Routing tests drive Kernel::route() directly (via reflection — it is private on purpose)
 * against the fixture site in tests/fixtures/site, without a web server. Kernel::handle()
 * itself (tracing, header emission, output) is exercised through the example site, not here.
 */
final class KernelTest extends TestCase
{
    /** Route $uri the way production does: the Request (and its path) comes from Request::fromGlobals(). */
    private function route(string $uri, string $method = 'GET'): Response
    {
        $server = $_SERVER;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;
        $request = Request::fromGlobals();
        $_SERVER = $server;

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

    /** A path Sanitize::path() would change is a 404, not the page its clean form names. */
    #[DataProvider('garbagePaths')]
    public function testPathThatIsNotCleanIsNotFound(string $path): void
    {
        $response = $this->route($path);
        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('custom-404', $response->body);
    }

    /** @return array<string, array{string}> */
    public static function garbagePaths(): array
    {
        return [
            'dropped characters' => ['/about!!'],
            'dropped characters and a trailing slash' => ['/about!!/'],
            'raw UTF-8' => ['/привет'],
            'traversal' => ['/../../etc/passwd'],
            'two trailing slashes' => ['/about//'],
            'two leading slashes' => ['//about'],
            'three leading slashes and a trailing one' => ['///about/'],
            'a host-like first segment' => ['//evil.com/'],
            'only two slashes' => ['//'],
        ];
    }

    /** Leading slashes are never redirected, even on GET: the target would not be this path. */
    public function testLeadingSlashesAreNotRedirected(): void
    {
        $this->assertSame(404, $this->route('//about/?next=x')->status);
        $this->assertSame(404, $this->route('///')->status);
    }

    #[RunInSeparateProcess]
    public function testLeadingSlashesAfterTheBaseUriAreNotFound(): void
    {
        define('BASE_URI', '/site');
        $this->assertSame(404, $this->route('/site//about')->status);
        $this->assertSame(404, $this->route('/site//')->status);
    }

    public function testLeadingSlashesUnderApiAreAJson404(): void
    {
        $response = $this->route('//api/ping');
        $this->assertSame(404, $response->status);
        $this->assertSame('Not Found', json_decode($response->body, true)['error']);
    }

    /** pages/blog/index.php has one address, /blog; /blog/index is a 404, not a redirect. */
    public function testDirectoryIndexByItsFileNameIsNotFound(): void
    {
        $response = $this->route('/blog/index');
        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('custom-404', $response->body);
        $this->assertSame(404, $this->route('/blog/index/')->status);
    }

    /** pages/404.php is what a 404 renders, not a page of its own. */
    public function testTheNotFoundPageRequestedDirectlyIsNotFound(): void
    {
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);

        $response = $this->route('/404');
        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('custom-404', $response->body);
        $this->assertSame(404, $this->route('/ru/404')->status);
    }

    public function testPathThatIsNotCleanUnderApiIsAJson404(): void
    {
        $response = $this->route('/api/ping!!', 'POST');
        $this->assertSame(404, $response->status);
        $this->assertSame('Not Found', json_decode($response->body, true)['error']);
    }

    public function testTrailingSlashRedirectsKeepingTheQueryString(): void
    {
        $response = $this->route('/about/?ref=nav');
        $this->assertSame(301, $response->status);
        $this->assertSame('/about?ref=nav', $response->headers['Location']);
    }

    #[RunInSeparateProcess]
    public function testRedirectKeepsTheBaseUri(): void
    {
        define('BASE_URI', '/site');
        $response = $this->route('/site/notes/hello/', 'HEAD');
        $this->assertSame(301, $response->status);
        $this->assertSame('/site/notes/hello', $response->headers['Location']);
    }

    public function testTrailingSlashOnPostIsServed(): void
    {
        $response = $this->route('/api/ping/', 'POST');
        $this->assertSame(200, $response->status);
        $this->assertSame(['ok' => true, 'method' => 'POST'], json_decode($response->body, true));
    }

    public function testDefaultRouteRedirectsToTheLocaleRoot(): void
    {
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);

        $this->assertSame('/', $this->route('/home')->headers['Location']);
        $this->assertSame('/ru', $this->route('/ru/home/')->headers['Location']);
        $this->assertSame('/ru', $this->route('/ru/')->headers['Location']);
    }

    public function testCanonicalComesFromTheMatchedRoute(): void
    {
        Config::set('app.url.base', 'https://example.com');
        $_SERVER['REQUEST_URI'] = '/about!!';

        $this->route('/notes/hello');
        $this->assertStringContainsString('<link rel="canonical" href="https://example.com/notes/hello" />', Seo::render());
    }

    public function testNotFoundPageHasNoCanonical(): void
    {
        Config::set('app.url.base', 'https://example.com');
        $_SERVER['REQUEST_URI'] = '/about!!';

        $this->route('/about!!');
        $html = Seo::render();
        $this->assertStringNotContainsString('canonical', $html);
        $this->assertStringNotContainsString('og:url', $html);
    }

    /** A dynamic page that answers 404 itself gets the same head as the engine's 404. */
    public function testPageThatAnswersNotFoundHasNoCanonicalOrHreflang(): void
    {
        Config::set('app.url.base', 'https://example.com');
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);

        $response = $this->route('/ru/notes/missing');
        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('note-missing', $response->body);
        $html = Seo::render();
        $this->assertStringNotContainsString('canonical', $html);
        $this->assertStringNotContainsString('og:url', $html);
        $this->assertStringNotContainsString('hreflang', $html);
    }

    public function testFoundPageHasHreflangFromTheMatchedRoute(): void
    {
        Config::set('app.url.base', 'https://example.com');
        Config::set('app.locales', ['enabled' => true, 'supported' => ['en', 'ru'], 'default' => 'en']);

        $this->route('/ru/notes/hello');
        $html = Seo::render();
        $this->assertStringContainsString('<link rel="alternate" hreflang="en" href="https://example.com/notes/hello" />', $html);
        $this->assertStringContainsString('<link rel="alternate" hreflang="ru" href="https://example.com/ru/notes/hello" />', $html);
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
        // One redirect straight to the final address.
        $this->assertSame('/', $this->route('/en/home/')->headers['Location']);
    }

    public function testApiEndpointThatThrowsLeavesNoBufferOpen(): void
    {
        $level = ob_get_level();
        try {
            $this->route('/api/broken');
            $this->fail('the endpoint exception must reach the kernel');
        } catch (\RuntimeException $e) {
            $this->assertSame('endpoint failed', $e->getMessage());
        }
        $this->assertSame($level, ob_get_level());
    }

    /** handle() end to end: a page that throws is a 500 without its half-rendered HTML, then alerts. */
    public function testPageExceptionIsA500WithoutPartialOutputAndRaisesTheAlert(): void
    {
        Config::set('app.alertloop', ['enabled' => true, 'base_url' => 'https://alerts.example', 'api_key' => 'k']);
        $alerts = [];
        $sentBeforeAlert = [];
        self::fakeHttp(static function (array $request) use (&$alerts, &$sentBeforeAlert): array {
            $alerts[] = json_decode((string) $request['body'], true);
            $sentBeforeAlert[] = (string) ob_get_contents();
            return ['status' => 202];
        });
        $_SERVER['REQUEST_URI'] = '/broken';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $level = ob_get_level();
        ob_start();
        Kernel::handle();
        $output = (string) ob_get_clean();

        $this->assertSame($level, ob_get_level());
        $this->assertStringContainsString('<h1>500</h1>', $output);
        $this->assertStringNotContainsString('half a page', $output);
        // The 500 went out before the alert waited for AlertLoop.
        $this->assertStringContainsString('<h1>500</h1>', $sentBeforeAlert[0] ?? '');

        // The alert is the one the exception handler raises for the same throw site. Drop the
        // "already reported" window, or the second send of that dedupe key is held back.
        RateLimiter::release('alert:' . $alerts[0]['dedupe_key']);
        try {
            View::render(APP_ROOT . '/pages/broken.php');
        } catch (\RuntimeException $e) {
            ErrorHandler::report($e, 'exception');
        }
        $this->assertCount(2, $alerts);
        $this->assertSame('Unhandled exception: render failed', $alerts[0]['message']);
        $this->assertSame($alerts[1], $alerts[0]);
    }

    /** Under FPM the 500 is out before the alert; a session left open would still hold the next request. */
    #[RunInSeparateProcess]
    public function testSessionIsReleasedBeforeTheAlertWaits(): void
    {
        Config::set('app.alertloop', ['enabled' => true, 'base_url' => 'https://alerts.example', 'api_key' => 'k']);
        $statuses = [];
        self::fakeHttp(static function () use (&$statuses): array {
            $statuses[] = session_status();
            return ['status' => 202];
        });
        $_SERVER['REQUEST_URI'] = '/broken';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $sessions = $this->makeTempDir();
        session_save_path($sessions);
        $this->assertTrue(session_start());

        try {
            ob_start();
            Kernel::handle();
            ob_end_clean();
        } finally {
            $this->removeDir($sessions);
        }

        $this->assertSame([PHP_SESSION_NONE], $statuses);
    }
}
