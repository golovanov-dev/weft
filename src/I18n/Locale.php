<?php

declare(strict_types=1);

namespace Golovanov\Weft\I18n;

use Golovanov\Weft\Config\Config;

/**
 * Locale configuration and resolution.
 *
 * Multilingual mode is OPTIONAL (config 'app.locales.enabled'). When disabled the site is
 * single-language: no URL prefix, everything uses the default. The locale comes from the URL
 * only, never from Accept-Language.
 *
 * When enabled, the default locale is served WITHOUT a prefix ("/about") and other locales
 * carry a first-segment prefix ("/ru/about"). This matches the reference site's URL scheme.
 */
final class Locale
{
    private static ?string $current = null;

    public static function enabled(): bool
    {
        return (bool) Config::get('app.locales.enabled', false);
    }

    /** @return array<int, string> */
    public static function supported(): array
    {
        if (!self::enabled()) {
            return [self::default()];
        }
        $list = Config::get('app.locales.supported', ['en']);
        $list = is_array($list) ? $list : ['en'];
        return array_values(array_unique(array_map('strtolower', $list)));
    }

    public static function default(): string
    {
        return strtolower((string) Config::get('app.locales.default', 'en'));
    }

    public static function isSupported(string $locale): bool
    {
        return in_array(strtolower($locale), self::supported(), true);
    }

    public static function current(): string
    {
        return self::$current ?? self::default();
    }

    public static function set(string $locale): void
    {
        self::$current = self::isSupported($locale) ? strtolower($locale) : self::default();
    }

    /** Human name for a locale (from config 'app.locales.names'), fallback to the code. */
    public static function name(string $locale): string
    {
        $names = Config::get('app.locales.names', []);
        return is_array($names) && isset($names[$locale]) ? (string) $names[$locale] : strtoupper($locale);
    }
}
