<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Support;

use Golovanov\Weft\Support\Str;
use Golovanov\Weft\Tests\TestCase;

final class StrTest extends TestCase
{
    public function testSlugLowercasesAndReplacesNonAlphanumerics(): void
    {
        $this->assertSame('hello-world', Str::slug('Hello, World!'));
        $this->assertSame('a-b-c', Str::slug('  a  b  c  '));
    }

    public function testSlugSupportsCustomSeparator(): void
    {
        $this->assertSame('hello_world', Str::slug('Hello World', '_'));
    }

    public function testSlugTrimsSeparatorsFromEnds(): void
    {
        $this->assertSame('abc', Str::slug('--abc--'));
    }

    public function testLimitKeepsShortStringsUntouched(): void
    {
        $this->assertSame('short', Str::limit('short', 10));
    }

    public function testLimitTruncatesAndAppendsEnding(): void
    {
        $this->assertSame('hello…', Str::limit('hello world', 5));
        $this->assertSame('hello!', Str::limit('hello world', 5, '!'));
    }

    public function testLimitCountsMultibyteCharacters(): void
    {
        // 5 Cyrillic characters fit exactly into a limit of 5.
        $this->assertSame('прив…', Str::limit('привет', 4));
        $this->assertSame('привет', Str::limit('привет', 6));
    }

    public function testStartsWith(): void
    {
        $this->assertTrue(Str::startsWith('abcdef', 'abc'));
        $this->assertFalse(Str::startsWith('abcdef', 'def'));
        // An empty needle never matches (unlike native str_starts_with).
        $this->assertFalse(Str::startsWith('abcdef', ''));
    }

    public function testContains(): void
    {
        $this->assertTrue(Str::contains('abcdef', 'cde'));
        $this->assertFalse(Str::contains('abcdef', 'xyz'));
        $this->assertFalse(Str::contains('abcdef', ''));
    }
}
