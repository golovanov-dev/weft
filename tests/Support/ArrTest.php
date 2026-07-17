<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Support;

use Golovanov\Weft\Support\Arr;
use Golovanov\Weft\Tests\TestCase;

final class ArrTest extends TestCase
{
    public function testGetReadsNestedValueByDotPath(): void
    {
        $data = ['a' => ['b' => ['c' => 42]]];
        $this->assertSame(42, Arr::get($data, 'a.b.c'));
        $this->assertSame(['c' => 42], Arr::get($data, 'a.b'));
    }

    public function testGetReturnsDefaultForMissingPath(): void
    {
        $data = ['a' => ['b' => 1]];
        $this->assertNull(Arr::get($data, 'a.x'));
        $this->assertSame('fallback', Arr::get($data, 'missing', 'fallback'));
        // A scalar in the middle of the path is not traversable.
        $this->assertSame('fallback', Arr::get($data, 'a.b.c', 'fallback'));
    }

    public function testGetDistinguishesNullValueFromMissingKey(): void
    {
        $data = ['a' => null];
        $this->assertNull(Arr::get($data, 'a', 'default'));
    }

    public function testOnlyKeepsListedKeys(): void
    {
        $this->assertSame(
            ['a' => 1, 'c' => 3],
            Arr::only(['a' => 1, 'b' => 2, 'c' => 3], ['a', 'c', 'missing'])
        );
    }

    public function testExceptRemovesListedKeys(): void
    {
        $this->assertSame(
            ['b' => 2],
            Arr::except(['a' => 1, 'b' => 2], ['a', 'missing'])
        );
    }

    public function testFirstReturnsFirstValueOrDefault(): void
    {
        $this->assertSame('x', Arr::first(['k' => 'x', 'j' => 'y']));
        $this->assertSame('default', Arr::first([], 'default'));
    }
}
