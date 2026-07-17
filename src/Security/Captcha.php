<?php

declare(strict_types=1);

namespace Golovanov\Weft\Security;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\HttpClient;

/**
 * Google reCAPTCHA v3 verification, baked into the engine and toggled by config.
 *
 * When config('app.captcha.enabled') is false, verify() returns a passing result so forms keep
 * working in development without keys. The frontend obtains a token with the site key and posts
 * it as `recaptcha_token`; the backend verifies score/action/hostname here.
 */
final class Captcha
{
    public static function enabled(): bool
    {
        return (bool) Config::get('app.captcha.enabled', false)
            && Config::get('app.captcha.site_key', '') !== ''
            && Config::get('app.captcha.secret_key', '') !== '';
    }

    public static function siteKey(): string
    {
        return (string) Config::get('app.captcha.site_key', '');
    }

    /**
     * @return array{ok: bool, success: bool, score: float, hostOk: bool, actionOk: bool, errorCodes: array<int, string>, skipped: bool}
     */
    public static function verify(string $token, string $expectedAction = 'submit'): array
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
            $result['ok'] = true;
            $result['skipped'] = true;
            return $result;
        }

        if ($token === '') {
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

        return $result;
    }
}
