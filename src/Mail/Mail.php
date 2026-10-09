<?php

declare(strict_types=1);

namespace Golovanov\Weft\Mail;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Support\Clock;

/**
 * Plain-text email in UTF-8, sent by the driver in app.mail.driver: `mail` hands it to PHP mail(),
 * `smtp` talks to the server in app.mail.smtp (see Smtp).
 *
 * send() never throws. A message that is not sent leaves a line in the error log and send()
 * returns false. The trace gets one `mail_out` event per message, metadata only: driver, number of
 * recipients, size, outcome and error — never the subject, the body or an address. Addresses must
 * be plain (`name@example.com`); a line break in the subject or a name becomes a space, so neither
 * can add a header.
 */
final class Mail
{
    public const DRIVERS = ['mail', 'smtp'];

    /**
     * Transport seam: null sends for real. Tests replace it (through reflection) to see the message
     * without sending it. Gets the driver, the envelope sender, the recipients and the parts mail()
     * takes; `headers` lacks To and Subject, which are separate. Returns '' when the message was
     * accepted, otherwise the error.
     *
     * @var null|\Closure(array{driver: string, from: string, to: list<string>, subject: string, headers: string, body: string}): string
     */
    private static ?\Closure $transport = null;

    /**
     * @param string|list<string> $to one address or several
     * @return bool true when the driver accepted the message: mail() returned true, or the SMTP
     *   server answered 250 to its data. Acceptance is not delivery to the mailbox.
     */
    public static function send(string|array $to, string $subject, string $body, ?string $replyTo = null): bool
    {
        $driver = (string) Config::get('app.mail.driver', 'mail');
        $to = array_values(array_filter(array_map(trim(...), (array) $to), static fn(string $a): bool => $a !== ''));
        $size = 0;

        try {
            $error = self::problem($to, $replyTo);
            if ($error === '') {
                $message = self::compose($driver, $to, $subject, $body, $replyTo);
                $size = strlen($message['headers']) + strlen($message['body']);
                $error = (self::$transport ?? self::deliver(...))($message);
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $error = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $error));
        self::record($driver, count($to), $size, $error);
        return $error === '';
    }

    /**
     * What is wrong with the app.mail settings; empty when send() can try to send.
     *
     * @return list<string>
     */
    public static function configErrors(): array
    {
        $errors = [];
        $driver = Config::get('app.mail.driver', 'mail');
        if (!in_array($driver, self::DRIVERS, true)) {
            $errors[] = 'app.mail.driver is not mail or smtp';
        }
        $from = (string) Config::get('app.mail.from', '');
        if (!self::isAddress($from)) {
            $errors[] = $from === '' ? 'app.mail.from is empty' : 'app.mail.from is not an email address';
        }
        if ($driver === 'smtp') {
            if ((string) Config::get('app.mail.smtp.host', '') === '') {
                $errors[] = 'app.mail.smtp.host is empty';
            }
            if (!in_array((string) Config::get('app.mail.smtp.encryption', ''), ['', ...Smtp::ENCRYPTION], true)) {
                $errors[] = 'app.mail.smtp.encryption is not starttls, ssl or none';
            }
        }
        return $errors;
    }

    /** A plain address mail servers accept; anything with a line break or a name is not. */
    public static function isAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @param list<string> $to */
    private static function problem(array $to, ?string $replyTo): string
    {
        $errors = self::configErrors();
        if ($errors !== []) {
            return implode('; ', $errors);
        }
        if ($to === []) {
            return 'no recipient';
        }
        foreach ($to as $address) {
            if (!self::isAddress($address)) {
                return 'a recipient is not an email address';
            }
        }
        if ($replyTo !== null && !self::isAddress($replyTo)) {
            return 'the reply-to is not an email address';
        }
        return '';
    }

    /**
     * @param list<string> $to
     * @return array{driver: string, from: string, to: list<string>, subject: string, headers: string, body: string}
     */
    private static function compose(string $driver, array $to, string $subject, string $body, ?string $replyTo): array
    {
        $from = (string) Config::get('app.mail.from', '');
        $headers = [
            'Date: ' . Clock::now()->format(DATE_RFC2822),
            'From: ' . self::mailbox($from, (string) Config::get('app.mail.from_name', '')),
        ];
        if ($replyTo !== null) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr((string) strrchr($from, '@'), 1) . '>';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';

        return [
            'driver' => $driver,
            'from' => $from,
            'to' => $to,
            'subject' => self::encode(self::oneLine($subject)),
            'headers' => implode("\r\n", $headers),
            'body' => quoted_printable_encode((string) preg_replace('/\r\n|\r|\n/', "\r\n", $body)),
        ];
    }

    /** @param array{driver: string, from: string, to: list<string>, subject: string, headers: string, body: string} $message */
    private static function deliver(array $message): string
    {
        if ($message['driver'] === 'smtp') {
            $data = 'To: ' . implode(', ', $message['to']) . "\r\n"
                . 'Subject: ' . $message['subject'] . "\r\n"
                . $message['headers'] . "\r\n\r\n"
                . $message['body'];
            Smtp::send((array) Config::get('app.mail.smtp', []), $message['from'], $message['to'], $data);
            return '';
        }
        if (!function_exists('mail')) {
            return 'mail() is not available';
        }
        return @mail(implode(', ', $message['to']), $message['subject'], $message['body'], $message['headers'])
            ? ''
            : 'mail() failed';
    }

    private static function mailbox(string $address, string $name): string
    {
        $name = self::oneLine($name);
        if ($name === '') {
            return $address;
        }
        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1) {
            return '"' . addcslashes($name, '"\\') . '" <' . $address . '>';
        }
        return self::encode($name) . ' <' . $address . '>';
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $text));
    }

    /** RFC 2047: ASCII as it is, anything else as base64 encoded words of whole UTF-8 characters. */
    private static function encode(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $text) === 1) {
            return $text;
        }
        $words = [];
        $chunk = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($text) as $char) {
            if (strlen($chunk . $char) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        return implode("\r\n ", $words);
    }

    private static function record(string $driver, int $recipients, int $size, string $error): void
    {
        if ($error !== '') {
            ErrorHandler::log('mail', 500, 'Mail not sent (' . $driver . '): ' . mb_substr($error, 0, 300), __FILE__, __LINE__);
        }
        if (!Trace::isActive()) {
            return;
        }
        try {
            Trace::event('mail_out', [
                'driver' => $driver,
                'recipients' => $recipients,
                'size' => $size,
                'ok' => $error === '',
                'error' => $error === '' ? null : mb_substr($error, 0, 200),
            ]);
        } catch (\Throwable) {
            // Tracing is best-effort; the error log already has the failure.
        }
    }
}
