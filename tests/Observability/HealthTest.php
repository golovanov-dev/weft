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
            'captcha' => ['enabled' => true, 'allowed_hosts' => ['www.example.com']],
            'vite' => ['entry' => 'src/main.js'],
        ]);

        [$status, $report] = $this->runHealth();

        $this->assertSame(1, $status);
        $this->assertSame([
            'DEBUG is on',
            'DEV_MODE is on',
            'config/config.example.php is loaded',
            'app.url.base is on example.com',
            'app.captcha.allowed_hosts lists example.com',
        ], array_slice($report['production'], 0, 5));
        $this->assertStringEndsWith('manifest.json not found', $report['production'][5]);

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

    /** @return array{0: int, 1: array<string, mixed>, 2: string} exit status, report, stderr */
    private function runHealth(string $args = ''): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/weft-health') . ' ' . $args;
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->site);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [$status, (array) json_decode($stdout, true), $stderr];
    }
}
