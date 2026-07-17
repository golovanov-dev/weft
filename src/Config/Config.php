<?php

declare(strict_types=1);

namespace Golovanov\Weft\Config;

/**
 * Business configuration store.
 *
 * Two independent layers of configuration exist in a Weft site:
 *
 *  - Environment  — PHP constants defined via define() in /config/config.php
 *                   (secrets, DB credentials, feature flags, DEBUG). Per-server, not in git.
 *  - Business     — array files in /config/*.php, each returning an array, kept in git.
 *                   Accessed here by "<file>.<dot.path>", e.g. Config::get('app.seo.title').
 *
 * This mirrors the split used across the reference framework: env stays in define(),
 * everything structured lives in arrays and is read through a single dot-path API.
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    /**
     * Load every *.php file in a directory. Filename (without extension) becomes the top-level key.
     */
    public static function load(string $dir): void
    {
        $dir = rtrim($dir, '/\\');
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $data = require $file;
            if (is_array($data)) {
                self::$items[$name] = $data;
            }
        }
        self::$loaded = true;
    }

    /**
     * Read a value by dot path. Returns $default when the key is absent.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $node = self::$items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__weft_missing__') !== '__weft_missing__';
    }

    /**
     * Override / inject a value at runtime (mostly useful in tests and bootstrap).
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $node = &self::$items;
        foreach ($segments as $i => $segment) {
            if ($i === array_key_last($segments)) {
                $node[$segment] = $value;
                return;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Convenience reader for environment constants with a typed default.
     */
    public static function env(string $constant, mixed $default = null): mixed
    {
        return defined($constant) ? constant($constant) : $default;
    }
}
