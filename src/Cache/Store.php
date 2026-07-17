<?php

declare(strict_types=1);

namespace Golovanov\Weft\Cache;

interface Store
{
    public function get(string $key): mixed;

    /** @param int $ttl seconds; 0 = no expiry */
    public function set(string $key, mixed $value, int $ttl = 0): void;

    public function has(string $key): bool;

    public function forget(string $key): void;

    public function flush(): void;
}
