<?php

declare(strict_types=1);

namespace Golovanov\Weft\Cache;

/** No-op store used when caching is disabled. */
final class NullStore implements Store
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
    }

    public function has(string $key): bool
    {
        return false;
    }

    public function forget(string $key): void
    {
    }

    public function flush(): void
    {
    }
}
