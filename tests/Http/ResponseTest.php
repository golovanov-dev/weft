<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Http;

use Golovanov\Weft\Http\Response;
use Golovanov\Weft\Tests\TestCase;

final class ResponseTest extends TestCase
{
    public function testHtmlFactory(): void
    {
        $response = Response::html('<h1>Hi</h1>');
        $this->assertSame(200, $response->status);
        $this->assertSame('<h1>Hi</h1>', $response->body);
        $this->assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);

        $this->assertSame(404, Response::html('nope', 404)->status);
    }

    public function testTextFactory(): void
    {
        $response = Response::text('plain');
        $this->assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame('plain', $response->body);
    }

    public function testJsonFactoryEncodesBody(): void
    {
        $response = Response::json(['ok' => true, 'path' => '/a/b'], 201);
        $this->assertSame(201, $response->status);
        $this->assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        $this->assertSame('{"ok":true,"path":"/a/b"}', $response->body);
    }

    public function testRedirectFactory(): void
    {
        $response = Response::redirect('/target');
        $this->assertSame(302, $response->status);
        $this->assertSame('/target', $response->headers['Location']);
        $this->assertSame('', $response->body);

        $this->assertSame(301, Response::redirect('/x', 301)->status);
    }

    public function testWithHeaderAddsHeaderFluently(): void
    {
        $response = Response::text('x')->withHeader('X-Custom', 'v');
        $this->assertSame('v', $response->headers['X-Custom']);
        $this->assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testSendEchoesBody(): void
    {
        ob_start();
        Response::text('body-out')->send();
        $out = ob_get_clean();

        $this->assertSame('body-out', $out);
        // send() sets the process-wide response code; reset it for later tests.
        http_response_code(200);
    }
}
