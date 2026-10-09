<?php

declare(strict_types=1);

namespace App\Services;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Mail\Mail;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Observability\Trace;

/**
 * Contact request handling — business logic kept out of the API endpoint and the view.
 *
 * A request is delivered by an email to app.contact.mail_to (one address or several separated by
 * commas; Mail, app.mail.*) and by an alert to the channels in app.alerts.channels (a "sensitive
 * spot": a real person is trying to reach the owner). It counts as delivered when the email was accepted or the alert reached a person; an
 * undelivered request leaves a line in the error log, as does a failed email. Swap the delivery
 * for a CRM without touching the endpoint or the template.
 */
final class ContactService
{
    /**
     * @param array<string, mixed> $data validated, sanitised fields
     * @return bool true when the email was accepted or the alert reached a person
     */
    public function submit(array $data): bool
    {
        Trace::event('contact_submit', [
            'type' => $data['type'] ?? null,
            'email' => $data['email'] ?? null,
            'has_repo' => !empty($data['project_url']),
        ]);

        // No dedupe key: every request must arrive, and a repeated key would be held back.
        $alerted = Alert::send(
            message: sprintf(
                "New contact request (%s) from %s <%s>",
                $data['type'] ?? 'other',
                $data['name'] ?? '',
                $data['email'] ?? '',
            ),
            severity: Alert::INFO,
            type: 'business_event',
            context: $data,
        );
        $mailed = $this->email($data);

        if (!$alerted && !$mailed) {
            ErrorHandler::log('contact', 503, 'Contact request not delivered: no email was accepted and no alert reached a person', __FILE__, __LINE__);
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return bool true when Mail accepted the email; false when MAIL_TO is empty or sending failed
     */
    private function email(array $data): bool
    {
        $to = (string) Config::get('app.contact.mail_to', '');
        if ($to === '') {
            return false;
        }

        $body = '';
        foreach ($data as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $body .= ucfirst((string) $key) . ': ' . $value . "\n";
            }
        }
        $email = (string) ($data['email'] ?? '');

        return Mail::send(explode(',', $to), 'Contact: ' . ($data['type'] ?? 'request'), $body, $email !== '' ? $email : null);
    }
}
