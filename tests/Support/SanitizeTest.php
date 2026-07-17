<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Support;

use Golovanov\Weft\Support\Sanitize;
use Golovanov\Weft\Tests\TestCase;

final class SanitizeTest extends TestCase
{
    public function testInputTrimsAndTurnsEmptyIntoNull(): void
    {
        $this->assertSame('abc', Sanitize::input('  abc  '));
        $this->assertNull(Sanitize::input(''));
        $this->assertNull(Sanitize::input('   '));
        $this->assertNull(Sanitize::input(null));
    }

    public function testInputStripsControlCharactersButKeepsWhitespaceOnes(): void
    {
        $this->assertSame('ab', Sanitize::input("a\x00\x07b"));
        $this->assertSame("a\tb\nc", Sanitize::input("a\tb\nc"));
    }

    public function testInputDoesNotEscapeHtml(): void
    {
        $this->assertSame('<b>x</b>', Sanitize::input('<b>x</b>'));
    }

    public function testInputArrayNormalisesRecursively(): void
    {
        $in = ['name' => '  Ann ', 'meta' => ['note' => '', 'n' => 5], 'tags' => []];
        $this->assertSame(
            ['name' => 'Ann', 'meta' => ['note' => null, 'n' => '5'], 'tags' => []],
            Sanitize::inputArray($in)
        );
        $this->assertSame([], Sanitize::inputArray(null));
    }

    public function testOutputEscapesHtmlIncludingQuotes(): void
    {
        $this->assertSame(
            '&lt;a href=&quot;x&quot;&gt;&#039;q&#039;&lt;/a&gt;',
            Sanitize::output('<a href="x">\'q\'</a>')
        );
        $this->assertSame('', Sanitize::output(null));
        $this->assertSame('5', Sanitize::output(5));
    }

    public function testPathRemovesTraversalSegments(): void
    {
        $this->assertSame('etc/passwd', Sanitize::path('../../etc/passwd'));
        $this->assertSame('a/b', Sanitize::path('a/./../b'));
    }

    public function testPathCollapsesSlashesAndStripsUnsafeCharacters(): void
    {
        $this->assertSame('a/b', Sanitize::path('/a//b/'));
        $this->assertSame('ab/c', Sanitize::path('a<b>/c'));
        $this->assertSame('about', Sanitize::path('/about'));
        $this->assertSame('', Sanitize::path('/'));
    }
}
