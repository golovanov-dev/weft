<?php

declare(strict_types=1);

namespace Golovanov\Weft\Support;

final class Random
{
    /** RFC 4122 version 4 UUID. */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /** Cryptographically strong lowercase hex string. */
    public static function hex(int $bytes = 16): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    public static function digits(int $length = 6): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string) random_int(0, 9);
        }
        return $out;
    }
}
