<?php

declare(strict_types=1);

namespace Golovanov\Weft\Observability;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Http\ServiceUnavailable;
use Golovanov\Weft\Support\Clock;
use Golovanov\Weft\Support\Sanitize;

/**
 * Central error/exception handler.
 *
 * Writes human-readable lines to LOGS_PATH/errors/YYYY-mm-dd.log and, for exceptions and
 * fatal errors, raises an alert (sensitive spot). Registered once in bootstrap;
 * the Kernel reports exceptions thrown by pages and endpoints through report(). An exception
 * nobody caught outside the Kernel (bootstrap, App\Bootstrap::init()) gets a built-in 500 page on
 * the web, or a 503 with Retry-After for a ServiceUnavailable. One thrown after the Kernel answered
 * (a shutdown function, a destructor) is logged and alerted, and the answer stays as it was.
 */
final class ErrorHandler
{
    private static bool $registered = false;

    /** Whether the Kernel has sent its response; respondIfWeb() then leaves it alone. */
    private static bool $responded = false;

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
        self::report($e, 'exception');
        self::respondIfWeb($e);
        self::exitIfCli($e);
    }

    /**
     * Log a throwable nobody handled and raise a critical alert for it; $type only labels the log
     * line.
     */
    public static function report(\Throwable $e, string $type): void
    {
        self::logException($e, $type);
        self::alertException($e);
    }

    /**
     * The log line of report(). The Kernel writes it before the 500 response, the alert after.
     *
     * @internal Used by the Kernel.
     */
    public static function logException(\Throwable $e, string $type): void
    {
        self::log($type, (int) $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine());
    }

    /**
     * The alert of report(): the exception handler and the Kernel (for a page or endpoint that
     * threw) share it, so an error gets the same alert message and dedupe key wherever it is caught.
     *
     * @internal Used by the Kernel.
     */
    public static function alertException(\Throwable $e): void
    {
        self::alert('Unhandled exception: ' . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace_id' => RequestContext::traceId(),
        ], 'exception:' . md5($e->getFile() . $e->getLine()));
    }

    /**
     * Mark the request as answered. The Kernel calls it before sending its response: with
     * output_buffering on, that response is still in PHP's buffer and headers_sent() is false, so
     * a later exception would otherwise replace it with the 500 page.
     *
     * @internal Used by the Kernel.
     */
    public static function responseSent(): void
    {
        self::$responded = true;
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
     * Answer a web request whose exception escaped outside the Kernel, in the site's bootstrap.
     *
     * With an exception handler registered, PHP ends such a request with 200 and an empty page,
     * which monitoring and search engines take for a working one. pages/500.php is not rendered:
     * the site did not start. Headers already sent, or a response the Kernel already gave, leave
     * the status as it is.
     */
    private static function respondIfWeb(\Throwable $e): void
    {
        if (PHP_SAPI === 'cli' || self::$responded || headers_sent()) {
            return;
        }

        $unavailable = $e instanceof ServiceUnavailable;
        $status = $unavailable ? 503 : 500;
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        if ($unavailable) {
            header('Retry-After: ' . $e->retryAfter);
        }

        $message = self::debug() ? '<pre>' . Sanitize::output((string) $e) . '</pre>' : '<p>Something went wrong.</p>';
        echo '<!doctype html><meta charset="utf-8"><title>Error</title><h1>' . $status . '</h1>' . $message;
    }

    /**
     * End a failed CLI run with a non-zero status.
     *
     * Registering an exception handler changes how PHP dies: once the handler returns, the script
     * terminates with status 0. A CLI script killed by an uncaught throwable therefore reports
     * success to cron, CI and deploy steps, and with display_errors off in production it prints
     * nothing at all — the failure exists only in the error log nobody was told to read.
     */
    private static function exitIfCli(\Throwable $e): void
    {
        if (PHP_SAPI !== 'cli' || !defined('STDERR')) {
            return;
        }

        fwrite(STDERR, sprintf(
            "Uncaught %s: %s in %s:%d\n",
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        ));

        exit(1);
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

    /** Append a line to today's error log. */
    public static function log(string $type, int $code, string $message, string $file, int $line): void
    {
        $dir = self::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
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

    /**
     * What PHP cannot write among the logs, for health checks: the error log directory and the web's
     * traces (LOGS_PATH/requests); null when both are writable. Only checks: creates nothing.
     */
    public static function logsError(): ?string
    {
        $unwritable = array_filter([self::directory(), Config::logsPath() . '/requests'], static fn(string $dir): bool => !self::writable($dir));
        return $unwritable !== [] ? 'PHP cannot write ' . implode(', ', $unwritable) : null;
    }

    /**
     * Whether PHP can write in $dir, or create it when it does not exist yet (its nearest existing
     * parent is a writable directory).
     *
     * @internal Also used by RateLimiter::storeStatus().
     */
    public static function writable(string $dir): bool
    {
        while (!file_exists($dir) && dirname($dir) !== $dir) {
            $dir = dirname($dir);
        }
        return is_dir($dir) && is_writable($dir);
    }

    private static function directory(): string
    {
        return Config::logsPath() . '/errors';
    }

    public static function debug(): bool
    {
        return (bool) Config::env('DEBUG', false);
    }
}
