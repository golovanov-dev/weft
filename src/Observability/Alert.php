<?php

declare(strict_types=1);

namespace Golovanov\Weft\Observability;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Mail\Mail;
use Golovanov\Weft\Security\RateLimiter;

/**
 * Alerts for sensitive spots (payment failures, captcha hard-fails, unhandled errors, suspicious
 * input, ...), sent to the channels listed in app.alerts.channels, in that order:
 *
 * - `alertloop` — an event to the AlertLoop ingest API (POST /v1/events), settings app.alertloop.*;
 * - `telegram` — a message from a bot, settings app.alerts.telegram.*;
 * - `mail` — an email to app.alerts.mail_to through Mail (app.mail.*);
 * - `log` — a line in the error log; it reaches nobody, so it never counts as delivered.
 *
 * No channels, no alerts. A listed channel without its settings is skipped and the error log says
 * so once per request; `weft-health` shows it too.
 *
 * Delivery is synchronous and best-effort; nothing is retried, no exception ever leaves this class,
 * and a failure never breaks the main request flow. Channels are tried one after another, each up to
 * its own timeout; an alert with a dedupe key also waits for the rate-limit store that keeps its
 * window. A failed delivery is recorded as an `alert.failed` trace event (metadata only, never the
 * alert payload or a secret).
 *
 * An alert with a dedupe key is sent once per five minutes: repeats within that window are dropped
 * locally, whether the first send was delivered or not, so an incident hit on every request does
 * not make every request wait for the channels. The window lives in the rate-limit store; when that
 * store fails, the alert is sent.
 *
 * @see https://github.com/golovanov-dev/alertloop
 */
