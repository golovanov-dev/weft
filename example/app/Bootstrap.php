<?php

declare(strict_types=1);

namespace App;

/**
 * Site-specific wiring. Called once from /bootstrap.php after the engine is initialised.
 *
 * This is where you connect engine seams to this site: how the current user is loaded from a
 * token, which services a page can reach, etc. Keep it thin.
 */
final class Bootstrap
{
    public static function init(): void
    {
        // The example needs no wiring of its own.
    }
}
