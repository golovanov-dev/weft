<?php

declare(strict_types=1);

/**
 * Write a static sitemap.xml into the web root:  php scripts/sitemap.php
 * (The kernel also serves /sitemap.xml dynamically; this is for CDNs that prefer a real file.)
 */

require dirname(__DIR__) . '/bootstrap.php';

use Golovanov\Weft\Seo\Sitemap;

// Sitemap::routes() resolves 'app.sitemap' whether it is a plain list or a callable, so this
// file and the kernel's /sitemap.xml always describe the same site.
$xml = Sitemap::xml(Sitemap::routes());
$target = dirname(__DIR__) . '/public/sitemap.xml';

if (@file_put_contents($target, $xml) === false) {
    fwrite(STDERR, "Failed to write $target\n");
    exit(1);
}

echo "Wrote $target\n";
