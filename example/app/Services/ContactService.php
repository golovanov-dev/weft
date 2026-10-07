<?php

declare(strict_types=1);

namespace App\Services;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\Alert;
use Golovanov\Weft\Observability\Trace;

/**
 * Contact request handling — business logic kept out of the API endpoint and the view.
 *
 * For a simple site this records the request to the trace log and raises an AlertLoop
 * notification (a "sensitive spot": a real person is trying to reach the owner). Swap the
 * deliver() body for SMTP/Resend/a CRM when needed without touching the endpoint or template.
 */
final class ContactService
{
    /**
     * @param array<string, mixed> $data validated, sanitised fields
     */
    public function submit(array $data): bool
    {
        // No personal fields in the trace: the visitor's contact details go only to the owner.
        Trace::event('contact_submit', [
            'type' => $data['type'] ?? null,
            'has_repo' => !empty($data['repository']),
        ]);

        Alert::send(
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

        return $this->deliver($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function deliver(array $data): bool
    {
        $to = (string) Config::get('app.contact.mail_to', '');
        if ($to === '' || !function_exists('mail')) {
            // No mail transport wired: the alert + trace above are the record of the request.
            return true;
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

        return @mail($to, $subject, $body, $headers);
    }
}
