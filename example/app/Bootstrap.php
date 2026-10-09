<?php

declare(strict_types=1);

namespace App;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Mail\Mail;
use Golovanov\Weft\Observability\Alert;
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
     * The contact form needs a channel: an email to a real MAIL_TO (one plain address, or several
     * separated by commas), or an alert channel that reaches a person; with the mail driver, the
     * alert channel `mail` counts only while mail() is available. It checks the settings and, with the mail driver,
     * whether PHP can hand mail() a message, not that an email arrives: send a test request for that.
     *
     * @return array<string, mixed> the `checks.contact_form` fields of vendor/bin/weft-health
     */
    public static function contactForm(): array
    {
        $mailTo = (string) Config::get('app.contact.mail_to', '');
        $recipients = self::recipients($mailTo);
        $mailFrom = (string) Config::get('app.mail.from', '');
        $driver = (string) Config::get('app.mail.driver', 'mail');
        $mailAvailable = $driver !== 'mail' || self::mailAvailable();
        $mailErrors = Mail::configErrors();
        $alertChannels = Alert::deliveryChannels();
        if (!$mailAvailable) {
            // The alert channel mail sends through the same mail().
            $alertChannels = array_values(array_diff($alertChannels, ['mail']));
        }

        $problems = [];
        foreach (['MAIL_TO' => $recipients, 'MAIL_FROM' => [$mailFrom]] as $name => $addresses) {
            foreach ($addresses as $address) {
                $host = strtolower(substr((string) strrchr($address, '@'), 1));
                if ($host === 'example.com' || str_ends_with($host, '.example.com')) {
                    $problems[] = $name . ' is on example.com';
                    break;
                }
            }
        }
        $mailProblem = match (true) {
            $recipients === [] => 'MAIL_TO is empty',
            array_filter($recipients, static fn(string $a): bool => !Mail::isAddress($a)) !== [] => 'MAIL_TO is not an email address',
            $mailErrors !== [] => implode('; ', $mailErrors),
            !$mailAvailable => 'mail() is not available',
            default => null,
        };
        if ($mailProblem !== null && $alertChannels === []) {
            $problems[] = 'no channel: ' . $mailProblem . ' and no alert channel reaches a person';
        }

        return [
            'ok' => $problems === [],
            'mail_to' => $mailTo,
            'mail_from' => $mailFrom,
            'mail_driver' => $driver,
            'mail_available' => $mailAvailable,
            'alert_channels' => $alertChannels,
            'problems' => $problems,
        ];
    }

    /**
     * MAIL_TO as a list: one address or several separated by commas, each a plain address.
     *
     * @return list<string>
     */
    private static function recipients(string $mailTo): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $mailTo)), static fn(string $a): bool => $a !== ''));
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
