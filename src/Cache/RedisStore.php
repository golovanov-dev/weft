<?php

declare(strict_types=1);

namespace Golovanov\Weft\Cache;

use Golovanov\Weft\Config\Config;
use Redis;

/**
 * Redis-backed cache (requires ext-redis). Values are serialized; keys are namespaced by prefix.
 * Values come back without their classes (allowed_classes => false): an object is returned as
 * __PHP_Incomplete_Class, so cache arrays and scalars.
 */
final class RedisStore implements Store
{
    /** Seconds the connection may take, and then each reply. */
    private const CONNECT_TIMEOUT = 2.0;
    private const READ_TIMEOUT = 1.0;

    private Redis $redis;

    /** This request's connection, shared by the cache and the rate limiter (fromConfig()). */
    private static ?Redis $connection = null;

    /** Why Redis failed in this request; fromConfig() then throws it instead of connecting again. */
    private static ?\Throwable $failure = null;

    /**
     * Connects, authenticates, selects the database and pings; throws when Redis rejects any of it.
     *
     * @param Redis|null $client a client to use instead of a new one; one that is connected already
     *   is used as is
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
        if ($this->redis->isConnected()) {
            return;
        }
        $this->redis->connect($host, $port, self::CONNECT_TIMEOUT, null, 0, self::READ_TIMEOUT);
        // A connection lost mid-request fails its command at once instead of reconnecting (set
        // after connect(), which resets it to 10).
        $this->redis->setOption(Redis::OPT_MAX_RETRIES, 0);
        // phpredis throws RedisException for some rejections (WRONGPASS, NOAUTH) and returns false
        // for plain ERR replies (Redis 5's "ERR invalid password", a database out of range).
        if ($password !== '' && $this->redis->auth($password) !== true) {
            throw new \RuntimeException(
                'Redis AUTH failed (wrong password, or Redis has no password set): ' . $this->lastError(),
            );
        }
        if ($database !== 0 && $this->redis->select($database) !== true) {
            throw new \RuntimeException('Redis SELECT ' . $database . ' failed: ' . $this->lastError());
        }
        // A Redis that requires a password nobody sent answers NOAUTH only to the first command.
        if ($this->redis->ping() === false) {
            throw new \RuntimeException('Redis PING failed: ' . $this->lastError());
        }
    }

    /**
     * A store on this request's connection to the Redis of REDIS_HOST, REDIS_PORT, REDIS_PASSWORD
     * and REDIS_DB, its keys under sitePrefix() . $namespace. The first call connects; the cache and
     * the rate limiter share that connection. A caller whose command fails goes on with files.
     * Redis is tried once per request: after a failed connection, or a command that closed it
     * (command()), every later call throws at once and its caller goes on with files too.
     *
     * @internal Used by Cache and RateLimiter.
     */
    public static function fromConfig(string $namespace = ''): self
    {
        if (self::$failure === null && self::$connection?->isConnected() === false) {
            self::$failure = new \RuntimeException('a Redis command failed earlier in this request');
        }
        if (self::$failure !== null) {
            throw self::$failure;
        }
        try {
            $store = new self(
                host: (string) Config::env('REDIS_HOST', '127.0.0.1'),
                port: (int) Config::env('REDIS_PORT', 6379),
                password: (string) Config::env('REDIS_PASSWORD', ''),
                database: (int) Config::env('REDIS_DB', 0),
                prefix: self::sitePrefix() . $namespace,
                client: self::$connection,
            );
        } catch (\Throwable $e) {
            throw self::$failure = $e;
        }
        self::$connection = $store->redis;
        return $store;
    }

    /**
     * The site's Redis key prefix: app.cache.prefix when set, else the host of app.url.base and a
     * colon ("example.com:"), else "weft:".
     */
    private static function sitePrefix(): string
    {
        $prefix = Config::get('app.cache.prefix');
        if ($prefix !== null) {
            return (string) $prefix;
        }
        $host = parse_url((string) Config::get('app.url.base', ''), PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) . ':' : 'weft:';
    }

    /**
     * Run one Redis command. On RedisException (a timeout, a lost connection) the connection is
     * closed: phpredis can leave it open, and a reply that arrives late would answer the next command.
     */
    private function command(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (\RedisException $e) {
            // A socket phpredis dropped itself is left alone: close() would connect it again first.
            if ($this->redis->isConnected()) {
                $this->redis->close();
            }
            throw $e;
        }
    }

    private function lastError(): string
    {
        return $this->redis->getLastError() ?? 'rejected';
    }

    public function get(string $key): mixed
    {
        $raw = $this->command(fn () => $this->redis->get($this->prefix . $key));
        if ($raw === false) {
            return null;
        }
        $value = @unserialize((string) $raw, ['allowed_classes' => false]);
        return $value === false && $raw !== serialize(false) ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $raw = serialize($value);
        $this->command(fn () => $ttl > 0
            ? $this->redis->setex($this->prefix . $key, $ttl, $raw)
            : $this->redis->set($this->prefix . $key, $raw));
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
        $count = $this->command(fn () => $this->redis->eval(
            "local c = redis.call('INCR', KEYS[1]) "
            . "if c == 1 and tonumber(ARGV[1]) > 0 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end "
            . 'return c',
            [$this->prefix . $key, $ttl],
            1,
        ));
        return is_int($count) ? $count : null;
    }

    public function has(string $key): bool
    {
        return (bool) $this->command(fn () => $this->redis->exists($this->prefix . $key));
    }

    public function forget(string $key): void
    {
        $this->command(fn () => $this->redis->del($this->prefix . $key));
    }

    public function flush(): void
    {
        // The prefix is matched literally: "site[1]:" must not match "site1:".
        $keys = $this->command(fn () => $this->redis->keys(addcslashes($this->prefix, '\*?[]') . '*'));
        if (is_array($keys) && $keys !== []) {
            $this->command(fn () => $this->redis->del($keys));
        }
    }
}
