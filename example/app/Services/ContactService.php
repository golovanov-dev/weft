<?php

declare(strict_types=1);

namespace App\Services;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Observability\Trace;

/**
 * Contact request handling — business logic kept out of the API endpoint and the view.
 *
 * A request is delivered by an AlertLoop notification (a "sensitive spot": a real person is trying
 * to reach the owner) and by an email to app.contact.mail_to through PHP mail(). It counts as
 * delivered when at least one of them confirmed it; a failed mail() and an undelivered request each
 * leave a line in the error log. Swap deliver() for SMTP/Resend/a CRM without touching the endpoint
 * or the template.
 */
final class ContactService
{
    /**
     * @param array<string, mixed> $data validated, sanitised fields
     * @return bool true when AlertLoop accepted the request or mail() sent it
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
        $mailed = $this->deliver($data);

        if (!$alerted && !$mailed) {
            ErrorHandler::log('contact', 503, 'Contact request not delivered: neither AlertLoop nor mail() accepted it', __FILE__, __LINE__);
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return bool true when mail() accepted the message; false when MAIL_TO is empty or mail() failed
     */
    private function deliver(array $data): bool
    {
        $to = (string) Config::get('app.contact.mail_to', '');
        if ($to === '') {
            return false;
        }
        if (!function_exists('mail')) {
            ErrorHandler::log('contact', 500, 'Contact email not sent: mail() is not available', __FILE__, __LINE__);
            return false;
        }

        $subject = 'Contact: ' . ($data['type'] ?? 'request');
        $body = '';
        foreach ($data as $key => $value) {
            if (is_scalar($value) && $value !== '') {
                $body .= ucfirst((string) $key) . ": " . $value . "\n";
            }
        }
        $from = (string) Config::get('app.contact.mail_from', $to);
        $headers = 'From: ' . $from . "\r\n" . 'Reply-To: ' . ($data['email'] ?? $from) . "\r\n";

        if (!@mail($to, $subject, $body, $headers)) {
            ErrorHandler::log('contact', 500, 'Contact email to ' . $to . ' not sent: mail() failed', __FILE__, __LINE__);
            return false;
        }
        return true;
    }
}
