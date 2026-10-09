<?php

declare(strict_types=1);

namespace Golovanov\Weft\Http;

/**
 * Per-request context shared across the engine without threading it through every call:
 * the current Request, the correlation/trace id and when the request started. Populated once by
 * the Kernel.
 */
final class RequestContext
{
    private static ?Request $request = null;
    private static string $traceId = '';
    private static float $startedAt = 0.0;

    public static function setRequest(Request $request): void
    {
        self::$request = $request;
        if (self::$startedAt === 0.0) {
            self::$startedAt = microtime(true);
        }
    }

    public static function request(): ?Request
    {
        return self::$request;
    }

    public static function setTraceId(string $traceId): void
    {
        self::$traceId = $traceId;
    }

    public static function traceId(): string
    {
        return self::$traceId;
    }

    public static function startedAt(): float
    {
        return self::$startedAt > 0.0 ? self::$startedAt : microtime(true);
    }

    public static function elapsed(): float
    {
        return microtime(true) - self::startedAt();
    }
}
