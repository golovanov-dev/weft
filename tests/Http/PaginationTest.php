<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Http;

use Golovanov\Weft\Http\Abort;
use Golovanov\Weft\Http\Pagination;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Url::setRoute('/blog');
    }

    private static function paginate(string $query, int $total, int $perPage = 10): Pagination
    {
        $request = new Request('GET', '/blog' . ($query !== '' ? '?' . $query : ''), '/blog', '127.0.0.1', [], [], [], null);
        return Pagination::fromRequest($request, $total, $perPage);
    }

    public function testFirstPageWithoutParameter(): void
    {
        $pages = self::paginate('tag=php', 25);
        $this->assertSame([1, 3, 25, 0, 10], [$pages->page, $pages->pages, $pages->total, $pages->offset, $pages->limit]);
        $this->assertNull($pages->prev);
        // Other parameters stay in the links, as sent.
        $this->assertSame('/blog?tag=php&page=2', $pages->next);
    }

    public function testMiddlePageLinksBackToTheAddressWithoutPage(): void
    {
        $pages = self::paginate('page=2', 25);
        $this->assertSame([2, 10], [$pages->page, $pages->offset]);
        $this->assertSame('/blog', $pages->prev);
        $this->assertSame('/blog?page=3', $pages->next);
        $this->assertSame('/blog', $pages->url(1));
    }

    public function testLastPageHasNoNext(): void
    {
        $pages = self::paginate('page=3', 25);
        $this->assertSame(20, $pages->offset);
        $this->assertNull($pages->next);
    }

    public function testEmptyListHasOnePage(): void
    {
        $pages = self::paginate('', 0);
        $this->assertSame([1, 1, null, null], [$pages->page, $pages->pages, $pages->prev, $pages->next]);
    }

    public function testFirstPageRedirectsToTheAddressWithoutPage(): void
    {
        try {
            self::paginate('page=1&x=y', 25);
            $this->fail('No redirect');
        } catch (Abort $abort) {
            $this->assertSame(301, $abort->status);
            $this->assertSame('/blog?x=y', $abort->location);
        }
    }

    /** @return array<string, array{string, int}> */
    public static function missingPages(): array
    {
        return [
            'zero' => ['page=0', 25],
            'not a number' => ['page=abc', 25],
            'a line break after the number' => ['page=2%0A', 25],
            'past the end' => ['page=4', 25],
        ];
    }

    #[DataProvider('missingPages')]
    public function testPageThatDoesNotExistIsNotFound(string $query, int $total): void
    {
        try {
            self::paginate($query, $total);
            $this->fail('No 404');
        } catch (Abort $abort) {
            $this->assertSame(404, $abort->status);
            $this->assertNull($abort->location);
        }
    }

    public function testRejectsAPageSizeBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::paginate('', 10, 0);
    }
}
