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
    /** Path of the route the Kernel matched; '' when it matched none (404), null outside the Kernel. */
    private static ?string $route = null;

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

        // No trailing slash except on the site root: "/about/" -> "/about", "/ru/" -> "/ru".
        // The kernel redirects a trailing slash away, so links never point at a redirect.
        $built = self::localePrefix($locale) . '/' . ltrim($path, '/');
        if ($built !== '/') {
            $built = rtrim($built, '/');
        }
        return self::baseUri() . $built . $suffix;
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
     * @internal Called by the Kernel once it has routed the request: the locale-independent
     * path of the matched page, or null when nothing matched (404).
     */
    public static function setRoute(?string $path): void
    {
        self::$route = $path ?? '';
    }

    /**
     * Locale-independent path of the page being served ("/", "/about", "/notes/hello"), used for
     * canonical, og:url and hreflang: the route the Kernel matched, never the raw request URI.
     * Null when the request is a 404. Outside the Kernel (CLI, tests) it is read from REQUEST_URI.
     */
    public static function routePath(): ?string
    {
        if (self::$route !== null) {
            return self::$route === '' ? null : self::$route;
        }
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

    /**
     * Current route path with base URI and any locale prefix stripped, so page lookups and
     * language switchers work off a locale-independent path ("/", "/about", "/contact").
     * On a 404 it is "/", so a language switcher there leads to the home page.
     */
    public static function currentPath(): string
    {
        return self::routePath() ?? '/';
    }

    public static function isActive(string $route): bool
    {
        $path = self::routePath();
        if ($path === null) {
            return false;
        }
        $current = trim($path, '/');
        $current = $current === '' ? 'home' : $current;
        return $current === trim($route, '/');
    }
}
