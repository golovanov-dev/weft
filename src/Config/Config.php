<?php

declare(strict_types=1);

namespace Golovanov\Weft\Config;

use Golovanov\Weft\Support\Arr;

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
    }

    /**
     * Read a value by dot path. Returns $default when the key is absent.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Arr::get(self::$items, $key, $default);
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

    /**
     * Convenience reader for environment constants with a typed default.
     */
    public static function env(string $constant, mixed $default = null): mixed
    {
        return defined($constant) ? constant($constant) : $default;
    }

    /**
     * A configured filesystem path: an absolute one as is, a relative one from root(), so the site
     * and CLI scripts agree.
     *
     * @internal Used by the engine's file stores.
     */
    public static function path(string $path): string
    {
        if ($path === '' || preg_match('~^(/|\\\\|[a-z]:|[a-z][a-z0-9+.-]*://)~i', $path)) {
            return $path;
        }
        return self::root() . '/' . $path;
    }

    /**
     * The site root: APP_ROOT, or the working directory when APP_ROOT is undefined or empty.
     * pages/, api/, components/, lang/ and relative configured paths are found from here.
     *
     * @internal
     */
    public static function root(): string
    {
        $root = (string) self::env('APP_ROOT', '');
        return rtrim($root !== '' ? $root : (getcwd() ?: '.'), '/\\');
    }

    /**
     * The log directory: LOGS_PATH, or weft-logs in the system temp directory when LOGS_PATH is
     * undefined or empty. Errors go to errors/ in it, traces to requests/ and cli/.
     *
     * @internal
     */
    public static function logsPath(): string
    {
        $path = (string) self::env('LOGS_PATH', '');
        return rtrim($path !== '' ? $path : sys_get_temp_dir() . '/weft-logs', '/\\');
    }
}
