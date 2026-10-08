<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Observability;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Tests\TestCase;

/**
 * Alert delivery: the shape of the event sent to AlertLoop and what happens when delivery
 * fails. The HTTP transport is replaced with a closure, so no network is involved.
 */
final class AlertTest extends TestCase
{
    /** Top-level fields the AlertLoop ingest API accepts (EventInput). */
    private const SCHEMA_FIELDS = [
        'type',
        'severity',
        'source',
        'category',
        'message',
        'entity_type',
        'entity_id',
        'trace_id',
        'dedupe_key',
        'payload',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.alertloop.enabled', true);
        Config::set('app.alertloop.base_url', 'https://alerts.example/');
        Config::set('app.alertloop.api_key', 'test-key');
        Config::set('app.alertloop.source', 'weft-tests');
    }

    /**
     * Install a fake transport.
     *
     * @param array{status: int, error: string}|\Throwable $result
     * @param array<string, mixed>|null $captured filled with the payload the transport received
     */
    private function fakeTransport(array|\Throwable $result, ?array &$captured = null, ?string &$url = null): void
    {
        $transport = static function (string $u, string $key, array $payload) use ($result, &$captured, &$url): array {
            $captured = $payload;
            $url = $u;
            if ($result instanceof \Throwable) {
                throw $result;
            }
            return $result;
        };

        (new \ReflectionClass(Alert::class))->getProperty('transport')->setValue(null, $transport);
    }

    /** @param list<array<string, mixed>> $events */
    private function failureEvents(array $events): array
    {
        return array_values(array_filter(
            $events,
            static fn(array $e): bool => ($e['event'] ?? $e['type'] ?? null) === 'alert.failed',
        ));
    }

    public function testDisabledAlertingSendsNothing(): void
    {
        Config::set('app.alertloop.enabled', false);
        $this->fakeTransport(['status' => 200, 'error' => ''], $captured);

        $this->assertFalse(Alert::send('nope'));
        $this->assertNull($captured);
    }

