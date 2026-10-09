<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Golovanov\Weft\Security\Csrf;
use Golovanov\Weft\Tests\TestCase;

final class CsrfTest extends TestCase
{
    public function testTokenIsGeneratedOnceAndReused(): void
    {
        $token = Csrf::token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, Csrf::token());
    }

    public function testCheckAcceptsTheCurrentToken(): void
    {
        $this->assertTrue(Csrf::check(Csrf::token()));
    }

    public function testCheckRejectsWrongEmptyAndNullTokens(): void
    {
        Csrf::token();
        $this->assertFalse(Csrf::check('wrong'));
        $this->assertFalse(Csrf::check(''));
        $this->assertFalse(Csrf::check(null));
    }

    /** A form can post the field as an array (_csrf[]=…): that is a wrong token, not a TypeError. */
    public function testCheckRejectsATokenThatIsNotAString(): void
    {
        $this->assertFalse(Csrf::check([Csrf::token()]));
    }

    public function testCheckRejectsWhenNoTokenWasIssued(): void
    {
        $this->assertFalse(Csrf::check('anything'));
    }

    public function testFieldRendersHiddenInputWithToken(): void
    {
        $field = Csrf::field();
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="' . Csrf::fieldName() . '"', $field);
        $this->assertStringContainsString('value="' . Csrf::token() . '"', $field);
    }
}
