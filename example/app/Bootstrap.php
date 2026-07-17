<?php

declare(strict_types=1);

namespace App;

use Golovanov\Weft\Database\Database;
use Golovanov\Weft\Observability\Alert;

/**
 * Site-specific wiring. Called once from /bootstrap.php after the engine is initialised.
 *
 * This is where you connect engine seams to this site: which channel receives alerts, how the
 * current user is loaded from a token, etc. Keep it thin.
 */
final class Bootstrap
{
    public static function init(): void
    {
        // Route database errors to AlertLoop (no-op unless alertloop is configured).
        Database::setErrorNotifier(Alert::notifier());
    }
}