final class Alert
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const INFO = 'info';

    /** Seconds during which a repeat of a dedupe key is not sent. */
    private const REPORTED_WINDOW = 300;

    /** Seconds a send waits for Telegram. */
    private const TELEGRAM_TIMEOUT = 4;

    /** Re-entry guard: reporting a failed alert must never trigger another alert. */
    private static bool $reporting = false;

    /** Channels without their settings are logged once per request, not on every send. */
    private static bool $misconfiguredLogged = false;

    /**
     * @param array<string, mixed> $context free-form data sent as the event's `payload`
     * @return bool true when a channel that reaches a person (alertloop, telegram, mail) took the
     *   alert; false when none did: no such channel is set up, all of them failed, or the dedupe key
     *   was already reported within the window
     */
    public static function send(
        string $message,
        string $severity = self::WARNING,
        string $type = 'incident',
        ?string $dedupeKey = null,
        array $context = [],
        ?string $category = null,
    ): bool {
        $channels = self::readyChannels();
        $errors = self::configErrors();
        if ($errors !== [] && !self::$misconfiguredLogged) {
            self::$misconfiguredLogged = true;
            ErrorHandler::log('alert', 500, 'Alert channels skipped: ' . implode('; ', $errors), __FILE__, __LINE__);
        }
        if ($channels === []) {
            return false;
        }

        // trace_id is a top-level field of the AlertLoop event schema, not payload data:
        // lift it out of $context so correlation with the trace survives ingestion.
        $traceId = isset($context['trace_id']) ? (string) $context['trace_id'] : RequestContext::traceId();
        unset($context['trace_id']);

        $event = [
            'type' => $type,
            'severity' => $severity,
            'source' => (string) Config::get('app.alertloop.source', 'weft-site'),
            'message' => $message,
        ];
        if ($category !== null && $category !== '') {
            $event['category'] = $category;
        }
        if ($traceId !== '') {
            $event['trace_id'] = $traceId;
        }
        if ($dedupeKey !== null) {
            $event['dedupe_key'] = $dedupeKey;
        }
        if ($context !== []) {
            $event['payload'] = $context;
        }

        if ($dedupeKey !== null && $dedupeKey !== '' && !RateLimiter::claim('alert:' . $dedupeKey, self::REPORTED_WINDOW)) {
            return false;
        }
        $delivered = false;
        foreach ($channels as $channel) {
            if (self::deliver($channel, $event) && $channel !== 'log') {
                $delivered = true;
            }
        }
        return $delivered;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function critical(string $message, array $context = [], ?string $dedupeKey = null): bool
    {
        return self::send($message, self::CRITICAL, 'incident', $dedupeKey, $context);
    }

    /**
     * The channels listed in app.alerts.channels, in order.
     *
     * @return list<string>
     */
    public static function channels(): array
    {
        return array_values(array_unique(array_map(strval(...), (array) Config::get('app.alerts.channels', []))));
    }

    /**
     * What the listed channels lack; empty when every one of them can send.
     *
     * @return list<string>
     */
    public static function configErrors(): array
    {
        $errors = [];
        foreach (self::channels() as $channel) {
            array_push($errors, ...self::channelErrors($channel));
        }
        return $errors;
    }

    /** @return list<string> */
    private static function readyChannels(): array
    {
        return array_values(array_filter(self::channels(), static fn(string $c): bool => self::channelErrors($c) === []));
    }

    /** @return list<string> */
    private static function channelErrors(string $channel): array
    {
        $empty = static fn(string $key): bool => trim((string) Config::get($key, '')) === '';

        switch ($channel) {
            case 'alertloop':
                return array_values(array_map(
                    static fn(string $key): string => $key . ' is empty',
                    array_filter(['app.alertloop.base_url', 'app.alertloop.api_key'], $empty),
                ));
            case 'telegram':
                return array_values(array_map(
                    static fn(string $key): string => $key . ' is empty',
                    array_filter(['app.alerts.telegram.bot_token', 'app.alerts.telegram.chat_id'], $empty),
                ));
            case 'mail':
                $to = self::mailTo();
                $errors = $to === [] ? ['app.alerts.mail_to is empty'] : [];
                foreach ($to as $address) {
                    if (!Mail::isAddress($address)) {
                        $errors[] = 'app.alerts.mail_to is not an email address';
                        break;
                    }
                }
                return [...$errors, ...Mail::configErrors()];
            case 'log':
                return [];
            default:
                return ['app.alerts.channels has an unknown channel "' . mb_substr($channel, 0, 40) . '"'];
        }
    }

    /** @return list<string> */
    private static function mailTo(): array
    {
        return array_values(array_filter(
            array_map(static fn(mixed $a): string => trim((string) $a), (array) Config::get('app.alerts.mail_to', [])),
            static fn(string $a): bool => $a !== '',
        ));
    }

    /**
     * One channel; true when it took the alert. Never throws: a failure is recorded instead.
     *
     * @param array<string, mixed> $event
     */
    private static function deliver(string $channel, array $event): bool
    {
        try {
            $failure = match ($channel) {
                'alertloop' => self::toAlertLoop($event),
                'telegram' => self::toTelegram($event),
                'mail' => self::toMail($event),
                default => self::toLog($event),
            };
        } catch (\Throwable $e) {
            // Best-effort by contract: the caller must not see a delivery problem as an error.
            $failure = [0, true, $e->getMessage()];
        }
        if ($failure === null) {
            return true;
        }
        self::reportFailure($channel, $event, ...$failure);
        return false;
    }

    /**
     * @param array<string, mixed> $event
     * @return null|array{int, bool, string} null when accepted; otherwise status, transport error, error
     */
    private static function toAlertLoop(array $event): ?array
    {
        $url = rtrim((string) Config::get('app.alertloop.base_url', ''), '/') . '/v1/events';
        $client = new HttpClient(timeout: (int) Config::get('app.alertloop.timeout', 4), connectTimeout: 3);
        // log => false: the payload can carry user input and secrets, and the trace keeps
        // only the alert.failed metadata.
        $response = $client->post($url, (string) json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
            'headers' => ['X-API-Key: ' . (string) Config::get('app.alertloop.api_key', '')],
            'decodeJson' => false,
            'log' => false,
        ]);
        $status = $response['status'];
        if ($response['ok'] && $status >= 200 && $status < 300) {
            return null;
        }
        return self::httpFailure($response, 'unexpected HTTP status');
    }

    /**
     * @param array<string, mixed> $event
     * @return null|array{int, bool, string}
     */
    private static function toTelegram(array $event): ?array
    {
        $token = trim((string) Config::get('app.alerts.telegram.bot_token', ''));
        $client = new HttpClient(timeout: self::TELEGRAM_TIMEOUT, connectTimeout: 3);
        // log => false: the bot token is part of the URL.
        $response = $client->post('https://api.telegram.org/bot' . $token . '/sendMessage', [
            'chat_id' => trim((string) Config::get('app.alerts.telegram.chat_id', '')),
            'text' => mb_substr(self::text($event), 0, 4000),
            'link_preview_options' => ['is_disabled' => true],
        ], ['log' => false]);
        $body = is_array($response['body']) ? $response['body'] : [];
        if ($response['ok'] && ($body['ok'] ?? false) === true) {
            return null;
        }
        return self::httpFailure($response, (string) ($body['description'] ?? 'Telegram did not confirm the message'));
    }

    /**
     * @param array<string, mixed> $event
     * @return null|array{int, bool, string}
     */
    private static function toMail(array $event): ?array
    {
        $subject = '[' . $event['severity'] . '] ' . mb_substr((string) $event['message'], 0, 150);
        return Mail::send(self::mailTo(), $subject, self::text($event))
            ? null
            : [0, true, 'Mail::send() failed, the reason is in the error log'];
    }

    /**
     * @param array<string, mixed> $event
     * @return null
     */
    private static function toLog(array $event): ?array
    {
        $kind = $event['type'] . (isset($event['category']) ? '/' . $event['category'] : '');
        $line = strtoupper((string) $event['severity']) . ' ' . $kind . ': ' . $event['message']
            . (isset($event['dedupe_key']) ? ' [' . $event['dedupe_key'] . ']' : '');
        ErrorHandler::log('alert', 0, (string) preg_replace('/[\r\n]+/', ' ', $line), __FILE__, __LINE__);
        return null;
    }

    /**
     * @param array{ok: bool, status: int, error: string|null} $response
     * @return array{int, bool, string}
     */
    private static function httpFailure(array $response, string $refused): array
    {
        $status = $response['status'];
        if (!$response['ok'] && $status < 400) {
            // No response, or a broken one: the transport failed, not the service.
            return [$status, true, (string) $response['error']];
        }
        return [$status, false, $refused];
    }

    /**
     * The alert as text for a person: the message, its routing fields and the payload.
     *
     * @param array<string, mixed> $event
     */
    private static function text(array $event): string
    {
        $lines = [strtoupper((string) $event['severity']) . ': ' . $event['message']];
        $site = (string) parse_url((string) Config::get('app.url.base', ''), PHP_URL_HOST);
        $fields = [
            'type' => $event['type'],
            'category' => $event['category'] ?? '',
            'site' => $site,
            'trace' => $event['trace_id'] ?? '',
            'dedupe key' => $event['dedupe_key'] ?? '',
        ];
        foreach ($fields as $name => $value) {
            if ((string) $value !== '') {
                $lines[] = $name . ': ' . $value;
            }
        }
        if (isset($event['payload'])) {
            $lines[] = '';
            $lines[] = (string) json_encode($event['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        return implode("\n", $lines);
    }

    /**
     * Record an undelivered alert on the trace timeline.
     *
     * Metadata only: the alert payload can carry user input and secrets, so nothing from it
     * is written except the routing fields, and the Telegram bot token is cut out of the error.
     * Reporting is itself best-effort — if tracing is unavailable the failure falls back to
     * error_log(), and any throw is swallowed, because this runs on the error path (ErrorHandler)
     * where a second failure has nowhere to go.
     *
     * @param array<string, mixed> $event
     */
    private static function reportFailure(string $channel, array $event, int $status, bool $transportError, string $error): void
    {
        if (self::$reporting) {
            return;
        }
        self::$reporting = true;

        try {
            $token = trim((string) Config::get('app.alerts.telegram.bot_token', ''));
            if ($token !== '') {
                $error = str_replace($token, '***', $error);
            }
            $data = [
                'channel' => $channel,
                'event_type' => (string) ($event['type'] ?? ''),
                'severity' => (string) ($event['severity'] ?? ''),
                'category' => (string) ($event['category'] ?? ''),
                'dedupe_key' => (string) ($event['dedupe_key'] ?? ''),
                'status' => $status,
                'transport_error' => $transportError,
                'error' => mb_substr($error, 0, 200),
            ];
            if (Trace::isActive()) {
                Trace::event('alert.failed', $data);
            } else {
                // No trace to attach to (CLI, or a failure before the Kernel booted one):
                // do not start one from an error path, just leave a line in the PHP log.
                $line = json_encode(['alert.failed' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
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
