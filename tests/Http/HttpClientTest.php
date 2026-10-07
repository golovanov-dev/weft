<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Http;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Tests\TestCase;

/**
 * The outgoing request body in the trace follows app.trace.log_body. The URL uses a scheme curl
 * does not support, so the request fails before any network I/O; the trace event is written first.
 */
final class HttpClientTest extends TestCase
{
    private const URL = 'weft-test://crm.invalid/leads';

    /** @return array<string, mixed>|null data of the http_out_request event of the current trace */
    private function outgoingBody(): ?array
    {
        $id = Trace::id();
        Trace::flush();
        foreach (glob(sys_get_temp_dir() . '/weft-logs/requests/*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = json_decode($line, true);
                if (is_array($record) && ($record['trace_id'] ?? null) === $id && ($record['event'] ?? null) === 'http_out_request') {
                    return $record['data']['body'];
                }
            }
        }
        $this->fail('http_out_request was not written');
    }

    public function testBodyIsSummarisedByDefault(): void
    {
        (new HttpClient())->post(self::URL, ['phone' => '+79001234567']);

        $this->assertSame(['size' => 24, 'content_type' => 'application/json'], $this->outgoingBody());
    }

    public function testBodyIsParsedAndMaskedWhenEnabled(): void
    {
        Config::set('app.trace.log_body', true);
        Config::set('app.trace.sensitive_keys', ['phone']);

        (new HttpClient())->post(self::URL, ['name' => 'Ann', 'phone' => '+79001234567'], ['json' => false]);

        $this->assertSame(['name' => 'Ann', 'phone' => '[REDACTED]'], $this->outgoingBody()['data']);
    }
}
