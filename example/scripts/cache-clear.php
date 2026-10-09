<?php

declare(strict_types=1);

/**
 * Flush the cache store:  php scripts/cache-clear.php
 *
 * Says when caching is off and exits 1 when the store cannot be flushed.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Cache\RedisStore;
use Golovanov\Weft\Config\Config;

if (!(bool) Config::get('app.cache.enabled', false)) {
    echo "Cache is off (app.cache.enabled is false): nothing to flush.\n";
    exit(0);
}

$store = Cache::store();
if ((string) Config::get('app.cache.driver', 'file') === 'redis' && !$store instanceof RedisStore) {
    fwrite(STDERR, 'Cache not flushed: Redis is unavailable; the reason is in ' . Config::logsPath() . "/errors/.\n");
    exit(1);
}

try {
    $store->flush();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Cache not flushed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Cache flushed.\n";
