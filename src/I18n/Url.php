<?php

declare(strict_types=1);

namespace Golovanov\Weft\I18n;

use Golovanov\Weft\Config\Config;

/**
 * URL builder that is locale- and base-path aware.
 *
 *  - baseUri()   optional sub-directory the app is mounted under (usually "").
 *  - absolute origin ('app.url.base', e.g. https://golovanov.dev) is used for canonical/OG/sitemap.
 *  - the default locale has no prefix; other locales get "/<locale>".
 */
final class Url
{
    public static function baseUri(): string
    {
        return rtrim((string) Config::env('BASE_URI', ''), '/');
    }

    public static function origin(): string
    {
        return rtrim((string) Config::get('app.url.base', ''), '/');
    }

    public static function localePrefix(?string $locale = null): string
    {
        $locale = $locale ?? Locale::current();
        return ($locale !== '' && $locale !== Locale::default()) ? '/' . $locale : '';
    }

    /** Site-relative link, with base URI and (optionally) locale prefix. */
    public static function link(string $path = '/', ?string $locale = null): string
    {
        // Preserve query string and #fragment; only the path part gets a locale prefix.
        $suffix = '';
        if (($cut = strpbrk($path, '?#')) !== false) {
            $suffix = $cut;
            $path = substr($path, 0, -strlen($suffix));
        }

        $path = '/' . ltrim($path, '/');
        $built = self::baseUri() . self::localePrefix($locale) . $path;
        // Collapse a trailing slash on non-root paths ("/about/" -> "/about").
        if ($path !== '/' && str_ends_with($built, '/')) {
            $built = rtrim($built, '/');
        }
        if ($built === '') {
            $built = '/';
        }
        return $built . $suffix;
    }

    /** Same route as $path but in a specific locale (for language switchers / hreflang). */
    public static function inLocale(string $locale, string $path): string
    {
        return self::link($path, $locale);
    }

    /** API link — never carries a locale prefix. */
    public static function api(string $path = '/'): string
    {
        return self::baseUri() . '/api/' . ltrim($path, '/');
    }

    /** Static asset under the web root (images, favicon, ...). */
    public static function asset(string $path = '/'): string
    {
        return self::baseUri() . '/' . ltrim($path, '/');
    }

    /** Absolute URL (origin + link). Used for canonical, OG and sitemap. */
    public static function absolute(string $path = '/', ?string $locale = null): string
    {
        return self::origin() . self::link($path, $locale);
    }

    /**
     * Current route path with base URI and any locale prefix stripped, so page lookups and
     * hreflang generation work off a locale-independent path ("/", "/about", "/contact").
     */
    public static function currentPath(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $base = self::baseUri();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $parts = explode('/', ltrim($path, '/'), 2);
        if (($parts[0] ?? '') !== '' && Locale::isSupported($parts[0])) {
            $path = '/' . ($parts[1] ?? '');
        }
        return '/' . ltrim($path, '/');
    }

    public static function isActive(string $route): bool
    {
        $current = trim(self::currentPath(), '/');
        $current = $current === '' ? 'home' : $current;
        return $current === trim($route, '/');
    }
}
