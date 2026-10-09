<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Example;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Security\Csrf;
use Golovanov\Weft\Tests\TestCase;

/**
 * The example's api/contact.php, run the way the Kernel runs an endpoint (required with $request
 * in scope, output captured). AlertLoop and Google answer through HttpClient's fake transport.
 * Not covered: a failing mail() (the transport is the PHP setup), the Location of the redirect
 * (the CLI keeps no headers) and the contact page itself.
 */
final class ContactEndpointTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../example';

    /** @var list<array<string, mixed>> alerts AlertLoop received */
    private array $alerts = [];

    /** @var array<string, mixed> Google's answer about a captcha token */
    private array $google = [];

    public static function setUpBeforeClass(): void
    {
        require_once self::EXAMPLE . '/app/Services/ContactService.php';
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.contact', ['request_types' => ['question'], 'mail_to' => '', 'mail_from' => '']);
        $this->alerts = [];
        $alerts = &$this->alerts;
        $google = &$this->google;
        self::fakeHttp(static function (array $request) use (&$alerts, &$google): array {
            if (str_contains((string) $request['url'], 'recaptcha')) {
                return $google;
            }
            $alerts[] = json_decode((string) $request['body'], true);
            return ['status' => 202];
        });
    }

    private function alertsOn(): void
    {
        Config::set('app.alertloop', ['enabled' => true, 'base_url' => 'https://alerts.example', 'api_key' => 'k']);
    }

    /** @return array<string, mixed> a submission that passes validation */
    private function validForm(): array
    {
        return [
            Csrf::fieldName() => Csrf::token(),
            'name' => 'Ann',
            'email' => 'ann@example.com',
            'type' => 'question',
            'message' => 'A question about Weft.',
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{int, string, array<string, mixed>|null} status, raw body, decoded body
     */
    private function post(array $fields, bool $json = true, string $ip = '203.0.113.5'): array
    {
        $request = new Request(
            method: 'POST',
            uri: '/api/contact',
            path: '/api/contact',
            clientIp: $ip,
            headers: $json ? ['accept' => 'application/json', 'x-requested-with' => 'XMLHttpRequest'] : [],
            query: [],
            post: $fields,
            rawBody: null,
        );
        RequestContext::setRequest($request);
        http_response_code(200);

        $run = static function (string $__file, Request $request): void {
            require $__file;
        };
        ob_start();
        $run(self::EXAMPLE . '/api/contact.php', $request);
        $body = (string) ob_get_clean();

        $status = http_response_code();
        return [is_int($status) ? $status : 200, $body, json_decode($body, true)];
    }

    public function testRequestNoChannelConfirmedIsA503AndLogged(): void
    {
        $logOffset = self::errorLogSize();

        [$status, , $data] = $this->post($this->validForm());

        $this->assertSame(503, $status);
        $this->assertFalse($data['success']);
        $this->assertSame('Your message could not be sent right now. Please try again later.', $data['error']);
        $this->assertStringContainsString('Contact request not delivered', self::errorLogSince($logOffset));
    }

    public function testRequestAcceptedByAlertLoopIsASuccessAlertedWithoutDedupeKey(): void
    {
        $this->alertsOn();

        [$status, , $data] = $this->post($this->validForm());

        $this->assertSame(200, $status);
        $this->assertTrue($data['success']);
        $this->assertCount(1, $this->alerts);
        $this->assertStringStartsWith('New contact request', $this->alerts[0]['message']);
        $this->assertArrayNotHasKey('dedupe_key', $this->alerts[0]);
    }

    /** 422 names the fields; the corrected form sent at once is not throttled by the failed one. */
    public function testInvalidFieldsAreNamedAndTheCorrectedRetryIsNotThrottled(): void
    {
        $this->alertsOn();

        [$status, , $data] = $this->post(['email' => 'not-an-email', 'message' => 'short'] + $this->validForm());

        $this->assertSame(422, $status);
        $this->assertSame(['email', 'message'], array_column($data['errors'], 'name'));
        $this->assertSame('Please enter a valid email address.', $data['errors'][0]['text']);

        [$status] = $this->post($this->validForm());
        $this->assertSame(200, $status);
    }

    /** A token posted as an array (_csrf[]=…) is an invalid token, not a 500. */
    public function testTokenThatIsNotAStringIsA419WithAHint(): void
    {
        [$status, , $data] = $this->post([Csrf::fieldName() => ['x']] + $this->validForm());

        $this->assertSame(419, $status);
        $this->assertStringContainsString('refresh the page', $data['error']);
    }

    private function captchaOn(): void
    {
        $this->alertsOn();
        Config::set('app.captcha', [
            'enabled' => true,
            'site_key' => 'site',
            'secret_key' => 'secret',
            'allowed_hosts' => ['example.com'],
        ]);
    }

    /** @param array<string, mixed> $answer what Google says, over a human's answer */
    private function googleAnswers(array $answer): void
    {
        $this->google = ['body' => (string) json_encode($answer + [
            'success' => true,
            'score' => 0.9,
            'action' => 'contact',
            'hostname' => 'example.com',
        ])];
    }

    /** The rejection alert carries Google's verdict; a repeat within the window is not sent. */
    public function testCaptchaRejectionAlertsOnceWithTheReason(): void
    {
        $this->captchaOn();
        $this->googleAnswers(['hostname' => 'staging.example.com']);

        [$first] = $this->post(['recaptcha_token' => 't'] + $this->validForm(), ip: '203.0.113.5');
        [$second] = $this->post(['recaptcha_token' => 't'] + $this->validForm(), ip: '203.0.113.6');

        $this->assertSame([400, 400], [$first, $second]);
        $this->assertCount(1, $this->alerts);
        $this->assertSame('contact:captcha-failed:host', $this->alerts[0]['dedupe_key']);
        $this->assertSame(
            ['score' => 0.9, 'errorCodes' => [], 'hostOk' => false, 'actionOk' => true],
            $this->alerts[0]['payload'],
        );
    }

    /**
     * A bot's rejection does not hold back the alert about a broken setup: each setup reason has
     * a key of its own, every other rejection shares the bot's.
     */
    public function testCaptchaBotAndSetupRejectionsHaveTheirOwnDedupeKeys(): void
    {
        $this->captchaOn();
        $answers = [
            ['score' => 0.1],
            ['success' => false, 'error-codes' => ['timeout-or-duplicate']],
            ['success' => false, 'error-codes' => ['invalid-input-secret']],
            ['action' => 'login'],
            ['hostname' => 'evil.example'],
        ];
        foreach ($answers as $i => $answer) {
            $this->googleAnswers($answer);
            [$status] = $this->post(['recaptcha_token' => 't'] + $this->validForm(), ip: '203.0.113.' . (10 + $i));
            $this->assertSame(400, $status);
        }
        $this->google = ['body' => false, 'error' => 'Could not resolve host: www.google.com'];
        $this->post(['recaptcha_token' => 't'] + $this->validForm(), ip: '203.0.113.20');

        $this->assertSame(
            [
                'contact:captcha-rejected',
                'contact:captcha-failed:invalid-input-secret',
                'contact:captcha-failed:action',
                'contact:captcha-failed:host',
                'contact:captcha-failed:request-failed',
            ],
            array_column($this->alerts, 'dedupe_key'),
        );
    }

    /** A form posted without JavaScript gets a redirect to the page, not raw JSON. */
    public function testFormPostWithoutJavaScriptIsRedirected(): void
    {
        $this->alertsOn();

        [$status, $body] = $this->post($this->validForm(), json: false);

        $this->assertSame(303, $status);
        $this->assertSame('', $body);
    }
}
