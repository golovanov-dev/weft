<?php

declare(strict_types=1);

namespace Golovanov\Weft\Cache;

/**
 * Filesystem cache. Each entry is a PHP file storing an expiry timestamp and a serialized value.
 * No external dependency — the default store for simple sites.
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
        @file_put_contents($file, $raw, LOCK_EX);
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
