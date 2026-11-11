<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Observability;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ErrorHandlerTest extends TestCase
{
    /**
     * Registering an exception handler changes how PHP dies: the handler runs and the script
     * then terminates with status 0. A deploy step or cron job that died therefore looked
     * successful. The run has to end non-zero and say why on stderr.
     */
    public function testUncaughtExceptionEndsACliRunWithFailure(): void
    {
        $script = dirname(__DIR__) . '/fixtures/cli/uncaught.php';
        $this->assertFileExists($script);

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);

        $this->assertSame(1, $status, 'A failed CLI run must not report success. Output: ' . $text);
        $this->assertStringContainsString('Uncaught RuntimeException: boom', $text);
    }

    /** A fatal error (here: memory exhausted) is the only way to hear of a parse error after a deploy. */
    public function testFatalErrorIsLoggedAndRaisesACriticalAlert(): void
    {
        $logs = $this->makeTempDir();
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/fixtures/cli/fatal.php') . ' ' . escapeshellarg($logs) . ' 2>&1', $output);

        try {
            $log = implode('', array_map('file_get_contents', glob($logs . '/errors/*.log') ?: []));
        } finally {
            $this->removeDir($logs);
        }
        $alerts = array_values(array_filter(array_map(static fn(string $line) => json_decode($line, true), $output), 'is_array'));

        $this->assertStringContainsString('FATAL ERRNO:' . E_ERROR . ' | Allowed memory size', $log, implode(PHP_EOL, $output));
        $this->assertCount(1, $alerts, implode(PHP_EOL, $output));
        $this->assertSame('critical', $alerts[0]['severity']);
        $this->assertStringStartsWith('Fatal error: Allowed memory size', $alerts[0]['message']);
        $this->assertStringContainsString('fatal.php:', $alerts[0]['payload']['file']);
        $this->assertStringStartsWith('fatal:', $alerts[0]['dedupe_key']);
    }

    /**
     * An exception the site's bootstrap does not catch: PHP with a registered exception handler
     * would answer 200 with an empty page. A failed connection there is a 503, as in the Kernel.
     * One thrown after the Kernel answered leaves that answer alone, though with output_buffering
     * (php.ini-production) the answer is still in PHP's buffer and headers_sent() is false.
     */
    public function testUncaughtExceptionOnTheWebAnswers500Or503(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir . '/index.php', '<?php
            require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
            define("APP_ROOT", isset($_GET["after"]) ? ' . var_export(dirname(__DIR__) . '/fixtures/site', true) . ' : __DIR__);
            define("LOGS_PATH", __DIR__ . "/logs");
            Golovanov\Weft\Observability\ErrorHandler::run();
            if (isset($_GET["after"])) {
                Golovanov\Weft\Kernel::handle();
                throw new RuntimeException("after the response");
            }
            throw isset($_GET["down"])
                ? new Golovanov\Weft\Http\ServiceUnavailable("Database connection error", retryAfter: 30)
                : new RuntimeException("boom");
        ');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $log = ['file', $dir . '/server.log', 'w'];
        $server = proc_open([PHP_BINARY, '-d', 'output_buffering=4096', '-S', $address, $dir . '/index.php'], [1 => $log, 2 => $log], $pipes);
        $this->assertIsResource($server);

        try {
            [$error, $errorHeaders] = self::get('http://' . $address . '/');
            [$down, $downHeaders] = self::get('http://' . $address . '/?down=1');
            [$page, $pageHeaders] = self::get('http://' . $address . '/about?after=1');
            $errors = implode('', array_map('file_get_contents', glob($dir . '/logs/errors/*.log') ?: []));
        } finally {
            proc_terminate($server);
            proc_close($server);
            $this->removeDir($dir);
        }

        $this->assertSame('HTTP/1.1 500 Internal Server Error', $errorHeaders[0] ?? '');
        $this->assertStringContainsString('<h1>500</h1><p>Something went wrong.</p>', $error);
        $this->assertSame('HTTP/1.1 503 Service Unavailable', $downHeaders[0] ?? '');
        $this->assertContains('Retry-After: 30', $downHeaders);
        $this->assertStringContainsString('EXCEPTION ERRNO:0 | boom', $errors);
        $this->assertSame('HTTP/1.1 200 OK', $pageHeaders[0] ?? '');
        $this->assertStringContainsString('about-page', $page);
        $this->assertStringNotContainsString('<h1>500</h1>', $page);
        $this->assertStringContainsString('EXCEPTION ERRNO:0 | after the response', $errors);
    }

    /**
     * The body and headers of a GET to the test server; waits up to 5 seconds for it to start.
     *
     * @return array{string, list<string>}
     */
    private static function get(string $url): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
        for ($try = 0; $try < 50; $try++) {
            $body = @file_get_contents($url, false, $context);
            if ($body !== false) {
                return [$body, $http_response_header];
            }
            usleep(100_000);
        }
        self::fail('The test server did not answer ' . $url);
    }

    public function testErrorThatIsNotFatalAtShutdownIsLeftAlone(): void
    {
        $calls = 0;
        self::fakeHttp(static function () use (&$calls): array {
            $calls++;
            return ['status' => 202];
        });
        Config::set('app.alerts.channels', ['alertloop']);
        Config::set('app.alertloop', ['base_url' => 'https://alerts.example', 'api_key' => 'k']);
        $logOffset = self::errorLogSize();

        error_clear_last();
        @trigger_error('late warning', E_USER_WARNING);
        ErrorHandler::handleShutdown();

        $this->assertSame('', self::errorLogSince($logOffset));
        $this->assertSame(0, $calls);
    }

    public function testErrorIsLoggedAndHandedBackToPhp(): void
    {
        $logOffset = self::errorLogSize();
        // PHPUnit narrows error_reporting while a test runs; a site runs with E_ALL or close to it.
        $previous = error_reporting(E_ALL);

        try {
            $this->assertFalse(ErrorHandler::handleError(E_USER_WARNING, 'disk almost full', 'cron.php', 7));
        } finally {
            error_reporting($previous);
        }

        $this->assertStringContainsString('ERROR ERRNO:' . E_USER_WARNING . ' | disk almost full | cron.php:7', self::errorLogSince($logOffset));
    }

    public function testErrorOutsideErrorReportingIsNotLogged(): void
    {
        $logOffset = self::errorLogSize();
        $previous = error_reporting(E_ALL & ~E_USER_WARNING);

        try {
            $this->assertFalse(ErrorHandler::handleError(E_USER_WARNING, 'silenced', 'cron.php', 7));
        } finally {
            error_reporting($previous);
        }

        $this->assertSame('', self::errorLogSince($logOffset));
    }

    /** weft-health runs from the console, but the traces it checks are the web's, in requests/. */
    #[DataProvider('logDirectories')]
    public function testLogsErrorNamesAFileInPlaceOfALogDirectory(string $name): void
    {
        $this->assertNull(ErrorHandler::logsError());
        $dir = Config::logsPath() . '/' . $name;
        $aside = $dir . '-aside-' . getmypid();
        if (is_dir($dir)) {
            rename($dir, $aside);
        } else {
            @mkdir(dirname($dir), 0777, true);
        }
        file_put_contents($dir, '');
        try {
            $error = ErrorHandler::logsError();
        } finally {
            unlink($dir);
            if (is_dir($aside)) {
                rename($aside, $dir);
            }
        }
        $this->assertSame('PHP cannot write ' . $dir, $error);
    }

    /** @return array<string, array{string}> */
    public static function logDirectories(): array
    {
        return ['error log' => ['errors'], 'web traces' => ['requests']];
    }
}
