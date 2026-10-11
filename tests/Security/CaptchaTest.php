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
 * verify(): the three ways it answers without calling the provider (captcha off, a key missing in
 * DEBUG, the same outside DEBUG) and its decision on Google's or Yandex's answer. HttpClient's
 * transport is a closure: alerts are captured, the provider answers with $this->google. The error log is the test
 * process's (LOGS_PATH from tests/bootstrap.php).
 */
final class CaptchaTest extends TestCase
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    private const YANDEX_URL = 'https://smartcaptcha.yandexcloud.net/validate';

    /** @var list<array<string, mixed>> */
    private array $alerts = [];

    /** @var list<array<string, mixed>> requests sent to Google */
    private array $verifications = [];

    /** @var array<string, mixed> transport response for Google */
    private array $google = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.alerts.channels', ['alertloop']);
        Config::set('app.alertloop', ['base_url' => 'https://alerts.example', 'api_key' => 'k']);
        $this->alerts = [];
        $this->verifications = [];
        $alerts = &$this->alerts;
        $verifications = &$this->verifications;
        $google = &$this->google;
        self::fakeHttp(static function (array $request) use (&$alerts, &$verifications, &$google): array {
            if (in_array($request['url'], [self::VERIFY_URL, self::YANDEX_URL], true)) {
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

    /** A token posted as an array (recaptcha_token[]=…) is no token: rejected without asking Google. */
    public function testTokenThatIsNotAStringIsAMissingToken(): void
    {
        $this->googleAnswers([]);

        $result = Captcha::verify(['token'], 'contact');

        $this->assertFalse($result['ok']);
        $this->assertSame(['missing-input-response'], $result['errorCodes']);
        $this->assertSame([], $this->verifications);

        Config::set('app.captcha', ['enabled' => false]);
        $this->assertTrue(Captcha::verify(['token'])['ok']);
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

    /**
     * A low score is the visitor's; the other rejections fail every submission until the setup is
     * fixed, so their reason is written down.
     *
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function rejections(): iterable
    {
        yield 'low score' => [['score' => 0.3], ['success' => true, 'score' => 0.3, 'hostOk' => true, 'actionOk' => true], ''];
        yield 'foreign host' => [
            ['hostname' => 'evil.example'],
            ['success' => true, 'hostOk' => false, 'actionOk' => true],
            'host "evil.example" is not in app.captcha.allowed_hosts',
        ];
        yield 'foreign action' => [
            ['action' => 'login'],
            ['success' => true, 'hostOk' => true, 'actionOk' => false],
            'action "login" is not "contact"',
        ];
        yield 'invalid secret' => [
            ['success' => false, 'error-codes' => ['invalid-input-secret']],
            ['success' => false, 'errorCodes' => ['invalid-input-secret']],
            'Google refused the secret key',
        ];
    }

    /**
     * @param array<string, mixed> $answer
     * @param array<string, mixed> $expected
     * @param string $reason the error-log reason; '' when nothing is recorded
     */
    #[DataProvider('rejections')]
    public function testAnswerThatFailsOneCheckIsRejected(array $answer, array $expected, string $reason): void
    {
        $traceId = Trace::boot();
        $this->googleAnswers($answer);
        $logOffset = self::errorLogSize();

        $result = Captcha::verify('token', 'contact');

        $this->assertFalse($result['ok']);
        $this->assertSame($expected, array_intersect_key($result, $expected));
        $this->assertFailureRecorded($traceId, $logOffset, $reason);
    }

    public function testUnreachableGoogleIsRequestFailed(): void
    {
        $traceId = Trace::boot();
        $this->googleAnswers([]);
        $this->google = ['body' => false, 'error' => 'Could not resolve host: www.google.com'];
        $logOffset = self::errorLogSize();

        $result = Captcha::verify('token', 'contact');

        $this->assertFalse($result['ok']);
        $this->assertSame(['request-failed'], $result['errorCodes']);
        $this->assertFailureRecorded($traceId, $logOffset, 'Could not resolve host: www.google.com');
    }

    /** One captcha.failed trace event and its error-log line with $reason, or neither when $reason is ''. */
    private function assertFailureRecorded(string $traceId, int $logOffset, string $reason): void
    {
        $events = array_values(array_filter(
            $this->traceEvents($traceId),
            static fn(array $e): bool => ($e['event'] ?? $e['type'] ?? null) === 'captcha.failed',
        ));
        $log = self::errorLogSince($logOffset);
        if ($reason === '') {
            $this->assertSame([], $events);
            $this->assertStringNotContainsString('Captcha rejected', $log);
            return;
        }
        $this->assertCount(1, $events);
        $this->assertSame(['errorCodes', 'hostOk', 'actionOk', 'host'], array_keys($events[0]['data']));
        $this->assertStringContainsString('Captcha rejected a submission: ' . $reason, $log);
    }

    /** @param array<string, mixed> $response what Yandex answers: body (array: JSON), status, error */
    private function yandexAnswers(array $response): void
    {
        Config::set('app.captcha', [
            'enabled' => true,
            'driver' => 'smartcaptcha',
            'site_key' => 'site',
            'secret_key' => 'secret',
            'allowed_hosts' => ['example.com'],
        ]);
        if (is_array($response['body'] ?? null)) {
            $response['body'] = (string) json_encode($response['body']);
        }
        $this->google = $response;
    }

    /** Yandex gets the secret and the token, nothing else: no IP, no form fields. */
    public function testSmartCaptchaPassesAndSendsOnlyTheSecretAndTheToken(): void
    {
        $this->yandexAnswers(['body' => ['status' => 'ok', 'message' => '', 'host' => 'example.com']]);

        $result = Captcha::verify('t0ken', 'contact');

        $this->assertTrue($result['ok']);
        $this->assertSame(1.0, $result['score']);
        $this->assertSame(self::YANDEX_URL, $this->verifications[0]['url']);
        $this->assertSame('secret=secret&token=t0ken', $this->verifications[0]['body']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>, string}>
     */
    public static function smartCaptchaRejections(): iterable
    {
        yield 'token failed' => [['body' => ['status' => 'failed', 'message' => 'Token invalid or expired.', 'host' => '']], ['invalid-input-response'], ''];
        yield 'foreign host' => [['body' => ['status' => 'ok', 'host' => 'evil.example']], [], 'host "evil.example" is not in app.captcha.allowed_hosts'];
        // How Yandex answers a wrong secret: HTTP 200, status failed (its validation docs).
        yield 'secret refused' => [['body' => ['status' => 'failed', 'message' => 'Authentication failed. Secret has not provided.']], ['invalid-input-secret'], 'Yandex refused the secret key'];
        yield 'secret refused with 403' => [['status' => 403, 'body' => ['status' => 'failed', 'message' => 'Forbidden']], ['invalid-input-secret'], 'Yandex refused the secret key'];
        yield 'unreachable' => [['body' => false, 'error' => 'Could not resolve host: smartcaptcha.yandexcloud.net'], ['request-failed'], 'Could not resolve host: smartcaptcha.yandexcloud.net'];
    }

    /**
     * @param array<string, mixed> $response
     * @param list<string> $errorCodes
     */
    #[DataProvider('smartCaptchaRejections')]
    public function testSmartCaptchaRejectionIsDiagnosedLikeRecaptcha(array $response, array $errorCodes, string $reason): void
    {
        $traceId = Trace::boot();
        $this->yandexAnswers($response);
        $logOffset = self::errorLogSize();

        $result = Captcha::verify('token', 'contact');

        $this->assertFalse($result['ok']);
        $this->assertSame($errorCodes, $result['errorCodes']);
        $this->assertFailureRecorded($traceId, $logOffset, $reason);
    }

    public function testUnknownDriverIsMisconfigured(): void
    {
        Config::set('app.captcha', ['enabled' => true, 'driver' => 'hcaptcha', 'site_key' => 'site', 'secret_key' => 'secret']);

        $this->assertSame(['driver'], Captcha::missingKeys());
        $this->assertSame(['misconfigured'], Captcha::verify('token')['errorCodes']);
        $this->assertSame([], $this->verifications);
    }
}
