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
     * Full set of tags (styles + module script) for an entry, ready to drop in <head>.
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

        $tags = [];
        foreach ($chunk['css'] ?? [] as $css) {
            $tags[] = '<link rel="stylesheet" href="' . self::assetUrl($css) . '">';
        }
        $tags[] = '<script type="module" src="' . self::assetUrl($chunk['file']) . '"></script>';
        return implode("\n  ", $tags) . "\n";
    }

    private static function assetUrl(string $file): string
    {
        return Url::asset('dist/' . ltrim($file, '/'));
    }

    /** @return array<string, array{file: string, css?: array<int, string>}> */
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
