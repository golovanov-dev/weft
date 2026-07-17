<?php

declare(strict_types=1);

namespace Golovanov\Weft\Support;

final class Json
{
    /**
     * Decode JSON to an associative array or throw on garbage.
     *
     * @return array<mixed>
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }
        return $data;
    }

    /**
     * Decode without throwing; returns $default on failure.
     */
    public static function tryDecode(string $json, mixed $default = null): mixed
    {
        $data = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    }

    public static function encode(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }
}
