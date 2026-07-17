<?php

declare(strict_types=1);

/**
 * Write a static sitemap.xml into the web root:  php scripts/sitemap.php
 * (The kernel also serves /sitemap.xml dynamically; this is for CDNs that prefer a real file.)
 */

require dirname(__DIR__) . '/bootstrap.php';

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Seo\Sitemap;

$xml = Sitemap::xml((array) Config::get('app.sitemap', []));
$target = dirname(__DIR__) . '/public/sitemap.xml';

if (@file_put_contents($target, $xml) === false) {
    fwrite(STDERR, "Failed to write $target\n");
    exit(1);
}

echo "Wrote $target\n";
