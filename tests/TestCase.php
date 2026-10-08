<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests;

use Golovanov\Weft\Assets\Vite;
use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\I18n\I18n;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Security\RateLimiter;
use Golovanov\Weft\Seo\Seo;
use Golovanov\Weft\Support\Clock;
use Golovanov\Weft\View\View;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case: the engine keeps per-request state in static properties (populated once by
 * the Kernel in production). Tests reset that state around every run so cases stay isolated.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::resetEngineState();
    }

    protected function tearDown(): void
    {
        self::resetEngineState();
        parent::tearDown();
    }

    protected static function resetEngineState(): void
    {
        self::setStatics(Config::class, ['items' => [], 'loaded' => false]);
        self::setStatics(Seo::class, ['data' => [], 'jsonLd' => [], 'booted' => false]);
        self::setStatics(Locale::class, ['current' => null]);
        self::setStatics(Url::class, ['route' => null]);
        self::setStatics(I18n::class, ['dict' => [], 'locale' => 'en']);
        self::setStatics(Cache::class, ['store' => null, 'failureLogged' => false]);
        self::setStatics(RateLimiter::class, ['store' => null, 'redisError' => null]);
        self::setStatics(View::class, ['shared' => []]);
        self::setStatics(Vite::class, ['manifest' => null]);
        self::setStatics(Alert::class, ['transport' => null, 'reporting' => false, 'misconfiguredLogged' => false]);
        self::setStatics(Trace::class, ['tracer' => null, 'trace' => null, 'id' => '', 'booted' => false]);
        self::setStatics(RequestContext::class, [
            'request' => null,
            'traceId' => '',
            'startedAt' => 0.0,
            'logPath' => 'requests',
        ]);

        // A page may set its own status (http_response_code(404)) and Seo::render() reads it;
        // keep one test's status from leaking into the next through this process-wide state.
        http_response_code(200);

        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['REMOTE_ADDR']);
    }

    /** @param array<string, mixed> $values */
    private static function setStatics(string $class, array $values): void
    {
        $ref = new \ReflectionClass($class);
        foreach ($values as $name => $value) {
            $ref->getProperty($name)->setValue(null, $value);
        }
    }

    /**
     * Events recorded on the current trace, read back from the JSONL timeline.
     *
     * @return list<array<string, mixed>>
     */
    protected function traceEvents(string $traceId): array
    {
        Trace::flush();
        $dir = sys_get_temp_dir() . '/weft-logs/requests';
        $events = [];
        foreach (glob($dir . '/*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = json_decode($line, true);
                if (is_array($record) && ($record['trace_id'] ?? $record['id'] ?? null) === $traceId) {
                    $events[] = $record;
                }
            }
        }
        return $events;
    }

    /** Size of today's error log (ErrorHandler::log() without LOGS_PATH), to read what a test adds. */
    protected static function errorLogSize(): int
    {
        clearstatcache();
        return is_file(self::errorLogFile()) ? (int) filesize(self::errorLogFile()) : 0;
    }

    /** What the error log gained after the given byte offset. */
    protected static function errorLogSince(int $offset): string
    {
        clearstatcache();
        return is_file(self::errorLogFile()) ? (string) file_get_contents(self::errorLogFile(), false, null, $offset) : '';
    }

    private static function errorLogFile(): string
    {
        return sys_get_temp_dir() . '/weft-logs/errors/' . Clock::date('Y-m-d') . '.log';
    }

    /** Create a scratch directory unique to the running test. */
    protected function makeTempDir(string $prefix = 'weft-test-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0770, true);
        return $dir;
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDir($file) : @unlink($file);
        }
        @rmdir($dir);
    }
}
