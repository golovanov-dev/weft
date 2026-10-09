<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Http;

use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Tests\TestCase;

/**
 * What a call returns for the response it gets, and what the trace keeps about it: the body as
 * HttpClient hands it to Trace::body() (how a body is parsed and masked is TraceTest's) and the
 * URL with its query string parsed out. The transport is a closure, so no network is involved.
 */
final class HttpClientTest extends TestCase
{
    private const URL = 'https://crm.example/leads';

    protected function setUp(): void
    {
        parent::setUp();
        self::fakeHttp(static fn(): array => ['body' => '{"id":1}']);
    }

    /** @return array{0: array<string, mixed>, 1: string} data of an event of the current trace, and its raw line */
    private function outgoing(string $event): array
    {
        $id = Trace::id();
        Trace::flush();
        foreach (glob(Trace::directory() . '/*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = json_decode($line, true);
                if (is_array($record) && ($record['trace_id'] ?? null) === $id && ($record['event'] ?? null) === $event) {
                    return [$record['data'], $line];
                }
            }
        }
        $this->fail($event . ' was not written');
    }

    public function testErrorStatusIsNotOkAndKeepsStatusAndBody(): void
    {
        self::fakeHttp(static fn(): array => ['status' => 503, 'body' => '{"error":"maintenance"}']);

        $response = (new HttpClient())->get(self::URL);

        $this->assertFalse($response['ok']);
        $this->assertSame(503, $response['status']);
        $this->assertSame('HTTP 503', $response['error']);
        $this->assertSame(['error' => 'maintenance'], $response['body']);
        $this->assertFalse($this->outgoing('http_out_response')[0]['ok']);
    }

    public function testBodyIsParsedByDefault(): void
    {
        (new HttpClient())->post(self::URL, ['name' => 'Ann', 'email' => 'ann@example.com']);

        $this->assertSame(
            ['size' => 40, 'content_type' => 'application/json', 'data' => ['name' => 'Ann', 'email' => 'ann@example.com']],
            $this->outgoing('http_out_request')[0]['body'],
        );
    }

    public function testArrayBodyWithJsonOffIsParsedAsAFormAndMaskedByKey(): void
    {
        (new HttpClient())->post(self::URL, ['name' => 'Ann', 'password' => 's3cret'], ['json' => false]);

        [$data, $line] = $this->outgoing('http_out_request');
        $this->assertSame('application/x-www-form-urlencoded', $data['body']['content_type']);
        $this->assertSame(['name' => 'Ann', 'password' => '[REDACTED]'], $data['body']['data']);
        $this->assertStringNotContainsString('s3cret', $line);
    }

    public function testStringBodyWithJsonOffIsLoggedAsTheRawString(): void
    {
        $xml = '<?xml version="1.0"?><lead><name>Ann</name></lead>';

        (new HttpClient())->post(self::URL, $xml, ['json' => false]);

        $body = $this->outgoing('http_out_request')[0]['body'];
        $this->assertSame('application/x-www-form-urlencoded', $body['content_type'], 'what is sent does not change');
        $this->assertSame($xml, $body['data']);
    }

    public function testJsonStringBodyWithJsonOffIsStillParsed(): void
    {
        (new HttpClient())->post(self::URL, '{"name":"Ann","password":"s3cret"}', ['json' => false]);

        [$data, $line] = $this->outgoing('http_out_request');
        $this->assertSame(['name' => 'Ann', 'password' => '[REDACTED]'], $data['body']['data']);
        $this->assertStringNotContainsString('s3cret', $line);
    }

    public function testUrlIsLoggedWithoutItsQueryAndTheQueryIsMaskedByKey(): void
    {
        (new HttpClient())->get(self::URL, ['page' => '2', 'api_key' => 'k3y-value']);

        foreach (['http_out_request', 'http_out_response'] as $event) {
            [$data, $line] = $this->outgoing($event);
            $this->assertSame(self::URL, $data['url'], $event);
            $this->assertSame(['page' => '2', 'api_key' => '[REDACTED]'], $data['query'], $event);
            $this->assertStringNotContainsString('k3y-value', $line, $event);
        }
    }
}
