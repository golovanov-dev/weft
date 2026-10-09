<?php

declare(strict_types=1);

namespace Golovanov\Weft\Security;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Observability\Trace;

/**
 * Google reCAPTCHA v3 verification, baked into the engine and toggled by config.
 *
 * When config('app.captcha.enabled') is false, verify() returns a passing result so forms keep
 * working in development without keys. When it is true but the site key or the secret key is
 * empty, verify() records a `captcha.misconfigured` trace event and a line in the error log
 * either way; with DEBUG on it then passes, otherwise it fails with `misconfigured` and sends a
 * security alert (deduplicated by key). A rejection a bot does not cause — Google unreachable, the
 * secret refused, a token for another host or action — records a `captcha.failed` trace event and
 * a line in the error log. The frontend obtains a token with the site key and posts it as
 * `recaptcha_token`; the backend verifies score/action/hostname here.
 */
final class Captcha
{
    /** True when the captcha is switched on and both keys are set. */
    public static function enabled(): bool
    {
        return (bool) Config::get('app.captcha.enabled', false) && self::missingKeys() === [];
    }

    /**
     * Keys the switched-on captcha lacks ('site_key', 'secret_key'); empty when both are set or the
     * captcha is off. Non-empty means verify() reports `misconfigured`.
     *
     * @return list<string>
     */
    public static function missingKeys(): array
    {
        if (!Config::get('app.captcha.enabled', false)) {
            return [];
        }
        $missing = [];
        foreach (['site_key', 'secret_key'] as $key) {
            if ((string) Config::get('app.captcha.' . $key, '') === '') {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    public static function siteKey(): string
    {
        return (string) Config::get('app.captcha.site_key', '');
    }

    /**
     * @param mixed $token the posted field as it came; a value that is not a string (`recaptcha_token[]=…`) is no token
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
                $debug = (bool) Config::env('DEBUG', false);
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

        $response = (new HttpClient())->request('https://www.google.com/recaptcha/api/siteverify', [
            'method' => 'POST',
            'json' => false,
            'body' => [
                'secret' => (string) Config::get('app.captcha.secret_key', ''),
                'response' => $token,
            ],
            'log' => false,
        ]);

        $data = is_array($response['body']) ? $response['body'] : [];
        if (!$response['ok'] || $data === []) {
            $result['errorCodes'] = ['request-failed'];
            self::reportFailed($result, '', $response['error'] !== '' ? (string) $response['error'] : 'Google sent no verification result');
            return $result;
        }

        $threshold = (float) Config::get('app.captcha.threshold', 0.5);
        $allowedHosts = (array) Config::get('app.captcha.allowed_hosts', []);
        $host = (string) ($data['hostname'] ?? '');

        $result['success'] = (bool) ($data['success'] ?? false);
        $result['score'] = (float) ($data['score'] ?? 0.0);
        $result['actionOk'] = ($expectedAction === '') || (($data['action'] ?? '') === $expectedAction);
        $result['hostOk'] = $allowedHosts === [] ? ($host !== '') : in_array($host, $allowedHosts, true);
        $result['errorCodes'] = is_array($data['error-codes'] ?? null) ? $data['error-codes'] : [];
        $result['ok'] = $result['success'] && $result['hostOk'] && $result['actionOk'] && $result['score'] >= $threshold;

        if (in_array('invalid-input-secret', $result['errorCodes'], true)) {
            self::reportFailed($result, $host, 'Google refused the secret key');
        } elseif ($result['success'] && !$result['hostOk']) {
            self::reportFailed($result, $host, 'host "' . $host . '" is not in app.captcha.allowed_hosts');
        } elseif ($result['success'] && !$result['actionOk']) {
            self::reportFailed($result, $host, 'action "' . (string) ($data['action'] ?? '') . '" is not "' . $expectedAction . '"');
        }

        return $result;
    }

    /**
     * A rejection caused by the setup or by Google rather than by the visitor: every submission
     * fails the same way until it is fixed, so the reason goes to the trace and the error log.
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
