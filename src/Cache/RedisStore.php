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

    /**
     * Connects, authenticates, selects the database and pings; throws when Redis rejects any of it.
     *
     * @param Redis|null $client a client to connect instead of a new one (tests)
     */
    public function __construct(
        string $host = '127.0.0.1',
        int $port = 6379,
        string $password = '',
        int $database = 0,
        private string $prefix = 'weft:',
        ?Redis $client = null,
    ) {
        $this->redis = $client ?? new Redis();
        $this->redis->connect($host, $port, 2.0);
        // phpredis throws RedisException for some rejections (WRONGPASS, NOAUTH) and returns false
        // for plain ERR replies (Redis 5's "ERR invalid password", a database out of range).
        if ($password !== '' && $this->redis->auth($password) !== true) {
            throw new \RuntimeException('Redis AUTH failed: ' . $this->lastError());
        }
        if ($database !== 0 && $this->redis->select($database) !== true) {
            throw new \RuntimeException('Redis SELECT ' . $database . ' failed: ' . $this->lastError());
        }
        // A Redis that requires a password nobody sent answers NOAUTH only to the first command.
        if ($this->redis->ping() === false) {
            throw new \RuntimeException('Redis PING failed: ' . $this->lastError());
        }
    }

    private function lastError(): string
    {
        return $this->redis->getLastError() ?? 'rejected';
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

    /**
     * Add one to a fixed-window counter and return the new count, or null when Redis reports an
     * error. INCR and the EXPIRE on the first hit run as one Lua script, so the window is never
     * extended and a key never lives without TTL.
     *
     * @internal Used by RateLimiter; not part of the Store contract.
     */
    public function increment(string $key, int $ttl): ?int
    {
        $count = $this->redis->eval(
            "local c = redis.call('INCR', KEYS[1]) "
            . "if c == 1 and tonumber(ARGV[1]) > 0 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end "
            . 'return c',
            [$this->prefix . $key, $ttl],
            1,
        );
        return is_int($count) ? $count : null;
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
