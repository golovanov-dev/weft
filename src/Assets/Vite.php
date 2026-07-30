<?php

declare(strict_types=1);

namespace Golovanov\Weft\Assets;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Url;

/**
 * Vite integration.
 *
 *  - DEV_MODE on  → point tags at the Vite dev server (HMR): http://localhost:5173.
 *  - DEV_MODE off → read public/dist/.vite/manifest.json and emit hashed, cache-busted assets.
 *
 * Usage in the layout <head>/<body>:
 *   echo Vite::tags('src/main.js');
 */
final class Vite
{
    private static ?array $manifest = null;

    public static function devMode(): bool
    {
        return (bool) Config::env('DEV_MODE', false);
    }

    private static function devServer(): string
    {
        return rtrim((string) Config::get('app.vite.dev_server', 'http://localhost:5173'), '/');
    }

    /**
     * Full set of tags (preload hints + styles + module script) for an entry, ready to drop
     * in <head>.
     *
     * In production the entry chunk and every chunk it statically imports are announced with
     * <link rel="modulepreload"> and the stylesheets with <link rel="preload" as="style">, so
     * the browser starts fetching them immediately instead of discovering them after parsing
     * the entry. Dynamic imports are deliberately left out: they are lazy by design and
     * preloading them would undo the code split.
     */
    public static function tags(string $entry = 'src/main.js'): string
    {
        if (self::devMode()) {
            $base = self::devServer();
            return implode("\n  ", [
                '<script type="module" src="' . $base . '/@vite/client"></script>',
                '<script type="module" src="' . $base . '/' . ltrim($entry, '/') . '"></script>',
            ]) . "\n";
        }

        $manifest = self::manifest();
        $key = ltrim($entry, '/');
        if (!isset($manifest[$key])) {
            return '<!-- Vite entry "' . htmlspecialchars($key, ENT_QUOTES) . '" not found in manifest -->' . "\n";
        }
        $chunk = $manifest[$key];

        // Module evaluation order: imported chunks first, the entry last.
        $chunks = self::staticGraph($manifest, $key);

        $preloads = [];
        $tags = [];

        // Highest priority first: the entry, then the chunks it pulls in.
        foreach (array_reverse($chunks) as $name) {
            $file = $manifest[$name]['file'] ?? null;
            if (is_string($file) && $file !== '') {
                $preloads[] = '<link rel="modulepreload" href="' . self::assetUrl($file) . '">';
            }
        }

        $styles = [];
        foreach ($chunks as $name) {
            foreach ($manifest[$name]['css'] ?? [] as $css) {
                if (is_string($css) && !in_array($css, $styles, true)) {
                    $styles[] = $css;
                }
            }
        }
        foreach ($styles as $css) {
            $url = self::assetUrl($css);
            $preloads[] = '<link rel="preload" as="style" href="' . $url . '">';
            $tags[] = '<link rel="stylesheet" href="' . $url . '">';
        }

        $tags[] = '<script type="module" src="' . self::assetUrl($chunk['file']) . '"></script>';
        return implode("\n  ", array_merge($preloads, $tags)) . "\n";
    }

    /**
     * Chunk keys reachable from $entry through static `imports`, in module evaluation order
     * (imports first, the entry last). Every chunk appears once; a chunk is marked visited
     * before its own imports are walked, so import cycles terminate.
     *
     * @param array<string, array<string, mixed>> $manifest
     * @return list<string>
     */
    private static function staticGraph(array $manifest, string $entry): array
    {
        $order = [];
        $visited = [];

        $visit = static function (string $key) use (&$visit, &$order, &$visited, $manifest): void {
            if (isset($visited[$key]) || !isset($manifest[$key])) {
                return;
            }
            $visited[$key] = true;
            foreach ($manifest[$key]['imports'] ?? [] as $import) {
                if (is_string($import)) {
                    $visit($import);
                }
            }
            $order[] = $key;
        };

        $visit($entry);
        return $order;
    }

    private static function assetUrl(string $file): string
    {
        return Url::asset('dist/' . ltrim($file, '/'));
    }

    /**
     * @return array<string, array{
     *     file: string,
     *     name?: string,
     *     src?: string,
     *     isEntry?: bool,
     *     css?: array<int, string>,
     *     imports?: array<int, string>,
     *     dynamicImports?: array<int, string>
     * }>
     */
    private static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }
        $root = (string) Config::env('WEB_ROOT', (string) Config::env('APP_ROOT', getcwd() ?: '.') . '/public');
        $path = rtrim($root, '/\\') . '/dist/.vite/manifest.json';
        if (!is_file($path)) {
            // Vite <5 wrote the manifest to dist/manifest.json.
            $legacy = rtrim($root, '/\\') . '/dist/manifest.json';
            $path = is_file($legacy) ? $legacy : $path;
        }
        $json = is_file($path) ? file_get_contents($path) : false;
        $data = $json !== false ? json_decode($json, true) : null;
        self::$manifest = is_array($data) ? $data : [];
        return self::$manifest;
    }
}
