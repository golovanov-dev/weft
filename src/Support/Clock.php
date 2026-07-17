<?php

declare(strict_types=1);

namespace Golovanov\Weft\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Single source of time for the whole application.
 *
 * All dates (logs, timestamps, DB session) are computed in the application timezone
 * (APP_TIMEZONE constant), not the server or database timezone. Use this instead of
 * date()/time()/new DateTime() so behaviour is identical everywhere.
 */
final class Clock
{
    private static ?DateTimeZone $tz = null;

    /**
     * Apply the application timezone as PHP's default. Call once during bootstrap,
     * before any logging or date work happens.
     */
    public static function applyDefaultTimezone(): void
    {
        date_default_timezone_set(self::timezone()->getName());
    }

    public static function timezone(): DateTimeZone
    {
        if (self::$tz === null) {
            $name = defined('APP_TIMEZONE') ? (string) APP_TIMEZONE : 'UTC';
            try {
                self::$tz = new DateTimeZone($name !== '' ? $name : 'UTC');
            } catch (\Throwable) {
                self::$tz = new DateTimeZone('UTC');
            }
        }
        return self::$tz;
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }

    public static function date(string $format = 'Y-m-d'): string
    {
        return self::now()->format($format);
    }

    public static function timestamp(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    /** e.g. "UTC+03:00" */
    public static function offset(): string
    {
        return 'UTC' . self::numericOffset();
    }

    /** e.g. "+03:00" — suitable for MySQL SET time_zone. */
    public static function numericOffset(): string
    {
        return self::now()->format('P');
    }
}
