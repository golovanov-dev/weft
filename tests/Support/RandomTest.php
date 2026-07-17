<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Support;

use Golovanov\Weft\Support\Random;
use Golovanov\Weft\Tests\TestCase;

final class RandomTest extends TestCase
{
    public function testUuidIsRfc4122Version4(): void
    {
        $uuid = Random::uuid();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    public function testUuidIsUniquePerCall(): void
    {
        $this->assertNotSame(Random::uuid(), Random::uuid());
    }

    public function testHexReturnsLowercaseHexOfRequestedByteLength(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Random::hex());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', Random::hex(4));
        // Byte count below 1 is clamped to 1.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{2}$/', Random::hex(0));
    }

    public function testDigitsReturnsOnlyDigitsOfRequestedLength(): void
    {
        $this->assertMatchesRegularExpression('/^\d{6}$/', Random::digits());
        $this->assertMatchesRegularExpression('/^\d{10}$/', Random::digits(10));
    }
}
