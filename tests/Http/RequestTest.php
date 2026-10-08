<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Http;

use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class RequestTest extends TestCase
{
    private function makeRequest(array $overrides = []): Request
    {
        $defaults = [
            'method' => 'GET',
            'uri' => '/about?x=1',
            'path' => '/about',
            'clientIp' => '203.0.113.7',
            'headers' => [],
            'query' => [],
            'post' => [],
            'rawBody' => null,
        ];
        $args = array_merge($defaults, $overrides);
        return new Request(...$args);
    }

    public function testFromGlobalsCollectsMethodPathHeadersAndInput(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI'] = '/contact?src=nav';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_GET = ['src' => 'nav'];
        $_POST = ['name' => 'Ann'];

        $request = Request::fromGlobals();

        $this->assertSame('POST', $request->method);
        $this->assertSame('/contact', $request->path);
        $this->assertSame('/contact?src=nav', $request->uri);
        $this->assertSame('203.0.113.7', $request->clientIp);
        $this->assertSame('XMLHttpRequest', $request->header('X-Requested-With'));
        $this->assertSame('application/x-www-form-urlencoded', $request->header('content-type'));
        $this->assertSame(['src' => 'nav'], $request->query);
        $this->assertSame(['name' => 'Ann'], $request->post);

        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_X_REQUESTED_WITH'],
            $_SERVER['CONTENT_TYPE'],
        );
    }

    public function testForwardedHeadersAreIgnoredWithoutTrustedProxies(): void
    {
        // PROXY_IPS is not defined in tests -> X-Forwarded-For must never win.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.99';

        $request = Request::fromGlobals();
        $this->assertSame('203.0.113.7', $request->clientIp);

        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    /**
     * @param array<string, string> $server
     * @param array<mixed>          $trusted
     */
    #[DataProvider('proxyChains')]
    public function testClientIpBehindTrustedProxies(array $server, array $trusted, string $expected): void
    {
        $resolve = new \ReflectionMethod(Request::class, 'resolveClientIp');
        $this->assertSame($expected, $resolve->invoke(null, $server, $trusted));
    }

    /** @return array<string, array{0: array<string, string>, 1: array<mixed>, 2: string}> */
    public static function proxyChains(): array
    {
        $cdn = ['REMOTE_ADDR' => '173.245.48.10'];
        return [
            'exact address' => [
                ['REMOTE_ADDR' => '203.0.113.10', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'],
                ['203.0.113.10'], '198.51.100.1',
            ],
            'remote not trusted' => [
                ['REMOTE_ADDR' => '203.0.113.11', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'],
                ['203.0.113.10'], '203.0.113.11',
            ],
            'ipv4 range, two trusted hops, forged left entry' => [
                $cdn + ['HTTP_X_FORWARDED_FOR' => '1.1.1.1, 198.51.100.1, 10.0.0.2'],
                ['173.245.48.0/20', '10.0.0.0/8'], '198.51.100.1',
            ],
            'range boundary inside a byte' => [
                ['REMOTE_ADDR' => '192.0.2.200', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'],
                ['192.0.2.0/25'], '192.0.2.200',
            ],
            'ipv6 range' => [
                ['REMOTE_ADDR' => '2400:cb00:2049::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::7'],
                ['2400:cb00::/32'], '2001:db8::7',
            ],
            'X-Client-Ip is not read' => [
                $cdn + ['HTTP_X_CLIENT_IP' => '1.1.1.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'],
                ['173.245.48.0/20'], '198.51.100.1',
            ],
            'no X-Forwarded-For' => [$cdn, ['173.245.48.0/20'], '173.245.48.10'],
            'invalid entry on the way' => [
                $cdn + ['HTTP_X_FORWARDED_FOR' => '198.51.100.1, unknown'],
                ['173.245.48.0/20'], '173.245.48.10',
            ],
            'every entry trusted' => [
                $cdn + ['HTTP_X_FORWARDED_FOR' => '10.0.0.2'],
                ['173.245.48.0/20', '10.0.0.0/8'], '173.245.48.10',
            ],
            'invalid PROXY_IPS entries are skipped' => [
                $cdn + ['HTTP_X_FORWARDED_FOR' => '198.51.100.1'],
                ['proxy', 42, '173.245.48.0/99', '173.245.48.0/x', '173.245.48.0/', '173.245.48.10'],
                '198.51.100.1',
            ],
        ];
    }

    public function testInvalidProxyIpsListsEntriesThatAreNeitherAnAddressNorARange(): void
    {
        $this->assertSame(
            ['localhost', 42, '173.245.48.0/99', '173.245.48.0/'],
            Request::invalidProxyIps(['localhost', '203.0.113.10', 42, '173.245.48.0/99', '2400:cb00::/32', '173.245.48.0/']),
        );
        // PROXY_IPS is not defined in tests.
        $this->assertSame([], Request::invalidProxyIps());
    }

    public function testTrustAllProxyIpsListsTooWideEntries(): void
    {
        $this->assertSame(
            ['0.0.0.0/0', ' ::/0 ', '10.0.0.0/00', '0.0.0.0/7', '2000::/15'],
            Request::trustAllProxyIps([
                '0.0.0.0/0', '10.0.0.0/8', ' ::/0 ', '127.0.0.1', '10.0.0.0/00', 'x/0', 0,
                '0.0.0.0/7', '2000::/15', '2400:cb00::/16', '173.245.48.0/20',
            ]),
        );
        // PROXY_IPS is not defined in tests.
        $this->assertSame([], Request::trustAllProxyIps());
        $this->assertNull(Request::proxyIpsError());
    }

    #[RunInSeparateProcess]
    public function testProxyIpsErrorWhenTheConstantIsNotAnArray(): void
    {
        define('PROXY_IPS', '10.0.0.1');

        $this->assertSame('PROXY_IPS must be an array, string given; no proxy is trusted', Request::proxyIpsError());
        $this->assertSame([], Request::invalidProxyIps());
    }

    public function testHeaderLookupIsCaseInsensitiveWithDefault(): void
    {
        $request = $this->makeRequest(['headers' => ['x-custom' => 'v']]);
        $this->assertSame('v', $request->header('X-Custom'));
        $this->assertSame('d', $request->header('Missing', 'd'));
        $this->assertNull($request->header('Missing'));
    }

    public function testIsPost(): void
    {
        $this->assertFalse($this->makeRequest()->isPost());
        $this->assertTrue($this->makeRequest(['method' => 'POST'])->isPost());
    }

    public function testWantsJsonViaXhrHeaderOrAccept(): void
    {
        $this->assertTrue($this->makeRequest(['headers' => ['x-requested-with' => 'XMLHttpRequest']])->wantsJson());
        $this->assertTrue($this->makeRequest(['headers' => ['accept' => 'application/json, */*']])->wantsJson());
        $this->assertFalse($this->makeRequest(['headers' => ['accept' => 'text/html']])->wantsJson());
        $this->assertFalse($this->makeRequest()->wantsJson());
    }

    public function testBodyDecodesJsonWhenContentTypeIsJson(): void
    {
        $request = $this->makeRequest([
            'method' => 'POST',
            'headers' => ['content-type' => 'application/json'],
            'rawBody' => '{"a":1,"b":"x"}',
        ]);
        $this->assertSame(['a' => 1, 'b' => 'x'], $request->body());
    }

    public function testBodyFallsBackToFormPost(): void
    {
        $request = $this->makeRequest([
            'method' => 'POST',
            'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
            'post' => ['name' => 'Ann'],
        ]);
        $this->assertSame(['name' => 'Ann'], $request->body());
    }

    public function testBodyReturnsEmptyArrayForInvalidJson(): void
    {
        $request = $this->makeRequest([
            'method' => 'POST',
            'headers' => ['content-type' => 'application/json'],
            'rawBody' => '{broken',
        ]);
        $this->assertSame([], $request->body());
    }
}
