<?php

declare(strict_types=1);

namespace Golovanov\Weft\Observability;

use Golovanov\Traceloom\Configuration;
use Golovanov\Traceloom\Tracer;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Support\Clock;

/**
 * Request tracer — a thin static facade over golovanov/traceloom.
 *
 * Every request gets a correlation (trace) id and a single event timeline grouped by that id,
 * written as JSONL under LOGS_PATH/requests. Traceloom handles rotation, size caps and
 * automatic redaction of sensitive keys (password, token, api_key, ...).
 *
 * If the traceloom package is not installed, Trace degrades to a compatible built-in JSONL
 * writer so the engine keeps working (and produces the same shape of logs).
 */
final class Trace
{
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
        self::event('request_start', [
            'method' => $request->method,
            'path' => $request->path,
            'uri' => $request->uri,
            'client_ip' => $request->clientIp,
            'headers' => $request->headers,
            'body' => $request->rawBody,
        ]);
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
