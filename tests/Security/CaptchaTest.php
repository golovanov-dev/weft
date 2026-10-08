<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Security\Captcha;
use Golovanov\Weft\Support\Clock;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The three ways verify() answers without calling Google: captcha off, captcha on with a key
 * missing in DEBUG, and the same outside DEBUG. The alert transport is a capturing closure;
 * the error log is the default one in the system temp directory (LOGS_PATH is not defined).
 */
final class CaptchaTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('alertloop', ['enabled' => true, 'base_url' => 'https://alerts.example', 'api_key' => 'k']);
        $this->alerts = [];
        $alerts = &$this->alerts;
        (new \ReflectionClass(Alert::class))->getProperty('transport')->setValue(
            null,
            static function (string $url, string $key, array $payload) use (&$alerts): array {
                $alerts[] = $payload;
                return ['status' => 202, 'error' => ''];
            },
        );
    }

    public function testDisabledCaptchaPasses(): void
    {
        Config::set('app.captcha', ['enabled' => false]);
        $logOffset = self::errorLogSize();

        $result = Captcha::verify('');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped']);
        $this->assertSame([], $this->alerts);
        $this->assertSame('', self::errorLogSince($logOffset));
    }

    public function testMissingKeysAreReportedOnlyWhileTheCaptchaIsOn(): void
    {
        Config::set('app.captcha', ['enabled' => false, 'site_key' => '', 'secret_key' => '']);
        $this->assertSame([], Captcha::missingKeys());

        Config::set('app.captcha', ['enabled' => true, 'site_key' => 'site', 'secret_key' => '']);
        $this->assertSame(['secret_key'], Captcha::missingKeys());
        $this->assertFalse(Captcha::enabled());

        Config::set('app.captcha', ['enabled' => true, 'site_key' => 'site', 'secret_key' => 'secret']);
        $this->assertSame([], Captcha::missingKeys());
        $this->assertTrue(Captcha::enabled());
    }

    public function testEnabledWithoutAKeyFailsAndAlerts(): void
    {
        $traceId = Trace::boot();
        Config::set('app.captcha', ['enabled' => true, 'site_key' => 'site', 'secret_key' => '']);
        $logOffset = self::errorLogSize();

        $result = Captcha::verify('token');

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['skipped']);
        $this->assertSame(['misconfigured'], $result['errorCodes']);

        $this->assertCount(1, $this->alerts);
        $this->assertSame('security', $this->alerts[0]['category']);
        $this->assertSame('critical', $this->alerts[0]['severity']);
        $this->assertSame('captcha:misconfigured', $this->alerts[0]['dedupe_key']);

        $events = array_values(array_filter(
            $this->traceEvents($traceId),
            static fn(array $e): bool => ($e['event'] ?? $e['type'] ?? null) === 'captcha.misconfigured',
        ));
        $this->assertCount(1, $events);
        $this->assertSame(['secret_key'], $events[0]['data']['missing']);

        $this->assertStringContainsString(
            'Captcha is enabled without secret_key; form submissions are rejected',
            self::errorLogSince($logOffset),
        );
    }

    #[RunInSeparateProcess]
    public function testEnabledWithoutAKeyPassesInDebug(): void
    {
        define('DEBUG', true);
        $traceId = Trace::boot();
        Config::set('app.captcha', ['enabled' => true, 'site_key' => '', 'secret_key' => '']);
        $logOffset = self::errorLogSize();

        $result = Captcha::verify('');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped']);
        $this->assertSame([], $this->alerts);

        $events = array_values(array_filter(
            $this->traceEvents($traceId),
            static fn(array $e): bool => ($e['event'] ?? $e['type'] ?? null) === 'captcha.misconfigured',
        ));
        $this->assertCount(1, $events);
        $this->assertSame(['site_key', 'secret_key'], $events[0]['data']['missing']);

        $this->assertStringContainsString(
            'Captcha is enabled without site_key and secret_key; form submissions pass because DEBUG is on',
            self::errorLogSince($logOffset),
        );
    }

    private static function errorLogFile(): string
    {
        return sys_get_temp_dir() . '/weft-logs/errors/' . Clock::date('Y-m-d') . '.log';
    }

    private static function errorLogSize(): int
    {
        clearstatcache();
        return is_file(self::errorLogFile()) ? (int) filesize(self::errorLogFile()) : 0;
    }

    /** What the error log gained after the given byte offset. */
    private static function errorLogSince(int $offset): string
    {
        clearstatcache();
        return is_file(self::errorLogFile()) ? (string) file_get_contents(self::errorLogFile(), false, null, $offset) : '';
    }
}
