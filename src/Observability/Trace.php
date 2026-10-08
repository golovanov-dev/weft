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
 * written as JSONL under LOGS_PATH/requests; a console script gets its own under LOGS_PATH/cli
 * (see directory()). Traceloom handles rotation, size caps, retention
 * (app.trace.retention_days) and redaction of sensitive keys (its defaults plus
 * app.trace.sensitive_keys). Weft masks nothing itself: it hands traceloom request and outgoing
 * bodies and query strings parsed into arrays, so the redaction reaches their fields — see body()
 * and splitQuery(). app.trace.log_body (default on) controls whether body contents are recorded.
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

        $dir = self::directory();
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

        [$uri, $query] = self::splitQuery($request->uri);

        self::event('request_start', [
            'method' => $request->method,
            'path' => $request->path,
            'uri' => $uri,
            ...($query !== [] ? ['query' => $query] : []),
            'client_ip' => $request->clientIp,
            'headers' => $request->headers,
            'body' => $size > 0
                ? self::body($raw, (string) $request->header('content-type', ''), $size, $request->post)
                : null,
        ]);
    }

    /**
     * What the trace records about a body: its size and content type, plus `data` with
     * app.trace.log_body on (the default). These go into `data` as an array, so traceloom masks
     * them by key: a body that decodes as a JSON object or array, whatever its content type (a
     * `+json` type, none at all, `curl -d` sending JSON as a form); otherwise an urlencoded body,
     * parsed with parse_str(); multipart fields (never file contents). Any other body goes in as
     * the raw string, which key-based masking cannot reach into.
     *
     * @internal used by httpStart() and HttpClient
     * @param array<string, mixed> $formFields fields PHP has already parsed from a multipart body
     * @param bool $parseForm false: an urlencoded body that is not JSON goes in as the raw string
     *   (HttpClient labels every non-JSON string body urlencoded, whatever it holds)
     * @return array{size: int, content_type: string, data?: array<mixed>|string}
     */
    public static function body(
        string $raw,
        string $contentType,
        ?int $size = null,
        array $formFields = [],
        bool $parseForm = true,
    ): array {
        $summary = ['size' => $size ?? strlen($raw), 'content_type' => $contentType];

        // The built-in fallback writer cannot mask by key, so it never receives body contents.
        if (!class_exists(Tracer::class) || !self::logBody()) {
            return $summary;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = match (strtolower(trim(explode(';', $contentType, 2)[0]))) {
                'application/x-www-form-urlencoded' => $parseForm ? self::parseForm($raw) : null,
                'multipart/form-data' => $formFields,
                default => null,
            };
        }
        $summary['data'] = is_array($data) ? self::utf8Keys($data) : $raw;

        return $summary;
    }

    /**
     * A URL split into the part before `?` and its query string parsed into an array (parse_str),
     * so traceloom masks query parameters by key. A `#fragment` is dropped.
     *
     * @internal used by httpStart() and HttpClient
     * @return array{0: string, 1: array<mixed>}
     */
    public static function splitQuery(string $url): array
    {
        $url = explode('#', $url, 2)[0];
        $pos = strpos($url, '?');
        if ($pos === false) {
            return [$url, []];
        }
        parse_str(substr($url, $pos + 1), $query);
        return [substr($url, 0, $pos), self::utf8Keys($query)];
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

    /**
     * Where a process of the given SAPI writes its traces: LOGS_PATH/cli for console scripts
     * (`cli`, `phpdbg`), LOGS_PATH/requests for the web, PHP's built-in server included. A web
     * request and a script never share a trace file, and each directory has one writer.
     *
     * @internal $sapi is for ErrorHandler::logsError(), which checks the web's directory from a
     *   console script, and for tests.
     */
    public static function directory(string $sapi = PHP_SAPI): string
    {
        $base = defined('LOGS_PATH') ? (string) LOGS_PATH : sys_get_temp_dir() . '/weft-logs';
        return rtrim($base, '/\\') . (in_array($sapi, ['cli', 'phpdbg'], true) ? '/cli' : '/requests');
    }

    /**
     * Fields of an urlencoded body. Any text parses: a pair without `=` becomes a field with an
     * empty value, a name PHP does not expect keeps its value, so `password` in an imperfect form is
     * still a field traceloom masks.
     *
     * @return array<mixed>
     */
    private static function parseForm(string $raw): array
    {
        parse_str($raw, $fields);
        return $fields;
    }

    /**
     * The array with every key made valid UTF-8, at any depth. traceloom cannot encode a key that
     * is not (`?%E8%EC%FF=1`, a cp1251 form field) and then drops the whole event's data — method,
     * path, headers and all. Invalid bytes become U+FFFD; traceloom compares keys on [a-z0-9] only,
     * so the masking decision for the key does not change. A renamed key that lands on an existing
     * one gets `~` appended until it is free, so no value is lost. Values stay as they are:
     * traceloom records a string that is not UTF-8 as `_binary`.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function utf8Keys(array $data): array
    {
        $out = [];
        $renamed = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::utf8Keys($value);
            }
            if (is_string($key) && preg_match('//u', $key) !== 1) {
                $renamed[] = [$key, $value];
                continue;
            }
            $out[$key] = $value;
        }
        foreach ($renamed as [$key, $value]) {
            $key = (string) json_decode((string) json_encode($key, JSON_INVALID_UTF8_SUBSTITUTE));
            while (array_key_exists($key, $out)) {
                $key .= '~';
            }
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * On unless configured off. A boolean or a boolean-like string ('true', '1', 'on', 'yes');
     * 'false' and any other value mean off.
     */
    private static function logBody(): bool
    {
        $value = Config::get('app.trace.log_body', true);
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
        $dir = self::directory();
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
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
