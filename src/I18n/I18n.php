<?php

declare(strict_types=1);

namespace Golovanov\Weft\I18n;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Support\Arr;

/**
 * Tiny translation store loaded from PHP array files:
 *
 *   /lang/<locale>/common.php   — shared strings, loaded for every route
 *   /lang/<locale>/<route>.php  — per-route strings
 *
 * Each file returns a nested array; strings are read with a dot key. Missing keys fall back
 * to the default locale, then to the key itself. Placeholders look like {name}.
 *
 * Works the same whether multilingual mode is on or off — with it off, only the default
 * locale directory is ever read.
 */
final class I18n
{
    /** @var array<string, mixed> */
    private static array $dict = [];
    private static string $locale = 'en';

    public static function boot(string $locale, string $route): void
    {
        self::$locale = $locale;
        self::$dict = [];

        $base = self::langPath();
        $fallback = Locale::default();

        // Order matters: fallback common/page first, then current-locale common/page override.
        $files = [];
        if ($fallback !== $locale) {
            $files[] = "$base/$fallback/common.php";
            $files[] = "$base/$fallback/$route.php";
        }
        $files[] = "$base/$locale/common.php";
        $files[] = "$base/$locale/$route.php";

        foreach ($files as $file) {
            if (is_file($file)) {
                $data = include $file;
                if (is_array($data)) {
                    self::$dict = self::merge(self::$dict, $data);
                }
            }
        }
    }

    /**
     * Translate a dot key, interpolating {placeholders} from $vars.
     *
     * @param array<string, scalar> $vars
     */
    public static function t(string $key, array $vars = []): string
    {
        $value = self::lookup($key);
        if (!is_string($value)) {
            $value = $key;
        }
        if ($vars !== []) {
            $replace = [];
            foreach ($vars as $k => $v) {
                $replace['{' . $k . '}'] = (string) $v;
            }
            $value = strtr($value, $replace);
        }
        return $value;
    }

    public static function has(string $key): bool
    {
        return is_string(self::lookup($key));
    }

    /** Raw value (may be an array — useful for lists of items). */
    public static function raw(string $key, mixed $default = null): mixed
    {
        $value = self::lookup($key);
        return $value ?? $default;
    }

    private static function lookup(string $key): mixed
    {
        return Arr::get(self::$dict, $key);
    }

    private static function langPath(): string
    {
        $path = (string) Config::env('LANG_PATH', '');
        if ($path === '') {
            $path = Config::root() . '/lang';
        }
        return rtrim($path, '/\\');
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (is_array($value) && isset($a[$key]) && is_array($a[$key])) {
                $a[$key] = self::merge($a[$key], $value);
            } else {
                $a[$key] = $value;
            }
        }
        return $a;
    }
}
