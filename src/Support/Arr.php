<?php

declare(strict_types=1);

namespace Golovanov\Weft\Support;

final class Arr
{
    /**
     * Read a value from a nested array by dot path.
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        $node = $array;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    /** @return array<string, mixed> */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /** @return array<string, mixed> */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    public static function first(array $array, mixed $default = null): mixed
    {
        foreach ($array as $value) {
            return $value;
        }
        return $default;
    }
}
