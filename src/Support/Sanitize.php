<?php

declare(strict_types=1);

namespace Golovanov\Weft\Support;

/**
 * Input/output sanitisation kept deliberately separate.
 *
 *  - input()  normalises incoming data before storage (trim, strip control chars, "" => null).
 *             It does NOT escape HTML.
 *  - output() escapes for safe HTML rendering. Apply at render time, never at save time,
 *             otherwise you get double-encoding.
 */
final class Sanitize
{
    /** Normalise a single incoming scalar. Empty becomes null. */
    public static function input(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        // Strip control characters except tab/newline/carriage-return.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /** Recursively normalise an incoming array (e.g. $_POST). */
    public static function inputArray(?array $data): array
    {
        if ($data === null) {
            return [];
        }
        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = is_array($value) ? self::inputArray($value) : self::input(is_scalar($value) ? (string) $value : null);
        }
        return $out;
    }

    /** Escape a value for HTML output. */
    public static function output(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Normalise a raw URL path: keep only safe characters, collapse slashes,
     * and remove directory-traversal segments.
     */
    public static function path(string $path): string
    {
        $path = trim($path, "/ \t\n\r\0\x0B");
        $path = preg_replace('~[^a-zA-Z0-9/_\-.]~', '', $path) ?? '';
        $path = preg_replace('~/{2,}~', '/', $path) ?? '';
        $parts = array_filter(
            explode('/', $path),
            static fn(string $s): bool => $s !== '' && $s !== '.' && $s !== '..'
        );
        return implode('/', $parts);
    }
}
