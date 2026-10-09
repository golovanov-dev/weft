<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Support\Sanitize;

/**
 * sitemap.xml generator.
 *
 * Give it the list of public routes (config 'app.sitemap'; pages are not discovered) and it
 * emits a valid urlset, including xhtml:link hreflang alternates for every locale when
 * multilingual mode is on.
 */
final class Sitemap
{
    /**
     * The configured routes, with a callable resolved.
     *
     * 'app.sitemap' is usually a plain list, but it may also be a callable for sites whose URLs
     * come from content (posts, docs pages, …). Everything that renders a sitemap reads it
     * through here: when only the kernel resolved the callable, a CLI generator handed
     * self::xml() a list holding one Closure and died casting it to a string.
     *
     * @return array<int, string|array<string, mixed>>
     */
    public static function routes(): array
    {
        $routes = Config::get('app.sitemap', []);
        if (is_callable($routes)) {
            $routes = $routes();
        }

        return (array) $routes;
    }

    /**
     * @param array<int, string|array{path: string, lastmod?: string, changefreq?: string, priority?: string}> $routes
     */
    public static function xml(array $routes): string
    {
        $e = static fn(string $v): string => Sanitize::output($v);
        $locales = Locale::enabled() ? Locale::supported() : [Locale::default()];

        $lines = [];
        $lines[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $lines[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">';

        foreach ($routes as $route) {
            $path = is_array($route) ? (string) $route['path'] : (string) $route;
            $lastmod = is_array($route) ? ($route['lastmod'] ?? null) : null;
            $changefreq = is_array($route) ? ($route['changefreq'] ?? null) : null;
            $priority = is_array($route) ? ($route['priority'] ?? null) : null;

            foreach ($locales as $locale) {
                $lines[] = '  <url>';
                $lines[] = '    <loc>' . $e(Url::absolute($path, $locale)) . '</loc>';
                if (Locale::enabled()) {
                    foreach ($locales as $alt) {
                        $lines[] = '    <xhtml:link rel="alternate" hreflang="' . $e($alt) . '" href="' . $e(Url::absolute($path, $alt)) . '" />';
                    }
                }
                // Order follows the sitemap schema sequence: loc, lastmod, changefreq, priority.
                // Give lastmod only where a real modification date exists; a date the generator
                // invented (a file mtime, today) teaches crawlers to distrust the field, and a
                // distrusted lastmod is worth less than an absent one.
                if ($lastmod !== null && $lastmod !== '') {
                    $lines[] = '    <lastmod>' . $e((string) $lastmod) . '</lastmod>';
                }
                if ($changefreq !== null) {
                    $lines[] = '    <changefreq>' . $e((string) $changefreq) . '</changefreq>';
                }
                if ($priority !== null) {
                    $lines[] = '    <priority>' . $e((string) $priority) . '</priority>';
                }
                $lines[] = '  </url>';
            }
        }

        $lines[] = '</urlset>';
        return implode("\n", $lines) . "\n";
    }
}
