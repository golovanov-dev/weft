<?php

declare(strict_types=1);

namespace App;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Mail\Mail;
use Golovanov\Weft\Observability\Health;

/**
 * Site-specific wiring. Called once from /bootstrap.php after the engine is initialised.
 *
 * This is where you connect engine seams to this site: how the current user is loaded from a
 * token, which services a page can reach, what vendor/bin/weft-health checks, etc. Keep it thin.
 */
final class Bootstrap
{
    public static function init(): void
    {
        Health::check('contact_form', self::contactForm(...));
    }

    /**
     * The contact form delivers a request only by email: MAIL_TO must be set (one plain address, or
     * several separated by commas), and none of them on example.com. It checks the settings, not
     * that an email arrives: send a test request for that.
     *
     * @return array<string, mixed> the `checks.contact_form` fields of vendor/bin/weft-health
     */
    public static function contactForm(): array
    {
        $mailTo = (string) Config::get('app.contact.mail_to', '');
        $recipients = array_values(array_filter(array_map(trim(...), explode(',', $mailTo)), static fn(string $a): bool => $a !== ''));

        $problems = [];
        if ($recipients === []) {
            $problems[] = 'MAIL_TO is empty';
        }
        if (array_filter($recipients, static fn(string $a): bool => !Mail::isAddress($a)) !== []) {
            $problems[] = 'MAIL_TO is not an email address';
        }
        if (array_filter($recipients, static fn(string $a): bool => Health::isExampleHost(substr((string) strrchr($a, '@'), 1))) !== []) {
            $problems[] = 'MAIL_TO is on example.com';
        }

        return ['ok' => $problems === [], 'mail_to' => $mailTo, 'problems' => $problems];
    }
}
