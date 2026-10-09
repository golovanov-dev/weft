<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Firebase\JWT\JWT as FirebaseJwt;
use Golovanov\Weft\Security\Jwt;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class JwtTest extends TestCase
{
    // firebase/php-jwt 7.x requires HS256 keys of at least 32 bytes.
    private const KEY = 'test-secret-key-0123456789abcdef-0123';

    public function testEncodeDecodeRoundTrip(): void
    {
        $token = Jwt::encode(['sub' => 'user-1', 'role' => 'admin'], self::KEY);
        $claims = Jwt::decode($token, self::KEY);

        $this->assertIsArray($claims);
        $this->assertSame('user-1', $claims['sub']);
        $this->assertSame('admin', $claims['role']);
        $this->assertArrayHasKey('iat', $claims);
    }

    public function testEncodeWithoutTtlIssuesATokenForAnHour(): void
    {
        $claims = Jwt::decode(Jwt::encode(['sub' => 'u'], self::KEY), self::KEY);

        $this->assertIsArray($claims);
        $this->assertSame($claims['iat'] + 3600, $claims['exp']);
    }

    public function testEncodeGivesAnExpOfNullTheTtl(): void
    {
        $claims = Jwt::decode(Jwt::encode(['sub' => 'u', 'exp' => null], self::KEY, ttl: 60), self::KEY);

        $this->assertIsArray($claims);
        $this->assertSame($claims['iat'] + 60, $claims['exp']);
    }

    public function testEncodeKeepsTheExpOfTheClaimsOverTheTtl(): void
    {
        $exp = time() + 120;
        $claims = Jwt::decode(Jwt::encode(['sub' => 'u', 'exp' => $exp], self::KEY, ttl: 60), self::KEY);

        $this->assertIsArray($claims);
        $this->assertSame($exp, $claims['exp']);
    }

    public function testDecodeRejectsATokenWithoutExpiry(): void
    {
        $token = FirebaseJwt::encode(['sub' => 'u', 'iat' => time()], self::KEY, 'HS256');

        $this->assertNull(Jwt::decode($token, self::KEY));
    }

    #[RunInSeparateProcess]
    public function testDecodeAcceptsOnlyTheConfiguredIssuer(): void
    {
        define('AUTH_JWT_ISSUER', 'https://auth.example.com');

        $claims = Jwt::decode(Jwt::encode(['sub' => 'u'], self::KEY), self::KEY);
        $this->assertIsArray($claims);
        $this->assertSame('https://auth.example.com', $claims['iss']);

        $this->assertNull(Jwt::decode(Jwt::encode(['iss' => 'https://other.example.com'], self::KEY), self::KEY));
        $this->assertNull(Jwt::decode(FirebaseJwt::encode(['exp' => time() + 60], self::KEY, 'HS256'), self::KEY));
    }

    public function testDecodeReturnsNullForExpiredToken(): void
    {
        $token = Jwt::encode(['sub' => 'u'], self::KEY, 'HS256', -10);
        $this->assertNull(Jwt::decode($token, self::KEY));
    }

    public function testDecodeReturnsNullForWrongKey(): void
    {
        $token = Jwt::encode(['sub' => 'u'], self::KEY);
        $this->assertNull(Jwt::decode($token, 'other-key-0123456789abcdef-01234567'));
    }

    public function testDecodeReturnsNullForGarbageOrEmptyInput(): void
    {
        $this->assertNull(Jwt::decode('not.a.jwt', self::KEY));
        $this->assertNull(Jwt::decode('', self::KEY));
        // No key given and no AUTH_JWT_KEY constant -> null, never an exception.
        $this->assertNull(Jwt::decode('whatever'));
    }

    public function testFromBearerExtractsToken(): void
    {
        $this->assertSame('abc.def.ghi', Jwt::fromBearer('Bearer abc.def.ghi'));
        $this->assertSame('abc', Jwt::fromBearer('bearer abc'));
        $this->assertSame('', Jwt::fromBearer('Token abc'));
        $this->assertSame('', Jwt::fromBearer(null));
        $this->assertSame('', Jwt::fromBearer(''));
    }
}
