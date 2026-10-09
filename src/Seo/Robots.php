<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Url;

/**
 * robots.txt generator. Blocks everything in DEBUG or with app.seo.robots_allow off, allows all
 * otherwise, and then advertises /sitemap.xml when app.sitemap is set: a non-empty list or a
 * callable. The callable is not called here: robots.txt is fetched on every crawl, and a generator
 * that throws would turn it into a 500, which crawlers read as "the whole site is closed".
 */
final class Robots
{
    public static function text(): string
    {
        $debug = (bool) Config::env('DEBUG', false);
        $allow = !$debug && (bool) Config::get('app.seo.robots_allow', true);

        $lines = ['User-agent: *'];
        $lines[] = $allow ? 'Allow: /' : 'Disallow: /';

        foreach ((array) Config::get('app.seo.robots_disallow', []) as $path) {
            $lines[] = 'Disallow: ' . $path;
        }

        $sitemap = Config::get('app.sitemap', []);
        if ($allow && (is_callable($sitemap) || (array) $sitemap !== [])) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . Url::origin() . Url::asset('sitemap.xml');
        }

        return implode("\n", $lines) . "\n";
    }
}
