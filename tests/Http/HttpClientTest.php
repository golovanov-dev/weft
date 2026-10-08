<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Http;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Tests\TestCase;

/**
 * What the trace keeps about an outgoing request: the body (app.trace.log_body) and the URL with
 * its query string parsed out. The URL uses a scheme curl does not support, so the request fails
 * before any network I/O; the trace events are written anyway.
 */
final class HttpClientTest extends TestCase
{
    private const URL = 'weft-test://crm.invalid/leads';

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

    public function testBodyIsParsedByDefault(): void
    {
        (new HttpClient())->post(self::URL, ['name' => 'Ann', 'email' => 'ann@example.com']);

        $this->assertSame(
            ['size' => 40, 'content_type' => 'application/json', 'data' => ['name' => 'Ann', 'email' => 'ann@example.com']],
            $this->outgoing('http_out_request')[0]['body'],
        );
    }

    public function testBodyIsSummarisedWhenLogBodyIsOff(): void
    {
        Config::set('app.trace.log_body', false);

        (new HttpClient())->post(self::URL, ['phone' => '+79001234567']);

        $this->assertSame(['size' => 24, 'content_type' => 'application/json'], $this->outgoing('http_out_request')[0]['body']);
    }

    public function testBodyIsMaskedBySensitiveKeys(): void
    {
        Config::set('app.trace.sensitive_keys', ['phone']);

        (new HttpClient())->post(self::URL, ['name' => 'Ann', 'phone' => '+79001234567'], ['json' => false]);

        $this->assertSame(['name' => 'Ann', 'phone' => '[REDACTED]'], $this->outgoing('http_out_request')[0]['body']['data']);
    }

    public function testUnparseableBodyIsLoggedAsTheRawString(): void
    {
        (new HttpClient())->post(self::URL, '<lead>Ann</lead>');

        $this->assertSame('<lead>Ann</lead>', $this->outgoing('http_out_request')[0]['body']['data']);
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

    public function testQueryNameThatIsNotUtf8KeepsTheEvent(): void
    {
        (new HttpClient())->request(self::URL . '?%E8%EC%FF=1&api_key=k3y-value');

        foreach (['http_out_request', 'http_out_response'] as $event) {
            [$data, $line] = $this->outgoing($event);
            $this->assertSame(self::URL, $data['url'], $event);
            $this->assertSame(['api_key' => '[REDACTED]', "\u{FFFD}\u{FFFD}" => '1'], $data['query'], $event);
            $this->assertStringNotContainsString('k3y-value', $line, $event);
        }
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
