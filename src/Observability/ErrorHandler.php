<?php

declare(strict_types=1);

namespace Golovanov\Weft\Observability;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Support\Clock;

/**
 * Central error/exception handler.
 *
 * Writes human-readable lines to LOGS_PATH/errors/YYYY-mm-dd.log and, for exceptions and
 * fatal errors, raises an AlertLoop notification (sensitive spot). Registered once in bootstrap.
 */
final class ErrorHandler
{
    private static bool $registered = false;

    public static function run(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError(int $code, string $message, string $file = '', int $line = 0): bool
    {
        // Respect the current error_reporting level (e.g. suppressed with @).
        if ((error_reporting() & $code) === 0) {
            return false;
        }
        self::log('error', $code, $message, $file, $line);
        return false; // let PHP's normal handler run too
    }

    public static function handleException(\Throwable $e): void
    {
        self::log('exception', (int) $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine());
        self::alert('Unhandled exception: ' . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace_id' => RequestContext::traceId(),
        ], 'exception:' . md5($e->getFile() . $e->getLine()));
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            self::log('fatal', $error['type'], $error['message'], $error['file'], $error['line']);
            self::alert('Fatal error: ' . $error['message'], [
                'file' => $error['file'] . ':' . $error['line'],
                'trace_id' => RequestContext::traceId(),
            ], 'fatal:' . md5($error['file'] . $error['line']));
        }
    }

    /**
     * Raise an alert from inside error handling.
     *
     * Alert is best-effort and swallows delivery problems itself; this is the second belt:
     * nothing thrown here may escape the exception/shutdown handler, or the failure of an
     * alert would be re-entered as a new error to handle.
     *
     * @param array<string, mixed> $context
     */
    private static function alert(string $message, array $context, string $dedupeKey): void
    {
        try {
            Alert::critical($message, $context, $dedupeKey);
        } catch (\Throwable) {
            // Nothing to do: the error itself is already in the log above.
        }
    }

    public static function log(string $type, int $code, string $message, string $file, int $line): void
    {
        $base = defined('LOGS_PATH') ? (string) LOGS_PATH : sys_get_temp_dir() . '/weft-logs';
        $dir = rtrim($base, '/\\') . '/errors';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }

        $line = sprintf(
            "[%s] %s ERRNO:%d | %s | %s:%d | trace:%s\n",
            Clock::timestamp(),
            strtoupper($type),
            $code,
            $message,
            $file,
            $line,
            RequestContext::traceId(),
        );

        @file_put_contents($dir . '/' . Clock::date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function debug(): bool
    {
        return (bool) Config::env('DEBUG', false);
    }
}
