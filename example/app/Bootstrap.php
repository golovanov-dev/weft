<?php

declare(strict_types=1);

namespace App;

use Golovanov\Weft\Config\Config;
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
     * The contact form needs a channel: an email to a real MAIL_TO or an AlertLoop alert. It checks
     * the settings and whether PHP can hand mail() a message, not that an email arrives: send a test
     * request for that.
     *
     * @return array<string, mixed> the `checks.contact_form` fields of vendor/bin/weft-health
     */
    public static function contactForm(): array
    {
        $mailTo = (string) Config::get('app.contact.mail_to', '');
        $mailFrom = (string) Config::get('app.contact.mail_from', '');
        $alertloop = (bool) Config::get('app.alertloop.enabled', false);
        $mailAvailable = self::mailAvailable();

        $problems = [];
        foreach (['MAIL_TO' => $mailTo, 'MAIL_FROM' => $mailFrom] as $name => $address) {
            $host = strtolower(substr((string) strrchr($address, '@'), 1));
            if ($host === 'example.com' || str_ends_with($host, '.example.com')) {
                $problems[] = $name . ' is on example.com';
            }
        }
        if ($mailTo === '' && !$alertloop) {
            $problems[] = 'no channel: MAIL_TO is empty and AlertLoop is off';
        } elseif ($mailTo !== '' && !$mailAvailable && !$alertloop) {
            $problems[] = 'mail() is not available and AlertLoop is off';
        }

        return [
            'ok' => $problems === [],
            'mail_to' => $mailTo,
            'mail_from' => $mailFrom,
            'mail_available' => $mailAvailable,
            'alertloop' => $alertloop,
            'problems' => $problems,
        ];
    }

    /**
     * Whether mail() can be called, without sending anything: the function is not disabled and,
     * outside Windows, the sendmail program it hands messages to exists. On Windows mail() talks
     * SMTP, which is only known once a message is sent.
     */
    private static function mailAvailable(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('mail') || in_array('mail', $disabled, true)) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return true;
        }
        $program = strtok(trim((string) ini_get('sendmail_path')), ' ');
        return $program !== false && is_executable($program);
    }
}
