<?php

declare(strict_types=1);

namespace Golovanov\Weft\Observability;

use Golovanov\Traceloom\Writer\JsonlFileWriter;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Support\Clock;

/**
 * Central error/exception handler.
 *
 * Writes human-readable lines to LOGS_PATH/errors/YYYY-mm-dd.log and, for exceptions and
 * fatal errors, raises an AlertLoop notification (sensitive spot). Registered once in bootstrap;
 * the Kernel reports exceptions thrown by pages and endpoints through report().
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
        self::report($e, 'exception');
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
     * End a failed CLI run with a non-zero status.
     *
     * Registering an exception handler changes how PHP dies: once the handler returns, the script
     * terminates with status 0. A CLI script killed by an uncaught throwable therefore reports
     * success to cron, CI and deploy steps, and with display_errors off in production it prints
     * nothing at all — the failure exists only in the error log nobody was told to read. The web
     * path is untouched: there the Kernel renders the response and sets the status code.
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
     * What PHP cannot write among the logs, for health checks: the error log directory and today's
     * file in it, and the web's traces (LOGS_PATH/requests, whatever SAPI calls this), as traceloom
     * itself checks them; null when everything is writable. A missing directory counts as writable
     * when PHP can create it. Only checks: creates nothing and changes nothing.
     */
    public static function logsError(): ?string
    {
        $problems = [];
        $errors = self::unwritable(self::directory(), [Clock::date('Y-m-d') . '.log']);
        if ($errors !== []) {
            $problems[] = 'PHP cannot write ' . implode(', ', $errors);
        }
        $traces = JsonlFileWriter::writeProblem(Trace::configuration('fpm-fcgi'));
        if ($traces !== null) {
            $problems[] = $traces;
        }
        return $problems !== [] ? implode('; ', $problems) : null;
    }

    /**
     * The directory, or those of the named files in it that exist, PHP cannot write. A directory
     * that does not exist yet is writable when its nearest existing parent is.
     *
     * @internal Also used by RateLimiter::storeStatus().
     * @param list<string> $files
     * @return list<string>
     */
    public static function unwritable(string $dir, array $files): array
    {
        if (!file_exists($dir)) {
            $parent = dirname($dir);
            while (!file_exists($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }
            return is_dir($parent) && is_writable($parent) ? [] : [$dir];
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return [$dir];
        }
        $paths = array_map(fn (string $file) => $dir . '/' . $file, $files);
        return array_values(array_filter($paths, fn (string $path) => is_file($path) && !is_writable($path)));
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
