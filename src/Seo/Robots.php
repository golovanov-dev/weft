<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Url;

/**
 * robots.txt generator. Blocks everything in DEBUG/non-production, allows all otherwise,
 * and always advertises the sitemap.
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

        if ($allow) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . Url::origin() . '/sitemap.xml';
        }

        return implode("\n", $lines) . "\n";
    }
}
