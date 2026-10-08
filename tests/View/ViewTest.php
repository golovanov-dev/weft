<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\View;

use Golovanov\Weft\Tests\TestCase;
use Golovanov\Weft\View\View;

final class ViewTest extends TestCase
{
    private const VIEWS = __DIR__ . '/../fixtures/views';
    private const SITE = __DIR__ . '/../fixtures/site';

    public function testRenderExtractsDataIntoTemplateScope(): void
    {
        $html = View::render(self::VIEWS . '/simple.php', ['name' => 'Ann']);
        // PHP swallows the single newline right after a template's final closing tag.
        $this->assertSame('Hello Ann, shared=none', $html);
    }

    public function testSharedDataIsAvailableInEveryTemplate(): void
    {
        View::share('shared_value', 'global');
        $html = View::render(self::VIEWS . '/simple.php', ['name' => 'Ann']);
        $this->assertSame('Hello Ann, shared=global', $html);
    }

    public function testPageDataWinsOverSharedData(): void
    {
        View::share('name', 'Shared');
        $html = View::render(self::VIEWS . '/simple.php', ['name' => 'Page']);
        $this->assertStringContainsString('Hello Page', $html);
    }

    public function testRenderThrowsForMissingTemplate(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('View not found');
        View::render(self::VIEWS . '/nope.php');
    }

    public function testTemplateThatThrowsLeavesNoOutputBehind(): void
    {
        $level = ob_get_level();
        ob_start();
        try {
            View::render(self::VIEWS . '/throws.php');
            $this->fail('the template exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('render failed', $e->getMessage());
        }
        $this->assertSame($level + 1, ob_get_level());
        $this->assertSame('', ob_get_clean());
    }

    public function testPageWrapsContentInLayout(): void
    {
        $html = View::page(self::SITE . '/pages/about.php', self::SITE . '/components/layout.php');
        $this->assertSame("<layout><p>about-page</p>\n</layout>\n", $html);
    }

    public function testPageWithoutLayoutReturnsBareContent(): void
    {
        $html = View::page(self::SITE . '/pages/about.php', null);
        $this->assertSame("<p>about-page</p>\n", $html);

        // A missing layout file degrades to bare content as well.
        $html = View::page(self::SITE . '/pages/about.php', self::SITE . '/components/nope.php');
        $this->assertSame("<p>about-page</p>\n", $html);
    }
}
