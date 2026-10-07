<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Observability;

use Golovanov\Traceloom\Configuration;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the trace keeps about a request: body summary vs. parsed body (app.trace.log_body) and the
 * traceloom settings taken from app.trace.*.
 */
final class TraceTest extends TestCase
{
    /** @param array<string, mixed> $post */
    private function post(string $contentType, string $raw, array $post = []): Request
    {
        return new Request(
            method: 'POST',
            uri: '/api/contact',
            path: '/api/contact',
            clientIp: '127.0.0.1',
            headers: ['content-type' => $contentType, 'content-length' => (string) strlen($raw)],
            query: [],
            post: $post,
            rawBody: $raw,
        );
    }

    /**
     * The `request_start` event of the current trace, read back from the JSONL timeline, plus the
     * raw line it was read from.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function requestStart(Request $request): array
    {
        $id = Trace::boot($request);
        Trace::httpStart($request);
        Trace::flush();

        foreach (glob(sys_get_temp_dir() . '/weft-logs/requests/*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = json_decode($line, true);
                if (is_array($record) && ($record['trace_id'] ?? null) === $id && ($record['event'] ?? null) === 'request_start') {
                    return [$record['data'], $line];
                }
            }
        }
        $this->fail('request_start was not written');
    }

    private function configuration(): Configuration
    {
        $tracer = (new \ReflectionClass(Trace::class))->getProperty('tracer')->getValue();
        return (new \ReflectionProperty($tracer, 'configuration'))->getValue($tracer);
    }

    public function testBodyIsSummarisedByDefault(): void
    {
        [$data, $line] = $this->requestStart($this->post('application/x-www-form-urlencoded', 'name=Ann&phone=%2B79001234567'));

        $this->assertSame(['size' => 29, 'content_type' => 'application/x-www-form-urlencoded'], $data['body']);
        $this->assertStringNotContainsString('79001234567', $line);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function parsedBodies(): iterable
    {
        yield 'json' => ['application/json; charset=utf-8', '{"name":"Ann","phone":"+79001234567"}', []];
        yield 'urlencoded' => ['application/x-www-form-urlencoded', 'name=Ann&phone=%2B79001234567', []];
        yield 'multipart' => ['multipart/form-data; boundary=x', '', ['name' => 'Ann', 'phone' => '+79001234567']];
    }

    /** @param array<string, mixed> $post */
    #[DataProvider('parsedBodies')]
    public function testBodyIsParsedAndMaskedWhenEnabled(string $type, string $raw, array $post): void
    {
        Config::set('app.trace.log_body', true);
        Config::set('app.trace.sensitive_keys', ['phone']);
        $request = new Request('POST', '/f', '/f', '127.0.0.1', ['content-type' => $type, 'content-length' => '120'], [], $post, $raw);

        [$data, $line] = $this->requestStart($request);

        $this->assertSame(['name' => 'Ann', 'phone' => Configuration::REDACTED], $data['body']['data']);
        $this->assertStringNotContainsString('79001234567', $line);
    }

    public function testUnparseableBodyIsSummarisedEvenWhenEnabled(): void
    {
        Config::set('app.trace.log_body', true);

        [$data, $line] = $this->requestStart($this->post('text/plain', 'phone +79001234567'));

        $this->assertSame(['size' => 18, 'content_type' => 'text/plain'], $data['body']);
        $this->assertStringNotContainsString('79001234567', $line);
    }

    /** @return iterable<string, array{string}> */
    public static function notForms(): iterable
    {
        yield 'json, no "="' => ['{"name":"Ann","phone":"+79001234567"}'];
        yield 'text with "="' => ['phone +79001234567 = call me'];
    }

    #[DataProvider('notForms')]
    public function testUrlencodedBodyThatIsNotAFormIsSummarised(string $raw): void
    {
        Config::set('app.trace.log_body', true);

        [$data, $line] = $this->requestStart($this->post('application/x-www-form-urlencoded', $raw));

        $this->assertSame(['size' => strlen($raw), 'content_type' => 'application/x-www-form-urlencoded'], $data['body']);
        $this->assertStringNotContainsString('79001234567', $line);
    }

    public function testLogBodyIsOnOnlyForARealTrue(): void
    {
        Config::set('app.trace.log_body', 'false');

        [$data] = $this->requestStart($this->post('application/x-www-form-urlencoded', 'name=Ann'));

        $this->assertArrayNotHasKey('data', $data['body']);
    }

    public function testSensitiveKeysGivenAsAStringIsOneKey(): void
    {
        Config::set('app.trace.log_body', true);
        Config::set('app.trace.sensitive_keys', 'phone');

        [$data] = $this->requestStart($this->post('application/x-www-form-urlencoded', 'name=Ann&phone=%2B79001234567'));

        $this->assertSame(['name' => 'Ann', 'phone' => Configuration::REDACTED], $data['body']['data']);
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function retention(): iterable
    {
        yield 'unset' => [null, 30];
        yield 'configured' => [7, 7];
        yield 'invalid' => [-1, 30];
    }

    #[DataProvider('retention')]
    public function testRetentionDaysReachTraceloom(mixed $configured, int $expected): void
    {
        if ($configured !== null) {
            Config::set('app.trace.retention_days', $configured);
        }

        Trace::boot();

        $this->assertSame($expected, $this->configuration()->retentionDays);
    }
}
