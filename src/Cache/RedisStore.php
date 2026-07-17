<?php

declare(strict_types=1);

namespace Golovanov\Weft\Cache;

use Redis;

/**
 * Redis-backed cache (requires ext-redis). Values are serialized; keys are namespaced by prefix.
 */
final class RedisStore implements Store
{
    private Redis $redis;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $password = '',
        int $database = 0,
        private string $prefix = 'weft:',
    ) {
        $this->redis = new Redis();
        $this->redis->connect($host, $port, 2.0);
        if ($password !== '') {
            $this->redis->auth($password);
        }
        if ($database !== 0) {
            $this->redis->select($database);
        }
    }

    public function get(string $key): mixed
    {
        $raw = $this->redis->get($this->prefix . $key);
        if ($raw === false) {
            return null;
        }
        $value = @unserialize((string) $raw, ['allowed_classes' => true]);
        return $value === false && $raw !== serialize(false) ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $raw = serialize($value);
        if ($ttl > 0) {
            $this->redis->setex($this->prefix . $key, $ttl, $raw);
        } else {
            $this->redis->set($this->prefix . $key, $raw);
        }
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->prefix . $key);
    }

    public function forget(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }

    public function flush(): void
    {
        $keys = $this->redis->keys($this->prefix . '*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
    }

    public function client(): Redis
    {
        return $this->redis;
    }
}
