<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Observability;

use Golovanov\Weft\Tests\TestCase;

/**
 * bin/weft-health run the way a site runs it: from the root of a scratch site whose bootstrap.php
 * loads the given environment config and app.php, then registers the site's checks.
 */
final class HealthTest extends TestCase
{
    private string $site = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->makeTempDir('weft-health-');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->site);
        parent::tearDown();
    }

    public function testReadySiteIsOkAndSaysWhichConfigItLoaded(): void
    {
        $this->makeSite('config.php', '', ['url' => ['base' => 'https://site.test']], <<<'PHP'
            Golovanov\Weft\Observability\Health::check('contact_form', fn() => ['ok' => true, 'mail_to' => 'hi@site.test']);
            PHP);

        [$status, $report] = $this->runHealth();

        $this->assertSame(0, $status, json_encode($report) ?: '');
        $this->assertSame('config/config.php', $report['config']);
        $this->assertSame([], $report['production']);
        $this->assertSame(['contact_form' => ['ok' => true, 'mail_to' => 'hi@site.test']], $report['checks']);
        $this->assertArrayNotHasKey('traceloom', $report);
    }

    public function testDevelopmentSettingsFailItUnlessRunWithDev(): void
    {
        $this->makeSite('config.example.php', "define('DEBUG', true);\ndefine('DEV_MODE', true);", [
            'url' => ['base' => 'https://example.com'],
            'vite' => ['entry' => 'src/main.js'],
        ]);

        [$status, $report] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertSame([
            'DEBUG is on',
            'DEV_MODE is on',
            'config/config.example.php is loaded',
            'app.url.base is on example.com',
        ], array_slice($report['production'], 0, 4));
        $this->assertStringEndsWith('manifest.json not found', $report['production'][4]);

        [$status, $report] = $this->runHealth('--dev');
        $this->assertSame(0, $status, json_encode($report) ?: '');
    }

    /**
     * The short app.php of the Configuration guide runs a site: the Kernel serves a page, and the
     * report fails only on what production needs from the site itself (its domain, a built frontend).
     */
    public function testTheShortAppConfigOfTheGuideRunsASite(): void
    {
        $guide = (string) file_get_contents(dirname(__DIR__, 2) . '/example/content/en/configuration.md');
        // The first php block of the section itself, never one of the next section.
        $found = preg_match('/^### A short app\.php\R(.*?)(?=^#{1,3} |\z)/ms', $guide, $section)
            && preg_match('/^```php\R(.*?)^```/ms', $section[1], $m);
        $this->assertTrue($found, 'the Configuration guide has no short app.php');
        $this->makeSite('config.php', '', []);
        file_put_contents($this->site . '/app/app.php', $m[1]);
        mkdir($this->site . '/pages');
        file_put_contents($this->site . '/pages/home.php', 'home page');
        file_put_contents($this->site . '/serve.php', <<<'PHP'
            <?php
            require __DIR__ . '/bootstrap.php';
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            Golovanov\Weft\Kernel::handle();
            PHP);

        [$status, $page] = $this->command(escapeshellarg(PHP_BINARY) . ' serve.php');
        $this->assertSame(0, $status);
        $this->assertStringContainsString('home page', $page);

        [$status, $report] = $this->runHealth();
        $this->assertSame(1, $status);
        $this->assertContains('app.url.base is on example.com', $report['production']);
        $this->assertNotSame([], preg_grep('/manifest\.json not found$/', $report['production']));
        [$status, $report] = $this->runHealth('--dev');
        $this->assertSame(0, $status, json_encode($report) ?: '');
    }

    public function testAFailingOrThrowingSiteCheckFailsIt(): void
    {
        $this->makeSite('config.php', '', [], <<<'PHP'
            Golovanov\Weft\Observability\Health::check('contact_form', fn() => ['ok' => false, 'mail_to' => '']);
            Golovanov\Weft\Observability\Health::check('database', fn() => throw new RuntimeException('no database'));
            PHP);

        [$status, $report] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertSame([
            'contact_form' => ['ok' => false, 'mail_to' => ''],
            'database' => ['ok' => false, 'error' => 'no database'],
        ], $report['checks']);
    }

    public function testAlertChannelsAndMailWithoutTheirSettingsFailIt(): void
    {
        $this->makeSite('config.php', '', [
            'alerts' => ['channels' => ['telegram', 'log'], 'telegram' => ['bot_token' => 't']],
            'mail' => ['driver' => 'smtp', 'from' => 'noreply@site.test'],
        ]);

        [$status, $report] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertSame(['telegram', 'log'], $report['alert_channels']);
        $this->assertSame(['app.alerts.telegram.chat_id is empty'], $report['alert_errors']);
        $this->assertSame('smtp', $report['mail_driver']);
        $this->assertSame(['app.mail.smtp.host is empty'], $report['mail_errors']);
    }

    /**
     * A static file in public/ that the web server serves instead of the configured robots groups; a
     * static llms.txt without app.llms is the site's own and fine.
     */
    public function testRobotsSettingsThatDoNotApplyFailIt(): void
    {
        $this->makeSite('config.php', '', [
            'url' => ['base' => 'https://site.test'],
            'seo' => ['robots_groups' => [['agents' => ['ai-training'], 'disallow' => ['/']]]],
        ]);
        mkdir($this->site . '/public');
        file_put_contents($this->site . '/public/robots.txt', "User-agent: *\nAllow: /\n");
        file_put_contents($this->site . '/public/llms.txt', "# Site\n");

        [$status, $report] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertSame([
            str_replace('\\', '/', $this->site) . '/public/robots.txt is served instead of app.seo.robots_groups',
        ], str_replace('\\', '/', $report['seo_errors']));
    }

    /** A static public/llms.txt is served instead of the configured app.llms. */
    public function testAStaticLlmsTxtWithAppLlmsFailsIt(): void
    {
        $this->makeSite('config.php', '', ['url' => ['base' => 'https://site.test'], 'llms' => ['title' => 'Site']]);
        mkdir($this->site . '/public');
        file_put_contents($this->site . '/public/llms.txt', "# Site\n");

        [$status, $report] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertSame(
            [str_replace('\\', '/', $this->site) . '/public/llms.txt is served instead of app.llms'],
            str_replace('\\', '/', $report['seo_errors']),
        );
    }

    /** Such as a database connection in the bootstrap that failed: still a JSON report, not PHP's `Uncaught`. */
    public function testABootstrapThatThrowsIsAReport(): void
    {
        $this->makeSite('config.php', '', [], <<<'PHP'
            Golovanov\Weft\Observability\ErrorHandler::run();
            throw new RuntimeException(str_repeat('x', 300));
            PHP);

        [$status, $report, $stderr] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertSame([
            'ok' => false,
            'bootstrap_error' => 'RuntimeException: ' . str_repeat('x', 200) . ' in bootstrap.php:' . $this->bootstrapLine('throw new'),
        ], $report);
        $this->assertStringNotContainsString('Uncaught', $stderr);
    }

    /** Thrown before the engine is even loaded, such as a typo in config.php: the file is in the report. */
    public function testABootstrapErrorNamesTheFileFromTheSiteRoot(): void
    {
        $this->makeSite('config.php', "define('DB_PASSWORD', 'it's-mine');", []);

        [$status, $report] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertMatchesRegularExpression('~^ParseError: .+ in config/config\.php:3$~', (string) ($report['bootstrap_error'] ?? ''));
    }

    /** As the site's exception handler would: the log line and the critical alert, here through the log channel. */
    public function testABootstrapErrorAfterTheHandlerIsRegisteredIsLoggedAndAlerted(): void
    {
        $this->makeSite('config.php', '', [
            'alerts' => ['channels' => ['log']],
            'rate_limit' => ['path' => $this->site . '/ratelimit'],
        ], <<<'PHP'
            Golovanov\Weft\Observability\ErrorHandler::run();
            throw new RuntimeException('bootstrap failed');
            PHP);

        [$status] = $this->runHealth();

        $this->assertSame(1, $status);
        $log = implode('', array_map('file_get_contents', glob($this->site . '/logs/errors/*.log') ?: []));
        $this->assertStringContainsString('EXCEPTION ERRNO:0 | bootstrap failed', $log);
        $this->assertStringContainsString('CRITICAL incident: Unhandled exception: bootstrap failed', $log);
    }

    /** Without the handler, nothing the site did not ask for: no error log of the engine's own. */
    public function testABootstrapErrorBeforeTheHandlerIsRegisteredIsOnlyTheReport(): void
    {
        $this->makeSite('config.php', '', ['alerts' => ['channels' => ['log']]], <<<'PHP'
            throw new RuntimeException('bootstrap failed');
            PHP);

        [$status, $report] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertStringStartsWith('RuntimeException: bootstrap failed in bootstrap.php:', (string) ($report['bootstrap_error'] ?? ''));
        $this->assertDirectoryDoesNotExist($this->site . '/logs/errors');
    }

    /** bootstrap.php runs in a scope of its own, so a $report of its own is not taken for the report. */
    public function testAVariableOfTheBootstrapIsNotTheReport(): void
    {
        $this->makeSite('config.example.php', '', [], <<<'PHP'
            $report = ['ok' => true, 'site' => 'mine'];
            $bootstrapError = null;
            PHP);

        [$status, $report] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertArrayNotHasKey('site', $report);
        $this->assertContains('config/config.example.php is loaded', $report['production']);
    }

    /** With PHP's display_errors on stdout, as in a CLI without php.ini: the warning goes to stderr. */
    public function testAWarningInTheBootstrapLeavesStdoutValidJson(): void
    {
        $this->makeSite('config.php', '', ['url' => ['base' => 'https://site.test']], <<<'PHP'
            trigger_error('careful', E_USER_WARNING);
            PHP);

        [$status, $stdout, $stderr] = $this->command(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/weft-health'));

        $this->assertSame(0, $status, $stdout);
        $this->assertIsArray(json_decode($stdout, true), $stdout);
        $this->assertStringContainsString('careful', $stderr);
    }

    public function testOutsideASiteRootItSaysWhereItLooked(): void
    {
        [$status, , $stderr] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertStringContainsString('no bootstrap.php in', $stderr);
    }

    /** @param array<string, mixed> $app */
    private function makeSite(string $configFile, string $constants, array $app, string $wiring = ''): void
    {
        mkdir($this->site . '/config');
        mkdir($this->site . '/app');
        file_put_contents($this->site . '/config/' . $configFile, "<?php\ndefine('LOGS_PATH', APP_ROOT . '/logs');\n{$constants}\n");
        file_put_contents($this->site . '/app/app.php', '<?php return ' . var_export($app, true) . ";\n");
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        file_put_contents($this->site . '/bootstrap.php', <<<PHP
            <?php
            define('APP_ROOT', __DIR__);
            foreach (['/config/config.php', '/config/config.example.php'] as \$candidate) {
                if (is_file(APP_ROOT . \$candidate)) {
                    require APP_ROOT . \$candidate;
                    break;
                }
            }
            require {$autoload};
            Golovanov\Weft\Config\Config::load(APP_ROOT . '/app');
            {$wiring}
            PHP);
    }

    /** The line of the scratch site's bootstrap.php that starts with $code. */
    private function bootstrapLine(string $code): int
    {
        foreach (file($this->site . '/bootstrap.php') ?: [] as $index => $line) {
            if (str_starts_with(ltrim($line), $code)) {
                return $index + 1;
            }
        }
        $this->fail("No line starting with {$code} in bootstrap.php");
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} exit status, report, stderr */
    private function runHealth(string $args = ''): array
    {
        [$status, $stdout, $stderr] = $this->command(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/weft-health') . ' ' . $args);
        return [$status, (array) json_decode($stdout, true), $stderr];
    }

    /** @return array{0: int, 1: string, 2: string} exit status, stdout and stderr of $command run in the site root */
    private function command(string $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->site);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
