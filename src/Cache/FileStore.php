<?php

declare(strict_types=1);

namespace Golovanov\Weft\Cache;

/**
 * Filesystem cache. Each entry is a PHP file storing an expiry timestamp and a serialized value.
 * No external dependency — the default store for simple sites.
 *
 * Files it creates get mode 0660 whatever the umask, so a cache entry or a counter created by one
 * user (a script run by the deploy user) stays writable for another in the same group (PHP).
 */
final class FileStore implements Store
{
    public function __construct(private string $dir)
    {
        $this->dir = rtrim($dir, '/\\');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }
    }

    public function get(string $key): mixed
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $payload = @unserialize($raw, ['allowed_classes' => true]);
        if (!is_array($payload) || !array_key_exists('e', $payload)) {
            return null;
        }
        if ($payload['e'] !== 0 && $payload['e'] < time()) {
            @unlink($file);
            return null;
        }
        return $payload['v'];
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $expiry = $ttl > 0 ? time() + $ttl : 0;
        $raw = serialize(['e' => $expiry, 'v' => $value]);
        $file = $this->path($key);
        $created = !is_file($file);
        if (@file_put_contents($file, $raw, LOCK_EX) !== false && $created) {
            @chmod($file, 0660);
        }
    }

    /**
     * Add one to a fixed-window counter and return the new count, or null when the counter file
     * cannot be opened, locked or written. The expiry is set by the first hit only, so later hits
     * never extend the window. Read-modify-write runs under an exclusive flock.
     *
     * @internal Used by RateLimiter; not part of the Store contract.
     */
    public function increment(string $key, int $ttl): ?int
    {
        $file = $this->path($key);
        $created = !is_file($file);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return null;
        }
        if ($created) {
            @chmod($file, 0660);
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return null;
            }
            $now = time();
            $payload = @unserialize((string) stream_get_contents($handle), ['allowed_classes' => false]);
            $live = is_array($payload) && is_int($payload['e'] ?? null) && is_int($payload['v'] ?? null)
                && ($payload['e'] === 0 || $payload['e'] >= $now);
            $count = $live ? $payload['v'] + 1 : 1;
            $expiry = $live ? $payload['e'] : ($ttl > 0 ? $now + $ttl : 0);

            $raw = serialize(['e' => $expiry, 'v' => $count]);
            if (!ftruncate($handle, 0) || !rewind($handle) || fwrite($handle, $raw) !== strlen($raw)) {
                return null;
            }
            fflush($handle);
            return $count;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    public function flush(): void
    {
        foreach (glob($this->dir . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.cache';
    }
}
