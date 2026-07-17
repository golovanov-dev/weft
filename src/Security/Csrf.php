<?php

declare(strict_types=1);

namespace Golovanov\Weft\Security;

use Golovanov\Weft\Support\Sanitize;

/**
 * Session-based CSRF protection for same-origin form posts.
 *
 *   <?= Csrf::field() ?>            in the form
 *   if (!Csrf::check($_POST['_csrf'] ?? '')) { ... }   in the handler
 */
final class Csrf
{
    private const KEY = '_weft_csrf';
    private const FIELD = '_csrf';

    public static function token(): string
    {
        self::startSession();
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . Sanitize::output(self::token()) . '">';
    }

    public static function fieldName(): string
    {
        return self::FIELD;
    }

    public static function check(?string $token): bool
    {
        self::startSession();
        $expected = $_SESSION[self::KEY] ?? '';
        return is_string($token) && $token !== '' && is_string($expected) && hash_equals($expected, $token);
    }

    private static function startSession(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }
}
