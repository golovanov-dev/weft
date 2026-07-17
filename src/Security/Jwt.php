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
 * validated payload as an array, or null on any failure — callers never see exceptions.
 */
final class Jwt
{
    /**
     * @return array<string, mixed>|null validated claims, or null if invalid/expired
     */
    public static function decode(string $token, ?string $key = null, string $algorithm = 'HS256'): ?array
    {
        $key = $key ?? (string) Config::env('AUTH_JWT_KEY', '');
        if ($token === '' || $key === '') {
            return null;
        }
        try {
            $payload = (array) FirebaseJwt::decode($token, new Key($key, $algorithm));
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
     * @param array<string, mixed> $claims
     */
    public static function encode(array $claims, ?string $key = null, string $algorithm = 'HS256', ?int $ttl = null): string
    {
        $key = $key ?? (string) Config::env('AUTH_JWT_KEY', '');
        $now = time();
        $claims += ['iat' => $now];
        if ($ttl !== null) {
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