    public function testEnabledWithoutAKeySendsNothingAndLogsOncePerRequest(): void
    {
        Config::set('app.alertloop.api_key', '');
        $this->fakeTransport(['status' => 200, 'error' => ''], $captured);
        $logOffset = self::errorLogSize();

        $this->assertSame(['api_key'], Alert::missingKeys());
        $this->assertFalse(Alert::send('nope'));
        $this->assertFalse(Alert::critical('nope again'));
        $this->assertNull($captured);

        $log = self::errorLogSince($logOffset);
        $this->assertStringContainsString('ALERT ERRNO:500 | AlertLoop is enabled without api_key, alerts are not sent', $log);
        $this->assertSame(1, substr_count($log, "
"));
    }

    public function testMissingKeysAreReportedOnlyWhileAlertingIsOn(): void
    {
        Config::set('app.alertloop.base_url', '');
        $this->assertSame(['base_url'], Alert::missingKeys());

        Config::set('app.alertloop.enabled', false);
        $this->assertSame([], Alert::missingKeys());
    }

    public function testPayloadUsesOnlyFieldsFromTheAlertLoopSchema(): void
    {
        $this->fakeTransport(['status' => 202, 'error' => ''], $captured, $url);

        $ok = Alert::send(
            'Payment failed',
            Alert::CRITICAL,
            'incident',
            'pay:42',
            ['order_id' => 42, 'amount' => 100],
            'payments',
        );

        $this->assertTrue($ok);
        $this->assertSame('https://alerts.example/v1/events', $url);
        $this->assertSame([], array_diff(array_keys((array) $captured), self::SCHEMA_FIELDS));
        $this->assertSame('incident', $captured['type']);
        $this->assertSame('critical', $captured['severity']);
        $this->assertSame('weft-tests', $captured['source']);
        $this->assertSame('payments', $captured['category']);
        $this->assertSame('Payment failed', $captured['message']);
        $this->assertSame('pay:42', $captured['dedupe_key']);
    }

    public function testContextIsSentAsPayloadNotContext(): void
    {
        $this->fakeTransport(['status' => 200, 'error' => ''], $captured);

        Alert::send('msg', context: ['order_id' => 42]);

        $this->assertArrayNotHasKey('context', (array) $captured);
        $this->assertSame(['order_id' => 42], $captured['payload']);
    }

    public function testEmptyContextOmitsThePayloadField(): void
    {
        $this->fakeTransport(['status' => 200, 'error' => ''], $captured);

        Alert::send('msg');

        $this->assertArrayNotHasKey('payload', (array) $captured);
    }

    public function testTraceIdIsLiftedOutOfTheContextToTheTopLevel(): void
    {
        $this->fakeTransport(['status' => 200, 'error' => ''], $captured);

        Alert::critical('boom', ['trace_id' => 'abc123', 'file' => 'x.php:10']);

        $this->assertSame('abc123', $captured['trace_id']);
        $this->assertSame(['file' => 'x.php:10'], $captured['payload']);
    }

    public function testTraceIdDefaultsToTheCurrentRequestTrace(): void
    {
        $traceId = Trace::boot();
        $this->fakeTransport(['status' => 200, 'error' => ''], $captured);

        Alert::send('msg');

        $this->assertSame($traceId, $captured['trace_id']);
    }

    public function testNotifierSendsACriticalDatabaseAlertWithTheTraceId(): void
    {
        $traceId = Trace::boot();
        $this->fakeTransport(['status' => 200, 'error' => ''], $captured);

        $notifier = Alert::notifier();
        $this->assertTrue($notifier('Query failed'));
        $this->assertSame('critical', $captured['severity']);
        $this->assertSame('database', $captured['category']);
        $this->assertSame($traceId, $captured['trace_id']);
        $this->assertArrayNotHasKey('payload', (array) $captured);
    }

    public function testSuccessfulDeliveryRecordsNoFailure(): void
    {
        $traceId = Trace::boot();
        $this->fakeTransport(['status' => 200, 'error' => '']);

        $this->assertTrue(Alert::send('all good'));
        $this->assertSame([], $this->failureEvents($this->traceEvents($traceId)));
    }

    public function testTransportErrorIsRecordedOnTheTrace(): void
    {
        $traceId = Trace::boot();
        $this->fakeTransport(['status' => 0, 'error' => 'Could not resolve host']);

        $this->assertFalse(Alert::send('lost', Alert::CRITICAL, 'incident', null, [], 'payments'));

        $failures = $this->failureEvents($this->traceEvents($traceId));
        $this->assertCount(1, $failures);
        $data = $failures[0]['data'];
        $this->assertTrue($data['transport_error']);
        $this->assertSame(0, $data['status']);
        $this->assertSame('incident', $data['event_type']);
        $this->assertSame('critical', $data['severity']);
        $this->assertSame('payments', $data['category']);
    }

    public function testNonSuccessStatusIsRecordedOnTheTrace(): void
    {
        $traceId = Trace::boot();
        $this->fakeTransport(['status' => 500, 'error' => '']);

        $this->assertFalse(Alert::send('lost'));

        $failures = $this->failureEvents($this->traceEvents($traceId));
        $this->assertCount(1, $failures);
        $this->assertSame(500, $failures[0]['data']['status']);
        $this->assertFalse($failures[0]['data']['transport_error']);
    }

    public function testFailureRecordNeverCarriesTheAlertBody(): void
    {
        $traceId = Trace::boot();
        $this->fakeTransport(['status' => 503, 'error' => '']);

        Alert::send('Card declined for user@example.com', context: ['card' => '4111111111111111']);

        $failures = $this->failureEvents($this->traceEvents($traceId));
        $this->assertCount(1, $failures);
        $encoded = (string) json_encode($failures[0]);
        $this->assertStringNotContainsString('4111111111111111', $encoded);
        $this->assertStringNotContainsString('user@example.com', $encoded);
    }

    public function testAThrowingTransportNeverEscapesAndIsRecorded(): void
    {
        $traceId = Trace::boot();
        $this->fakeTransport(new \RuntimeException('transport exploded'));

        $this->assertFalse(Alert::send('lost'));

        $failures = $this->failureEvents($this->traceEvents($traceId));
        $this->assertCount(1, $failures);
        $this->assertTrue($failures[0]['data']['transport_error']);
    }

    public function testFailureWithoutAnActiveTraceFallsBackToTheErrorLog(): void
    {
        $this->fakeTransport(['status' => 500, 'error' => '']);

        $dir = $this->makeTempDir();
        $log = $dir . '/php-error.log';
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $log);

        try {
            $this->assertFalse(Alert::send('lost'));
        } finally {
            ini_set('error_log', $previous);
        }

        // No trace was booted from inside the error path, and the failure is still on record.
        $this->assertFalse(Trace::isActive());
        $this->assertStringContainsString('alert.failed', (string) @file_get_contents($log));

        $this->removeDir($dir);
    }
}
