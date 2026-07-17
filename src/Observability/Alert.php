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
 * Delivery is best-effort and non-blocking: any failure is swallowed so alerting never
 * breaks the main request flow. Enabled via config('alertloop.enabled').
 *
 * @see https://github.com/golovanov-dev/alertloop
 */
final class Alert
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const INFO = 'info';

    /**
     * @param array<string, mixed> $context extra fields merged into the message payload
     */
    public static function send(
        string $message,
        string $severity = self::WARNING,
        string $type = 'incident',
        ?string $dedupeKey = null,
        array $context = [],
    ): bool {
        if (!Config::get('alertloop.enabled', false)) {
            return false;
        }

        $baseUrl = rtrim((string) Config::get('alertloop.base_url', ''), '/');
        $apiKey = (string) Config::get('alertloop.api_key', '');
        if ($baseUrl === '' || $apiKey === '') {
            return false;
        }

        $payload = [
            'type' => $type,
            'severity' => $severity,
            'source' => (string) Config::get('alertloop.source', 'weft-site'),
            'message' => $message,
        ];
        if ($dedupeKey !== null) {
            $payload['dedupe_key'] = $dedupeKey;
        }
        if ($context !== []) {
            $payload['context'] = $context;
        }

        return self::post($baseUrl . '/v1/events', $apiKey, $payload);
    }

    public static function critical(string $message, array $context = [], ?string $dedupeKey = null): bool
    {
        return self::send($message, self::CRITICAL, 'incident', $dedupeKey, $context);
    }

    /**
     * Convenience: register this as the Database error notifier in bootstrap.
     */
    public static function notifier(): callable
    {
        return static fn(string $message): bool => self::critical($message, ['trace_id' => RequestContext::traceId()]);
    }

    private static function post(string $url, string $apiKey, array $payload): bool
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return false;
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
        curl_close($ch);

        return $response !== false && $status >= 200 && $status < 300;
    }
}
