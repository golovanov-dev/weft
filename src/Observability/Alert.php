<?php

declare(strict_types=1);

namespace Golovanov\Weft\Observability;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;

/**
 * AlertLoop client — fire notifications for sensitive spots (payment failures, captcha
 * hard-fails, unhandled errors, suspicious input, ...).
 *
 * Sends a single event to the AlertLoop ingest API (POST /v1/events, X-API-Key auth).
 * Delivery is best-effort and non-blocking: nothing is retried, no exception ever leaves
 * this class, and a failure never breaks the main request flow. It is not silent, though —
 * a failed delivery is recorded as an `alert.failed` trace event (metadata only, never the
 * alert payload). Enabled via config('alertloop.enabled').
 *
 * @see https://github.com/golovanov-dev/alertloop
 */
final class Alert
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const INFO = 'info';

    /** Re-entry guard: reporting a failed alert must never trigger another alert. */
    private static bool $reporting = false;

    /**
     * Transport seam. Null means the built-in curl transport; tests replace it to exercise
     * success, transport errors and non-2xx responses without a network.
     *
     * @var null|\Closure(string, string, array<string, mixed>): array{status: int, error: string}
     */
    private static ?\Closure $transport = null;

    /**
     * @param array<string, mixed> $context free-form data sent as the event's `payload`
     */
    public static function send(
        string $message,
        string $severity = self::WARNING,
        string $type = 'incident',
        ?string $dedupeKey = null,
        array $context = [],
        ?string $category = null,
    ): bool {
        if (!Config::get('alertloop.enabled', false)) {
            return false;
        }

        $baseUrl = rtrim((string) Config::get('alertloop.base_url', ''), '/');
        $apiKey = (string) Config::get('alertloop.api_key', '');
        if ($baseUrl === '' || $apiKey === '') {
            return false;
        }

        // trace_id is a top-level field of the AlertLoop event schema, not payload data:
        // lift it out of $context so correlation with the trace survives ingestion.
        $traceId = isset($context['trace_id']) ? (string) $context['trace_id'] : RequestContext::traceId();
        unset($context['trace_id']);

        $payload = [
            'type' => $type,
            'severity' => $severity,
            'source' => (string) Config::get('alertloop.source', 'weft-site'),
            'message' => $message,
        ];
        if ($category !== null && $category !== '') {
            $payload['category'] = $category;
        }
        if ($traceId !== '') {
            $payload['trace_id'] = $traceId;
        }
        if ($dedupeKey !== null) {
            $payload['dedupe_key'] = $dedupeKey;
        }
        if ($context !== []) {
            $payload['payload'] = $context;
        }

        return self::post($baseUrl . '/v1/events', $apiKey, $payload);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function critical(string $message, array $context = [], ?string $dedupeKey = null): bool
    {
        return self::send($message, self::CRITICAL, 'incident', $dedupeKey, $context);
    }

    /**
     * Convenience: register this as the Database error notifier in bootstrap.
     */
    public static function notifier(): callable
    {
        return static fn(string $message): bool => self::send(
            $message,
            self::CRITICAL,
            'incident',
            null,
            [],
            'database',
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function post(string $url, string $apiKey, array $payload): bool
    {
        $transport = self::$transport ?? self::curl(...);

        try {
            $result = $transport($url, $apiKey, $payload);
        } catch (\Throwable $e) {
            // Best-effort by contract: the caller must not see a delivery problem as an error.
            self::reportFailure($payload, 0, true, $e->getMessage());
            return false;
        }

        if ($result['error'] !== '') {
            self::reportFailure($payload, $result['status'], true, $result['error']);
            return false;
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            self::reportFailure($payload, $result['status'], false, 'unexpected HTTP status');
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: int, error: string}
     */
    private static function curl(string $url, string $apiKey, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'error' => 'curl_init() failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => (int) Config::get('alertloop.timeout', 4),
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['status' => $status, 'error' => $error !== '' ? $error : 'transport error'];
        }
        return ['status' => $status, 'error' => ''];
    }

    /**
     * Record an undelivered alert on the trace timeline.
     *
     * Metadata only: the alert payload can carry user input and secrets, so nothing from it
     * is written except the routing fields. Reporting is itself best-effort — if tracing is
     * unavailable the failure falls back to error_log(), and any throw is swallowed, because
     * this runs on the error path (ErrorHandler) where a second failure has nowhere to go.
     *
     * @param array<string, mixed> $payload
     */
    private static function reportFailure(array $payload, int $status, bool $transportError, string $error): void
    {
        if (self::$reporting) {
            return;
        }
        self::$reporting = true;

        $data = [
            'event_type' => (string) ($payload['type'] ?? ''),
            'severity' => (string) ($payload['severity'] ?? ''),
            'category' => (string) ($payload['category'] ?? ''),
            'dedupe_key' => (string) ($payload['dedupe_key'] ?? ''),
            'status' => $status,
            'transport_error' => $transportError,
            'error' => mb_substr($error, 0, 200),
        ];

        try {
            if (Trace::isActive()) {
                Trace::event('alert.failed', $data);
            } else {
                // No trace to attach to (CLI, or a failure before the Kernel booted one):
                // do not start one from an error path, just leave a line in the PHP log.
                $line = json_encode(['alert.failed' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($line !== false) {
                    @error_log('weft ' . $line);
                }
            }
        } catch (\Throwable) {
            // Alerting and tracing are both best-effort; there is nothing left to fall back to.
        } finally {
            self::$reporting = false;
        }
    }
}
