<?php

declare(strict_types=1);

namespace Golovanov\Weft\Security;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Observability\Trace;

/**
 * Captcha verification: Google reCAPTCHA v3 (app.captcha.driver `recaptcha`, the default) or Yandex
 * SmartCaptcha (`smartcaptcha`). The provider gets only the secret and the token.
 *
 * Captcha off: verify() passes. On without a key or with an unknown driver: verify() fails with
 * `misconfigured` and sends a security alert; with DEBUG on it passes instead. A rejection a bot does
 * not cause (the provider unreachable, the secret refused, a token for another host or action) is
 * recorded as a `captcha.failed` trace event and an error-log line.
 */
final class Captcha
{
    /** Where each driver verifies a token, and who answers there (for the error log). */
    private const DRIVERS = [
        'recaptcha' => ['url' => 'https://www.google.com/recaptcha/api/siteverify', 'provider' => 'Google'],
        'smartcaptcha' => ['url' => 'https://smartcaptcha.yandexcloud.net/validate', 'provider' => 'Yandex'],
    ];

    /** True when the captcha is switched on with a known driver and both keys. */
    public static function enabled(): bool
    {
        return (bool) Config::get('app.captcha.enabled', false) && self::missingKeys() === [];
    }

    /**
     * What the switched-on captcha lacks: 'driver' (not recaptcha or smartcaptcha), 'site_key',
     * 'secret_key'; empty when all are set or the captcha is off. Non-empty means verify() reports
     * `misconfigured`.
     *
     * @return list<string>
     */
    public static function missingKeys(): array
    {
        if (!Config::get('app.captcha.enabled', false)) {
            return [];
        }
        $missing = isset(self::DRIVERS[self::driver()]) ? [] : ['driver'];
        foreach (['site_key', 'secret_key'] as $key) {
            if ((string) Config::get('app.captcha.' . $key, '') === '') {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    /** app.captcha.driver: recaptcha unless set. */
    public static function driver(): string
    {
        return (string) Config::get('app.captcha.driver', 'recaptcha');
    }

    public static function siteKey(): string
    {
        return (string) Config::get('app.captcha.site_key', '');
    }

    /**
     * @param mixed $token the posted field as it came; a value that is not a string (`recaptcha_token[]=…`) is no token
     * @param string $expectedAction the reCAPTCHA action; SmartCaptcha has none
     * @return array{ok: bool, success: bool, score: float, hostOk: bool, actionOk: bool, errorCodes: array<int, string>, skipped: bool}
     */
    public static function verify(mixed $token, string $expectedAction = 'submit'): array
    {
        $result = [
            'ok' => false,
            'success' => false,
            'score' => 0.0,
            'hostOk' => false,
            'actionOk' => false,
            'errorCodes' => [],
            'skipped' => false,
        ];

        if (!self::enabled()) {
            $missing = self::missingKeys();
            if ($missing !== []) {
                $debug = ErrorHandler::debug();
                self::reportMisconfigured($missing, $debug);
                if (!$debug) {
                    $result['errorCodes'] = ['misconfigured'];
                    return $result;
                }
            }
            $result['ok'] = true;
            $result['skipped'] = true;
            return $result;
        }

        if (!is_string($token) || $token === '') {
            $result['errorCodes'] = ['missing-input-response'];
            return $result;
        }

        $answer = self::driver() === 'smartcaptcha' ? self::askYandex($token) : self::askGoogle($token);
        if (is_string($answer)) {
            $result['errorCodes'] = ['request-failed'];
            self::reportFailed($result, '', $answer);
            return $result;
        }

        $threshold = (float) Config::get('app.captcha.threshold', 0.5);
        $allowedHosts = (array) Config::get('app.captcha.allowed_hosts', []);
        $host = $answer['host'];

        $result['success'] = $answer['success'];
        $result['score'] = $answer['score'];
        $result['actionOk'] = $answer['action'] === null || $expectedAction === '' || $answer['action'] === $expectedAction;
        $result['hostOk'] = $allowedHosts === [] ? ($host !== '') : in_array($host, $allowedHosts, true);
        $result['errorCodes'] = $answer['errorCodes'];
        $result['ok'] = $result['success'] && $result['hostOk'] && $result['actionOk'] && $result['score'] >= $threshold;

        if (in_array('invalid-input-secret', $result['errorCodes'], true)) {
            self::reportFailed($result, $host, self::DRIVERS[self::driver()]['provider'] . ' refused the secret key');
        } elseif ($result['success'] && !$result['hostOk']) {
            self::reportFailed($result, $host, 'host "' . $host . '" is not in app.captcha.allowed_hosts');
        } elseif ($result['success'] && !$result['actionOk']) {
            self::reportFailed($result, $host, 'action "' . (string) $answer['action'] . '" is not "' . $expectedAction . '"');
        }

        return $result;
    }

    /**
     * reCAPTCHA siteverify: success, score, action, hostname, error-codes; a string when no answer came.
     *
     * @return string|array{success: bool, score: float, host: string, action: ?string, errorCodes: array<int, string>}
     */
    private static function askGoogle(string $token): string|array
    {
        $response = self::post(['secret' => (string) Config::get('app.captcha.secret_key', ''), 'response' => $token]);
        $data = is_array($response['body']) ? $response['body'] : [];
        if (!$response['ok'] || $data === []) {
            return (string) $response['error'] !== '' ? (string) $response['error'] : 'Google sent no verification result';
        }
        return [
            'success' => (bool) ($data['success'] ?? false),
            'score' => (float) ($data['score'] ?? 0.0),
            'host' => (string) ($data['hostname'] ?? ''),
            'action' => (string) ($data['action'] ?? ''),
            'errorCodes' => is_array($data['error-codes'] ?? null) ? $data['error-codes'] : [],
        ];
    }

    /**
     * SmartCaptcha validate: status `ok` passes (score 1.0; there is no score or action). A refused
     * secret (HTTP 403, or `failed` with "Authentication failed…") is `invalid-input-secret`, any
     * other answer `invalid-input-response`; Yandex's message goes to the error log. A string when
     * no answer came.
     *
     * @return string|array{success: bool, score: float, host: string, action: ?string, errorCodes: array<int, string>}
     */
    private static function askYandex(string $token): string|array
    {
        $response = self::post(['secret' => (string) Config::get('app.captcha.secret_key', ''), 'token' => $token]);
        if ($response['status'] === 403) {
            return ['success' => false, 'score' => 0.0, 'host' => '', 'action' => null, 'errorCodes' => ['invalid-input-secret']];
        }
        $data = is_array($response['body']) ? $response['body'] : [];
        if (!$response['ok'] || !isset($data['status'])) {
            return (string) $response['error'] !== '' ? (string) $response['error'] : 'Yandex sent no verification result';
        }
        $passed = $data['status'] === 'ok';
        $message = is_string($data['message'] ?? null) ? $data['message'] : '';
        if (!$passed) {
            ErrorHandler::log('captcha', 0, 'SmartCaptcha did not pass a token: ' . json_encode(mb_substr($message, 0, 200), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), __FILE__, __LINE__);
        }
        return [
            'success' => $passed,
            'score' => $passed ? 1.0 : 0.0,
            'host' => (string) ($data['host'] ?? ''),
            'action' => null,
            'errorCodes' => match (true) {
                $passed => [],
                str_starts_with($message, 'Authentication failed') => ['invalid-input-secret'],
                default => ['invalid-input-response'],
            },
        ];
    }

    /**
     * @param array<string, string> $form
     * @return array{ok: bool, status: int, error: string|null, body: mixed}
     */
    private static function post(array $form): array
    {
        // log => false: the secret is in the body.
        return (new HttpClient())->request(self::DRIVERS[self::driver()]['url'], [
            'method' => 'POST',
            'json' => false,
            'body' => $form,
            'log' => false,
        ]);
    }

    /**
     * A rejection caused by the setup or by the provider rather than by the visitor: every
     * submission fails the same way until it is fixed, so the reason goes to the trace and the
     * error log.
     *
     * @param array{errorCodes: array<int, string>, hostOk: bool, actionOk: bool} $result
     */
    private static function reportFailed(array $result, string $host, string $reason): void
    {
        Trace::event('captcha.failed', [
            'errorCodes' => $result['errorCodes'],
            'hostOk' => $result['hostOk'],
            'actionOk' => $result['actionOk'],
            'host' => $host,
        ]);
        ErrorHandler::log('captcha', 500, 'Captcha rejected a submission: ' . $reason, __FILE__, __LINE__);
    }

    /**
     * Trace event and error-log line always; the alert only when the form is actually rejected.
     *
     * @param list<string> $missing
     */
    private static function reportMisconfigured(array $missing, bool $debug): void
    {
        Trace::event('captcha.misconfigured', ['missing' => $missing]);
        ErrorHandler::log(
            'captcha',
            500,
            'Captcha is enabled without ' . implode(' and ', $missing) . '; form submissions '
                . ($debug ? 'pass because DEBUG is on and will be rejected without it' : 'are rejected'),
            __FILE__,
            __LINE__,
        );
        if ($debug) {
            return;
        }
        Alert::send(
            'Captcha is enabled without its keys; form submissions are rejected',
            Alert::CRITICAL,
            'incident',
            'captcha:misconfigured',
            ['missing' => $missing],
            'security',
        );
    }
}
