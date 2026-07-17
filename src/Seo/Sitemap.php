<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Support\Sanitize;

/**
 * sitemap.xml generator.
 *
 * Give it the list of public routes (from config 'app.sitemap' or discovered from /pages) and
 * it emits a valid urlset, including xhtml:link hreflang alternates for every locale when
 * multilingual mode is on.
 */
final class Sitemap
{
    /**
     * @param array<int, string|array{path: string, changefreq?: string, priority?: string}> $routes
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
