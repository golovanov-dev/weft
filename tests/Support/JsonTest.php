<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Support;

use Golovanov\Weft\Support\Json;
use Golovanov\Weft\Tests\TestCase;

final class JsonTest extends TestCase
{
    public function testDecodeReturnsAssociativeArray(): void
    {
        $this->assertSame(['a' => 1, 'b' => ['c' => true]], Json::decode('{"a":1,"b":{"c":true}}'));
    }

    public function testDecodeThrowsOnInvalidJson(): void
    {
        $this->expectException(\RuntimeException::class);
        Json::decode('{invalid');
    }

    public function testDecodeThrowsOnScalarJson(): void
    {
        // Valid JSON but not an array — the contract promises an array.
        $this->expectException(\RuntimeException::class);
        Json::decode('"just a string"');
    }

    public function testTryDecodeReturnsDefaultOnFailure(): void
    {
        $this->assertSame(['ok' => 1], Json::tryDecode('{"ok":1}'));
        $this->assertNull(Json::tryDecode('{broken'));
        $this->assertSame('fallback', Json::tryDecode('{broken', 'fallback'));
    }

    public function testEncodeKeepsUnicodeAndSlashesReadable(): void
    {
        $this->assertSame('{"path":"/a/b","text":"привет"}', Json::encode(['path' => '/a/b', 'text' => 'привет']));
    }

    public function testEncodeReturnsEmptyStringOnFailure(): void
    {
        $this->assertSame('', Json::encode(NAN));
    }
}
