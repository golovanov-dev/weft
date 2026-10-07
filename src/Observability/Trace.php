<?php

declare(strict_types=1);

namespace Golovanov\Weft\Observability;

use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Tracer;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Support\Clock;

/**
 * Request tracer — a thin static facade over golovanov/traceloom.
 *
 * Every request gets a correlation (trace) id and a single event timeline grouped by that id,
 * written as JSONL under LOGS_PATH/requests. Traceloom handles rotation, size caps, retention
 * (app.trace.retention_days) and redaction of sensitive keys (its defaults plus
 * app.trace.sensitive_keys). Request and outgoing bodies are recorded as size and content type;
 * their parsed fields only with app.trace.log_body on — see body().
 *
 * If the traceloom package is not installed, Trace degrades to a compatible built-in JSONL
 * writer so the engine keeps working (and produces the same shape of logs).
 */
final class Trace
{
    private const DEFAULT_RETENTION_DAYS = 30;

    private static ?Tracer $tracer = null;
    private static mixed $trace = null;
    private static string $id = '';
    private static bool $booted = false;

    public static function boot(?Request $request = null): string
    {
        if (self::$booted) {
            return self::$id;
        }
        self::$booted = true;

        $dir = self::logDir();
        $incoming = $request?->header('x-trace-id') ?? $request?->header('x-request-id');
        $trustIncoming = (bool) (defined('TRACE_TRUST_INCOMING') ? TRACE_TRUST_INCOMING : false);

        if (class_exists(Tracer::class)) {
            $config = Configuration::create(
                logDirectory: $dir,
                sensitiveKeys: self::sensitiveKeys(),
                retentionDays: self::retentionDays(),
                trustIncomingTraceId: $trustIncoming,
            );
            self::$tracer = Tracer::fromConfiguration($config);
            self::$trace = self::$tracer->start($trustIncoming ? $incoming : null);
            self::$id = self::$trace->id();
        } else {
            self::$id = ($trustIncoming && self::isValidId($incoming)) ? (string) $incoming : bin2hex(random_bytes(16));
        }

        RequestContext::setTraceId(self::$id);
        return self::$id;
    }

    /**
     * Whether a trace is already running for this request.
     *
     * Lets callers on an error path (see Alert::reportFailure()) record an event only when a
     * timeline exists, instead of booting the tracer from inside failure handling.
     */
    public static function isActive(): bool
    {
        return self::$booted;
    }

    public static function id(): string
    {
        if (!self::$booted) {
            self::boot(RequestContext::request());
        }
        return self::$id;
    }

    /**
     * Record an event on the current trace timeline.
     *
     * @param array<string, mixed> $data
     */
    public static function event(string $name, array $data = []): void
    {
        if (!self::$booted) {
            self::boot(RequestContext::request());
        }

        if (self::$trace !== null) {
            self::$trace->event($name, $data);
            return;
        }

        self::writeFallback($name, $data);
    }

    public static function httpStart(Request $request): void
    {
        $raw = (string) $request->rawBody;
        // A multipart body is not readable from php://input; its size comes from the header.
        $size = $raw !== '' ? strlen($raw) : (int) $request->header('content-length', '0');

        self::event('request_start', [
            'method' => $request->method,
            'path' => $request->path,
            'uri' => $request->uri,
            'client_ip' => $request->clientIp,
            'headers' => $request->headers,
            'body' => $size > 0
                ? self::body($raw, (string) $request->header('content-type', ''), $size, $request->post)
                : null,
        ]);
    }

    /**
     * What the trace records about a body: its size and content type. With app.trace.log_body on,
     * a JSON, urlencoded or multipart body is added as `data`, parsed into an array so traceloom
     * masks it by key. The raw body string is never recorded.
     *
     * @internal used by httpStart() and HttpClient
     * @param array<string, mixed> $formFields fields PHP has already parsed from a multipart body
     * @return array{size: int, content_type: string, data?: array<mixed>}
     */
    public static function body(string $raw, string $contentType, ?int $size = null, array $formFields = []): array
    {
        $summary = ['size' => $size ?? strlen($raw), 'content_type' => $contentType];

        // The built-in fallback writer cannot mask by key, so it never receives body contents.
        if (!class_exists(Tracer::class) || !self::logBody()) {
            return $summary;
        }

        $data = match (strtolower(trim(explode(';', $contentType, 2)[0]))) {
            'application/json' => json_decode($raw, true),
            'application/x-www-form-urlencoded' => self::parseForm($raw),
            'multipart/form-data' => $formFields,
            default => null,
        };
        if (is_array($data)) {
            $summary['data'] = $data;
        }

        return $summary;
    }

    public static function httpEnd(int $status, float $elapsedSeconds): void
    {
        self::event('request_end', [
            'status' => $status,
            'elapsed_ms' => round($elapsedSeconds * 1000, 2),
        ]);
    }

    public static function flush(): void
    {
        self::$tracer?->flush();
    }

    private static function logDir(): string
    {
        $base = defined('LOGS_PATH') ? (string) LOGS_PATH : sys_get_temp_dir() . '/weft-logs';
        return rtrim($base, '/\\') . '/requests';
    }

    /**
     * Fields of an urlencoded body, or null when the body is not a form. parse_str() turns any text
     * into keys, and traceloom masks values, not keys: JSON or prose sent with this content type
     * would be logged as one long key. So every `&`-separated pair must be `name=value`, and every
     * decoded name a form field name: a letter or `_`, then letters, digits, `_`, `.`, `-`, and
     * optional `[...]` parts (`client[phone]`).
     *
     * @return array<mixed>|null
     */
    private static function parseForm(string $raw): ?array
    {
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strstr($pair, '=', true);
            if ($name === false || preg_match('/^[A-Za-z_][\w.\-]*(\[[\w.\-]*\])*$/', urldecode($name)) !== 1) {
                return null;
            }
        }
        parse_str($raw, $fields);
        return $fields;
    }

    /** A boolean or a boolean-like string ('true', '1', 'on', 'yes'); 'false' and anything else mean off. */
    private static function logBody(): bool
    {
        $value = Config::get('app.trace.log_body', false);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /** @return list<string> extra keys to redact, on top of traceloom's defaults; a single string is one key */
    private static function sensitiveKeys(): array
    {
        $keys = Config::get('app.trace.sensitive_keys', []);
        if (is_string($keys)) {
            return [$keys];
        }
        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /** Days of trace files to keep; 0 keeps them forever. Invalid values fall back to the default. */
    private static function retentionDays(): int
    {
        $days = Config::get('app.trace.retention_days', self::DEFAULT_RETENTION_DAYS);
        return is_numeric($days) && (int) $days >= 0 ? (int) $days : self::DEFAULT_RETENTION_DAYS;
    }

    private static function isValidId(?string $id): bool
    {
        return is_string($id) && preg_match('/^[a-f0-9]{16,64}$/i', $id) === 1;
    }

    /** Built-in JSONL writer used only when traceloom is absent. */
    private static function writeFallback(string $name, array $data): void
    {
        $dir = self::logDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $record = [
            'time' => Clock::timestamp(),
            'timezone' => Clock::offset(),
            'id' => self::$id,
            'type' => $name,
            'data' => $data,
        ];
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false) {
            @error_log($line . "\n", 3, $dir . '/' . Clock::date('Y-m-d') . '.jsonl');
        }
    }
}
