<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests;

use Golovanov\Weft\Assets\Vite;
use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Cache\RedisStore;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Database\Database;
use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\I18n\I18n;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Kernel;
use Golovanov\Weft\Mail\Mail;
use Golovanov\Weft\Mail\Smtp;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\Health;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Security\RateLimiter;
use Golovanov\Weft\Seo\Seo;
use Golovanov\Weft\Seo\Audit;
use Golovanov\Weft\Support\Clock;
use Golovanov\Weft\View\View;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case: the engine keeps per-request state in static properties (populated once by
 * the Kernel in production). Tests reset that state around every run so cases stay isolated.
 */
abstract class TestCase extends BaseTestCase
{
    /** Rate-limit counters and alert windows of the running test, created on first use. */
    private string $countersDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        self::resetEngineState();
        $this->countersDir = sys_get_temp_dir() . '/weft-test-counters-' . bin2hex(random_bytes(6));
        Config::set('app.rate_limit.path', $this->countersDir);
    }

    protected function tearDown(): void
    {
        self::resetEngineState();
        $this->removeDir($this->countersDir);
        parent::tearDown();
    }

    protected static function resetEngineState(): void
    {
        self::setStatics(Config::class, ['items' => []]);
        self::setStatics(Kernel::class, ['api' => false]);
        self::setStatics(Seo::class, ['data' => [], 'jsonLd' => [], 'booted' => false]);
        self::setStatics(Locale::class, ['current' => null]);
        self::setStatics(Url::class, ['route' => null]);
        self::setStatics(I18n::class, ['dict' => [], 'locale' => 'en']);
        self::setStatics(Cache::class, ['store' => null, 'failureLogged' => false]);
        self::setStatics(RateLimiter::class, ['store' => null, 'redisError' => null]);
        self::setStatics(RedisStore::class, ['shared' => null, 'sharedFailure' => null]);
        self::setStatics(View::class, ['shared' => []]);
        self::setStatics(Database::class, ['instance' => null]);
        self::setStatics(Vite::class, ['manifest' => null]);
        self::setStatics(Alert::class, ['reporting' => false, 'misconfiguredLogged' => false]);
        self::setStatics(Health::class, ['checks' => []]);
        self::setStatics(HttpClient::class, ['transport' => null]);
        self::setStatics(Mail::class, ['transport' => null]);
        self::setStatics(Audit::class, ['auth' => []]);
        self::setStatics(Smtp::class, ['connector' => null]);
        self::setStatics(Trace::class, ['tracer' => null, 'trace' => null, 'id' => '', 'booted' => false]);
        self::setStatics(RequestContext::class, [
            'request' => null,
            'traceId' => '',
            'startedAt' => 0.0,
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
        $dir = Trace::directory();
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

    /**
     * Answer every HttpClient request with $respond instead of curl.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $respond gets the transport request
     *   (method, url, headers, body, timeout, ...); returns any of status, headers, body (false: no
     *   response), error, defaulting to an empty 200
     */
    protected static function fakeHttp(\Closure $respond): void
    {
        self::setStatics(HttpClient::class, [
            'transport' => static fn(array $request): array => $respond($request)
                + ['status' => 200, 'headers' => [], 'body' => '', 'error' => ''],
        ]);
    }

    /**
     * Take every Mail::send() message with $accept instead of sending it.
     *
     * @param \Closure(array<string, mixed>): string $accept gets the message (driver, from, to,
     *   subject, headers, body); returns '' to accept it or the error
     */
    protected static function fakeMail(\Closure $accept): void
    {
        self::setStatics(Mail::class, ['transport' => $accept]);
    }

    /** Size of today's error log (LOGS_PATH of the test process, tests/bootstrap.php), to read what a test adds. */
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
        return Config::logsPath() . '/errors/' . Clock::date('Y-m-d') . '.log';
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
