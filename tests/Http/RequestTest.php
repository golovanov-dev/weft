<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Http;

use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Tests\TestCase;

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
