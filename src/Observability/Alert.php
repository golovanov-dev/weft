<?php

declare(strict_types=1);

namespace Golovanov\Weft\Observability;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Security\RateLimiter;

/**
 * AlertLoop client — fire notifications for sensitive spots (payment failures, captcha
 * hard-fails, unhandled errors, suspicious input, ...).
 *
 * Sends a single event to the AlertLoop ingest API (POST /v1/events, X-API-Key auth).
 * Delivery is synchronous and best-effort: the call waits for AlertLoop up to
 * app.alertloop.timeout seconds, and an alert with a dedupe key for its window in the rate-limit
 * store too: with Redis at most 1 s for each of two commands, plus 2 s to connect and 1 s for each
 * of AUTH, SELECT and PING when the request has not connected yet (RedisStore), so at most
 * app.alertloop.timeout + 7 s in all. Nothing is retried, no exception ever leaves this class, and a
 * failure never breaks the main request flow. It is not silent, though — a failed delivery is
 * recorded as an `alert.failed` trace event (metadata only, never the alert payload), and alerts
 * switched on without base_url or api_key leave a line in the error log. Configured by the
 * 'alertloop' block of app/Configs/app.php (app.alertloop.*).
 *
 * An alert with a dedupe key is sent once per five minutes: repeats within that window are dropped
 * locally, so an incident hit on every request does not make every request wait for AlertLoop.
 * The window opens when a send starts and is dropped if delivery fails, so the next repeat
 * tries again. It lives in the rate-limit store; when that store fails, the alert is sent.
 *
 * @see https://github.com/golovanov-dev/alertloop
 */
final class Alert
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const INFO = 'info';

    /** Seconds during which a repeat of a delivered dedupe key is not sent. */
    private const REPORTED_WINDOW = 300;

    /** Re-entry guard: reporting a failed alert must never trigger another alert. */
    private static bool $reporting = false;

    /** Alerts switched on without their keys are logged once per request, not on every send. */
    private static bool $misconfiguredLogged = false;

    /**
     * @param array<string, mixed> $context free-form data sent as the event's `payload`
     * @return bool true when AlertLoop accepted this event; false when nothing was delivered:
     *   alerting off or misconfigured, delivery failed, or the dedupe key was already reported
     *   within the window
     */
    public static function send(
        string $message,
        string $severity = self::WARNING,
        string $type = 'incident',
        ?string $dedupeKey = null,
        array $context = [],
        ?string $category = null,
    ): bool {
        if (!Config::get('app.alertloop.enabled', false)) {
            return false;
        }

        $missing = self::missingKeys();
        if ($missing !== []) {
            if (!self::$misconfiguredLogged) {
                self::$misconfiguredLogged = true;
                ErrorHandler::log('alert', 500, 'AlertLoop is enabled without ' . implode(', ', $missing) . ', alerts are not sent', __FILE__, __LINE__);
            }
            return false;
        }
        $baseUrl = rtrim((string) Config::get('app.alertloop.base_url', ''), '/');
        $apiKey = (string) Config::get('app.alertloop.api_key', '');

        // trace_id is a top-level field of the AlertLoop event schema, not payload data:
        // lift it out of $context so correlation with the trace survives ingestion.
        $traceId = isset($context['trace_id']) ? (string) $context['trace_id'] : RequestContext::traceId();
        unset($context['trace_id']);

        $payload = [
            'type' => $type,
            'severity' => $severity,
            'source' => (string) Config::get('app.alertloop.source', 'weft-site'),
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

        if ($dedupeKey === null || $dedupeKey === '') {
            return self::post($baseUrl . '/v1/events', $apiKey, $payload);
        }
        if (!RateLimiter::claim('alert:' . $dedupeKey, self::REPORTED_WINDOW)) {
            return false;
        }
        $delivered = self::post($baseUrl . '/v1/events', $apiKey, $payload);
        if (!$delivered) {
            RateLimiter::release('alert:' . $dedupeKey);
        }
        return $delivered;
    }

    /**
     * Keys the switched-on alerting lacks ('base_url', 'api_key'); empty when both are set or
     * alerting is off. Non-empty means send() sends nothing.
     *
     * @return list<string>
     */
    public static function missingKeys(): array
    {
        if (!Config::get('app.alertloop.enabled', false)) {
            return [];
        }
        $missing = [];
        foreach (['base_url', 'api_key'] as $key) {
            if ((string) Config::get('app.alertloop.' . $key, '') === '') {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function critical(string $message, array $context = [], ?string $dedupeKey = null): bool
    {
        return self::send($message, self::CRITICAL, 'incident', $dedupeKey, $context);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function post(string $url, string $apiKey, array $payload): bool
    {
        try {
            $client = new HttpClient(timeout: (int) Config::get('app.alertloop.timeout', 4), connectTimeout: 3);
            // log => false: the payload can carry user input and secrets, and the trace keeps
            // only the alert.failed metadata below.
            $response = $client->post($url, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
                'headers' => ['X-API-Key: ' . $apiKey],
                'decodeJson' => false,
                'log' => false,
            ]);
        } catch (\Throwable $e) {
            // Best-effort by contract: the caller must not see a delivery problem as an error.
            self::reportFailure($payload, 0, true, $e->getMessage());
            return false;
        }

        $status = $response['status'];
        if ($response['ok'] && $status >= 200 && $status < 300) {
            return true;
        }
        if (!$response['ok'] && $status < 400) {
            // No response, or a broken one: the transport failed, not AlertLoop.
            self::reportFailure($payload, $status, true, (string) $response['error']);
        } else {
            self::reportFailure($payload, $status, false, 'unexpected HTTP status');
        }
        return false;
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
