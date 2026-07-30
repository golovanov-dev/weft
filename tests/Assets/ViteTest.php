<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Assets;

use Golovanov\Weft\Assets\Vite;
use Golovanov\Weft\Tests\TestCase;

/**
 * Production tag rendering. DEV_MODE is an environment constant and is never defined in the
 * test bootstrap, so Vite::tags() always takes the manifest branch here; the parsed manifest
 * is injected into the static cache instead of being read from disk.
 */
final class ViteTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $manifest */
    private function setManifest(array $manifest): void
    {
        (new \ReflectionClass(Vite::class))->getProperty('manifest')->setValue(null, $manifest);
    }

    public function testUnknownEntryRendersACommentInsteadOfTags(): void
    {
        $this->setManifest([]);

        $out = Vite::tags('src/missing.js');

        $this->assertStringContainsString('not found in manifest', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function testEntryGetsModulepreloadAndStylesheetsGetPreload(): void
    {
        $this->setManifest([
            'src/main.js' => [
                'file' => 'assets/main-abc.js',
                'isEntry' => true,
                'css' => ['assets/main-def.css'],
            ],
        ]);

        $out = Vite::tags('src/main.js');

        $this->assertStringContainsString('<link rel="modulepreload" href="/dist/assets/main-abc.js">', $out);
        $this->assertStringContainsString('<link rel="preload" as="style" href="/dist/assets/main-def.css">', $out);
        $this->assertStringContainsString('<link rel="stylesheet" href="/dist/assets/main-def.css">', $out);
        $this->assertStringContainsString('<script type="module" src="/dist/assets/main-abc.js"></script>', $out);
    }

    public function testPreloadHintsComeBeforeStylesheetAndScript(): void
    {
        $this->setManifest([
            'src/main.js' => [
                'file' => 'assets/main-abc.js',
                'css' => ['assets/main-def.css'],
            ],
        ]);

        $out = Vite::tags('src/main.js');

        $lastPreload = max(
            (int) strrpos($out, 'rel="modulepreload"'),
            (int) strrpos($out, 'rel="preload"'),
        );
        $this->assertLessThan((int) strpos($out, 'rel="stylesheet"'), $lastPreload);
        $this->assertLessThan((int) strpos($out, '<script'), $lastPreload);
    }

    public function testStaticImportsArePreloadedRecursively(): void
    {
        $this->setManifest([
            'src/main.js' => [
                'file' => 'assets/main-abc.js',
                'imports' => ['_vendor-1.js'],
            ],
            '_vendor-1.js' => [
                'file' => 'assets/vendor-1.js',
                'imports' => ['_shared-2.js'],
            ],
            '_shared-2.js' => [
                'file' => 'assets/shared-2.js',
            ],
        ]);

        $out = Vite::tags('src/main.js');

        $this->assertStringContainsString('<link rel="modulepreload" href="/dist/assets/vendor-1.js">', $out);
        $this->assertStringContainsString('<link rel="modulepreload" href="/dist/assets/shared-2.js">', $out);
        // The entry is the highest-priority fetch and stays first.
        $this->assertLessThan(
            (int) strpos($out, '/dist/assets/vendor-1.js'),
            (int) strpos($out, '/dist/assets/main-abc.js'),
        );
    }

    public function testEachChunkIsPreloadedOnlyOnce(): void
    {
        $this->setManifest([
            'src/main.js' => [
                'file' => 'assets/main-abc.js',
                'imports' => ['_a.js', '_b.js'],
            ],
            '_a.js' => ['file' => 'assets/a.js', 'imports' => ['_shared.js']],
            '_b.js' => ['file' => 'assets/b.js', 'imports' => ['_shared.js']],
            '_shared.js' => ['file' => 'assets/shared.js', 'css' => ['assets/shared.css']],
        ]);

        $out = Vite::tags('src/main.js');

        $this->assertSame(1, substr_count($out, 'href="/dist/assets/shared.js"'));
        $this->assertSame(1, substr_count($out, 'rel="preload" as="style" href="/dist/assets/shared.css"'));
        $this->assertSame(1, substr_count($out, 'rel="stylesheet" href="/dist/assets/shared.css"'));
    }

    public function testImportCyclesTerminate(): void
    {
        $this->setManifest([
            'src/main.js' => ['file' => 'assets/main-abc.js', 'imports' => ['_a.js']],
            '_a.js' => ['file' => 'assets/a.js', 'imports' => ['_b.js']],
            '_b.js' => ['file' => 'assets/b.js', 'imports' => ['_a.js', 'src/main.js']],
        ]);

        $out = Vite::tags('src/main.js');

        $this->assertSame(1, substr_count($out, 'href="/dist/assets/main-abc.js">'));
        $this->assertSame(1, substr_count($out, 'href="/dist/assets/a.js"'));
        $this->assertSame(1, substr_count($out, 'href="/dist/assets/b.js"'));
    }

    public function testMissingImportedChunkIsSkipped(): void
    {
        $this->setManifest([
            'src/main.js' => ['file' => 'assets/main-abc.js', 'imports' => ['_gone.js']],
        ]);

        $out = Vite::tags('src/main.js');

        $this->assertStringContainsString('href="/dist/assets/main-abc.js"', $out);
        $this->assertStringNotContainsString('_gone', $out);
    }

    public function testDynamicImportsAreNotPreloaded(): void
    {
        $this->setManifest([
            'src/main.js' => [
                'file' => 'assets/main-abc.js',
                'dynamicImports' => ['src/lazy.js'],
            ],
            'src/lazy.js' => ['file' => 'assets/lazy-xyz.js', 'css' => ['assets/lazy.css']],
        ]);

        $out = Vite::tags('src/main.js');

        $this->assertStringNotContainsString('lazy-xyz.js', $out);
        $this->assertStringNotContainsString('lazy.css', $out);
    }

    public function testImportedChunkStylesLoadBeforeTheEntryStyles(): void
    {
        $this->setManifest([
            'src/main.js' => [
                'file' => 'assets/main-abc.js',
                'css' => ['assets/main.css'],
                'imports' => ['_base.js'],
            ],
            '_base.js' => ['file' => 'assets/base.js', 'css' => ['assets/base.css']],
        ]);

        $out = Vite::tags('src/main.js');

        $this->assertLessThan(
            (int) strpos($out, 'rel="stylesheet" href="/dist/assets/main.css"'),
            (int) strpos($out, 'rel="stylesheet" href="/dist/assets/base.css"'),
        );
    }
}
