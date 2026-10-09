<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Security\Captcha;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * verify(): the three ways it answers without calling Google (captcha off, a key missing in
 * DEBUG, the same outside DEBUG) and its decision on Google's answer. HttpClient's transport is
 * a closure: alerts are captured, Google answers with $this->google. The error log is the default
 * one in the system temp directory (LOGS_PATH is not defined).
 */
final class CaptchaTest extends TestCase
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /** @var list<array<string, mixed>> */
    private array $alerts = [];

    /** @var list<array<string, mixed>> requests sent to Google */
    private array $verifications = [];

    /** @var array<string, mixed> transport response for Google */
    private array $google = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.alertloop', ['enabled' => true, 'base_url' => 'https://alerts.example', 'api_key' => 'k']);
        $this->alerts = [];
        $this->verifications = [];
        $alerts = &$this->alerts;
        $verifications = &$this->verifications;
        $google = &$this->google;
        self::fakeHttp(static function (array $request) use (&$alerts, &$verifications, &$google): array {
            if ($request['url'] === self::VERIFY_URL) {
                $verifications[] = $request;
                return $google;
            }
            $alerts[] = json_decode((string) $request['body'], true);
            return ['status' => 202];
        });
    }

    /** @param array<string, mixed> $answer what Google says about the token */
    private function googleAnswers(array $answer): void
    {
        Config::set('app.captcha', [
            'enabled' => true,
            'site_key' => 'site',
            'secret_key' => 'secret',
            'threshold' => 0.5,
            'allowed_hosts' => ['example.com'],
        ]);
        $this->google = ['body' => (string) json_encode($answer + [
            'success' => true,
            'score' => 0.9,
            'action' => 'contact',
            'hostname' => 'example.com',
        ])];
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

    public function testHumanPasses(): void
    {
        $this->googleAnswers([]);

        $result = Captcha::verify('token', 'contact');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['skipped']);
        $this->assertSame(0.9, $result['score']);
    }

    /** The secret goes to Google as a form field and nowhere into the trace. */
    public function testTokenAndSecretAreSentAsAFormAndNotTraced(): void
    {
        $traceId = Trace::boot();
        $this->googleAnswers([]);

        Captcha::verify('t0ken', 'contact');

        $this->assertCount(1, $this->verifications);
        $this->assertSame('POST', $this->verifications[0]['method']);
        $this->assertSame('secret=secret&response=t0ken', $this->verifications[0]['body']);
        $this->assertStringNotContainsString('t0ken', (string) json_encode($this->traceEvents($traceId)));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function rejections(): iterable
    {
        yield 'low score' => [['score' => 0.3], ['success' => true, 'score' => 0.3, 'hostOk' => true, 'actionOk' => true]];
        yield 'foreign host' => [['hostname' => 'evil.example'], ['success' => true, 'hostOk' => false, 'actionOk' => true]];
        yield 'foreign action' => [['action' => 'login'], ['success' => true, 'hostOk' => true, 'actionOk' => false]];
        yield 'invalid secret' => [
            ['success' => false, 'error-codes' => ['invalid-input-secret']],
            ['success' => false, 'errorCodes' => ['invalid-input-secret']],
        ];
    }

    /**
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $expected
     */
    #[DataProvider('rejections')]
    public function testAnswerThatFailsOneCheckIsRejected(array $answer, array $expected): void
    {
        $this->googleAnswers($answer);

        $result = Captcha::verify('token', 'contact');

        $this->assertFalse($result['ok']);
        $this->assertSame($expected, array_intersect_key($result, $expected));
    }

    public function testUnreachableGoogleIsRequestFailed(): void
    {
        $this->googleAnswers([]);
        $this->google = ['body' => false, 'error' => 'Could not resolve host: www.google.com'];

        $result = Captcha::verify('token', 'contact');

        $this->assertFalse($result['ok']);
        $this->assertSame(['request-failed'], $result['errorCodes']);
    }
}
