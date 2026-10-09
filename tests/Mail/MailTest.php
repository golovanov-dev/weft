<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Mail;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Mail\Mail;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Tests\TestCase;

/**
 * Mail::send(): the message it builds and what it does when it cannot send. The transport is
 * replaced (fakeMail), so nothing leaves the process; the SMTP dialogue is SmtpTest's.
 */
final class MailTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.mail', ['driver' => 'mail', 'from' => 'noreply@site.test']);
        $this->accept('');
    }

    private function accept(string $error): void
    {
        $this->sent = [];
        $sent = &$this->sent;
        self::fakeMail(static function (array $message) use (&$sent, $error): string {
            $sent[] = $message;
            return $error;
        });
    }

    public function testUtf8SubjectNameAndBodyAreEncoded(): void
    {
        Config::set('app.mail.from_name', 'Сайт "Дуб"');
        $subject = 'Заявка с сайта: ' . str_repeat('очень длинная тема ', 4);

        $this->assertTrue(Mail::send(['a@site.test', 'b@site.test'], $subject, "Привет\n.точка в начале строки", 'ann@example.com'));

        $message = $this->sent[0];
        $this->assertSame(['a@site.test', 'b@site.test'], $message['to']);
        $this->assertStringStartsWith('=?UTF-8?B?', $message['subject']);
        $this->assertStringContainsString("?=\r\n =?UTF-8?B?", $message['subject'], 'a long subject is folded');
        $this->assertSame(trim($subject), mb_decode_mimeheader($message['subject']));
        $this->assertMatchesRegularExpression('/^From: =\?UTF-8\?B\?[^\r\n]+\?= <noreply@site\.test>\r$/m', $message['headers']);
        $this->assertStringContainsString("Reply-To: ann@example.com\r\n", $message['headers']);
        $this->assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable", $message['headers']);
        $this->assertSame("Привет\r\n.точка в начале строки", quoted_printable_decode($message['body']));
    }

    /** A line break in the subject or a name cannot start a header of its own. */
    public function testLineBreaksCannotAddHeaders(): void
    {
        Config::set('app.mail.from_name', "Site\r\nBcc: victim@evil.test");

        $this->assertTrue(Mail::send('a@site.test', "Hello\r\nBcc: victim@evil.test", 'body'));

        $this->assertSame('Hello Bcc: victim@evil.test', $this->sent[0]['subject']);
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $this->sent[0]['headers']);
        $this->assertStringContainsString('From: "Site Bcc: victim@evil.test" <noreply@site.test>', $this->sent[0]['headers']);
    }

    public function testAnAddressWithALineBreakIsRefusedAndNothingIsSent(): void
    {
        $logOffset = self::errorLogSize();

        $this->assertFalse(Mail::send("a@site.test\r\nBcc: victim@evil.test", 'Hi', 'body'));
        $this->assertFalse(Mail::send('a@site.test', 'Hi', 'body', "ann@example.com\nBcc: victim@evil.test"));

        $this->assertSame([], $this->sent);
        $log = self::errorLogSince($logOffset);
        $this->assertStringContainsString('Mail not sent (mail): a recipient is not an email address', $log);
        $this->assertStringContainsString('Mail not sent (mail): the reply-to is not an email address', $log);
        $this->assertStringNotContainsString('victim', $log);
    }

    public function testSettingsThatCannotSendAreReported(): void
    {
        Config::set('app.mail', ['driver' => 'smtp', 'from' => '', 'smtp' => ['encryption' => 'tls']]);

        $this->assertSame([
            'app.mail.from is empty',
            'app.mail.smtp.host is empty',
            'app.mail.smtp.encryption is not starttls, ssl or none',
        ], Mail::configErrors());
        $this->assertFalse(Mail::send('a@site.test', 'Hi', 'body'));
        $this->assertSame([], $this->sent);

        Config::set('app.mail', ['driver' => 'sendgrid', 'from' => 'noreply@site.test']);
        $this->assertSame(['app.mail.driver is not mail or smtp'], Mail::configErrors());
    }

    /** The trace gets metadata only; the error log gets the reason. Neither gets the subject or the body. */
    public function testARefusedMessageIsTracedWithoutItsContent(): void
    {
        $traceId = Trace::boot();
        $this->accept('SMTP RCPT TO refused: 550 no such user');
        $logOffset = self::errorLogSize();

        $this->assertFalse(Mail::send('a@site.test', 'Secret subject', 'Secret body'));

        $events = array_values(array_filter(
            $this->traceEvents($traceId),
            static fn(array $e): bool => ($e['event'] ?? $e['type'] ?? null) === 'mail_out',
        ));
        $this->assertCount(1, $events);
        $data = $events[0]['data'];
        $this->assertSame(['driver', 'recipients', 'size', 'ok', 'error'], array_keys($data));
        $this->assertSame(['mail', 1, false, 'SMTP RCPT TO refused: 550 no such user'], [$data['driver'], $data['recipients'], $data['ok'], $data['error']]);
        $this->assertGreaterThan(0, $data['size']);
        $encoded = (string) json_encode($events);
        $this->assertStringNotContainsString('Secret', $encoded);
        $this->assertStringNotContainsString('a@site.test', $encoded);
        $this->assertStringContainsString('Mail not sent (mail): SMTP RCPT TO refused: 550 no such user', self::errorLogSince($logOffset));
    }

    public function testAThrowingTransportNeverEscapes(): void
    {
        self::fakeMail(static function (): string {
            throw new \RuntimeException('socket exploded');
        });
        $logOffset = self::errorLogSize();

        $this->assertFalse(Mail::send('a@site.test', 'Hi', 'body'));
        $this->assertStringContainsString('Mail not sent (mail): socket exploded', self::errorLogSince($logOffset));
    }
}
