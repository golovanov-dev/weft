<?php

declare(strict_types=1);

namespace Golovanov\Weft\Support;

final class Str
{
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = strtolower(trim($value));
        // Transliterate common accents where intl is unavailable.
        $value = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('~[^a-z0-9]+~', $separator, $value) ?? '';
        return trim($value, $separator);
    }

    public static function limit(string $value, int $limit = 160, string $end = '…'): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $limit)) . $end;
    }

    public static function startsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_starts_with($haystack, $needle);
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains($haystack, $needle);
    }
}
