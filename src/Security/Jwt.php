<?php

declare(strict_types=1);

namespace Golovanov\Weft\Security;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use Golovanov\Weft\Config\Config;

/**
 * JWT verification/creation via firebase/php-jwt, wired to the AUTH_JWT_* environment constants.
 *
 * Used to validate access tokens (e.g. Bearer tokens from an auth service). decode() returns the
 * validated payload as an array, or null on any failure — callers never see exceptions. Tokens
 * always expire: decode() rejects a token without `exp`, and encode() issues none without it.
 */
final class Jwt
{
    /**
     * @return array<string, mixed>|null validated claims, or null if invalid, expired, without
     *   `exp`, or (with AUTH_JWT_ISSUER set) from another issuer
     */
    public static function decode(string $token, ?string $key = null, string $algorithm = 'HS256'): ?array
    {
        $key = $key ?? (string) Config::env('AUTH_JWT_KEY', '');
        if ($token === '' || $key === '') {
            return null;
        }
        try {
            $payload = (array) FirebaseJwt::decode($token, new Key($key, $algorithm));
            if (!isset($payload['exp'])) {
                return null;
            }
            $issuer = (string) Config::env('AUTH_JWT_ISSUER', '');
            if ($issuer !== '' && ($payload['iss'] ?? null) !== $issuer) {
                return null;
            }
            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $claims a numeric `exp` of their own wins over $ttl
     * @param int $ttl seconds until `exp`, used when $claims has no numeric `exp`
     */
    public static function encode(array $claims, ?string $key = null, string $algorithm = 'HS256', int $ttl = 3600): string
    {
        $key = $key ?? (string) Config::env('AUTH_JWT_KEY', '');
        $now = time();
        $claims += ['iat' => $now];
        if (!is_numeric($claims['exp'] ?? null)) {
            $claims['exp'] = $now + $ttl;
        }
        $issuer = (string) Config::env('AUTH_JWT_ISSUER', '');
        if ($issuer !== '' && !isset($claims['iss'])) {
            $claims['iss'] = $issuer;
        }
        return FirebaseJwt::encode($claims, $key, $algorithm);
    }

    /** Extract a Bearer token from an Authorization header value. */
    public static function fromBearer(?string $authorization): string
    {
        if ($authorization === null) {
            return '';
        }
        return preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m) ? trim($m[1]) : '';
    }
}
