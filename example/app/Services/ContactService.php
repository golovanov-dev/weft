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
 * commas; Mail, app.mail.*), and only by it: an undelivered request leaves a line in the error log.
 * The alert channels in app.alerts.channels are told that a request came and whether the email was
 * accepted, with no form data in the alert (a channel such as Telegram is no place for a visitor's
 * details). Swap the delivery for a CRM without touching the endpoint or the template.
 */
final class ContactService
{
    /**
     * @param array<string, mixed> $data validated, sanitised fields
     * @return bool true when the email was accepted
     */
    public function submit(array $data): bool
    {
        Trace::event('contact_submit', [
            'type' => $data['type'] ?? null,
            'email' => $data['email'] ?? null,
            'has_repo' => !empty($data['project_url']),
        ]);

        $mailed = $this->email($data);
        // No dedupe key: every request is announced. The trace id finds it in the logs.
        Alert::send(
            message: $mailed ? 'New contact request' : 'Contact request not delivered',
            severity: $mailed ? Alert::INFO : Alert::CRITICAL,
            type: 'business_event',
        );

        if (!$mailed) {
            ErrorHandler::log('contact', 503, 'Contact request not delivered: the email was not accepted', __FILE__, __LINE__);
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
