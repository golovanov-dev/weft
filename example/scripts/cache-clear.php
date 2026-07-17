<?php

declare(strict_types=1);

/**
 * Flush the cache store:  php scripts/cache-clear.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Golovanov\Weft\Cache\Cache;

Cache::flush();
echo "Cache flushed.\n";
