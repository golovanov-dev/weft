<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Mail;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Mail\Mail;
use Golovanov\Weft\Mail\Smtp;
use Golovanov\Weft\Tests\TestCase;

/**
 * Mail with the smtp driver against a scripted server: the connection is one end of a socket pair
 * whose other end already holds the server's replies; what the client wrote is read back after it
 * hangs up. Not covered: a successful TLS handshake (STARTTLS, ssl://) and certificate checks;
 * a failed one runs against a plain-text server in a separate PHP.
 */
final class SmtpTest extends TestCase
{
    /** @var resource|null the server's end of the connection */
    private $server = null;

    private string $address = '';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('app.url.base', 'https://site.test');
        Config::set('app.mail', [
            'driver' => 'smtp',
            'from' => 'noreply@site.test',
            'smtp' => ['host' => 'smtp.site.test', 'encryption' => 'none', 'username' => 'user', 'password' => 'p@ss:"word', 'timeout' => 1],
        ]);
    }

    /** The server answers with $replies, in order, whatever the client sends. */
    private function serverSays(string ...$replies): void
    {
        $pair = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$client, $this->server] = $pair;
        fwrite($this->server, implode('', array_map(static fn(string $r): string => $r . "\r\n", $replies)));
        $address = &$this->address;
        $connector = static function (string $to) use ($client, &$address) {
            $address = $to;
            return $client;
        };
        (new \ReflectionClass(Smtp::class))->getProperty('connector')->setValue(null, $connector);
    }

    /** @return list<string> the lines the client sent */
    private function clientSaid(): array
    {
        return explode("\r\n", rtrim((string) stream_get_contents($this->server), "\r\n"));
    }

    public function testAMessageIsSentWithAuthPlainAndDotStuffing(): void
    {
        $this->serverSays(
            '220 smtp.site.test ESMTP',
            '250-smtp.site.test',
            '250-SIZE 10240000',
            '250 AUTH LOGIN PLAIN',
            '235 2.7.0 Accepted',
            '250 OK',
            '250 OK',
            '250 OK',
            '354 Go ahead',
            '250 2.0.0 Queued',
            '221 Bye',
        );

        $this->assertTrue(Mail::send(['a@site.test', 'b@site.test'], 'Hi', ".hidden line\n..two dots"));

        $said = $this->clientSaid();
        $this->assertSame('tcp://smtp.site.test:587', $this->address);
        $this->assertSame('EHLO site.test', $said[0]);
        $this->assertSame('AUTH PLAIN ' . base64_encode("\0user\0p@ss:\"word"), $said[1]);
        $this->assertSame(['MAIL FROM:<noreply@site.test>', 'RCPT TO:<a@site.test>', 'RCPT TO:<b@site.test>', 'DATA'], array_slice($said, 2, 4));
        $this->assertContains('..hidden line', $said);
        $this->assertContains('...two dots', $said);
        $this->assertContains('To: a@site.test, b@site.test', $said);
        $this->assertSame(['.', 'QUIT'], array_slice($said, -2));
    }

    public function testAuthLoginIsUsedWhenPlainIsNotOffered(): void
    {
        $this->serverSays('220 ready', '250-smtp.site.test', '250 AUTH=LOGIN', '334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6', '235 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye');

        $this->assertTrue(Mail::send('a@site.test', 'Hi', 'body'));

        $this->assertSame(['AUTH LOGIN', base64_encode('user'), base64_encode('p@ss:"word')], array_slice($this->clientSaid(), 1, 3));
    }

    public function testARefusedRecipientFailsWithTheStepAndTheReply(): void
    {
        $this->serverSays('220 ready', '250 AUTH PLAIN', '235 ok', '250 ok', '550-5.1.1 No such user', '550 5.1.1 Try again never');
        $logOffset = self::errorLogSize();

        $this->assertFalse(Mail::send('a@site.test', 'Hi', 'body'));

        $this->assertStringContainsString(
            'Mail not sent (smtp): SMTP RCPT TO refused: 550-5.1.1 No such user 550 5.1.1 Try again never',
            self::errorLogSince($logOffset),
        );
    }

    /** Asked for STARTTLS, the client does not fall back to sending the password in the clear. */
    public function testStartTlsThatIsNotOfferedStopsBeforeAuth(): void
    {
        Config::set('app.mail.smtp.encryption', 'starttls');
        $this->serverSays('220 ready', '250 AUTH PLAIN');
        $logOffset = self::errorLogSize();

        $this->assertFalse(Mail::send('a@site.test', 'Hi', 'body'));

        $this->assertSame(['EHLO site.test'], $this->clientSaid());
        $this->assertStringContainsString('SMTP server does not offer STARTTLS', self::errorLogSince($logOffset));
    }

    public function testASilentServerTimesOut(): void
    {
        $this->serverSays();
        $logOffset = self::errorLogSize();
        $started = microtime(true);

        $this->assertFalse(Mail::send('a@site.test', 'Hi', 'body'));

        $this->assertLessThan(3, microtime(true) - $started);
        $this->assertStringContainsString('SMTP timed out waiting for connect', self::errorLogSince($logOffset));
    }

    /**
     * A plain-text SMTP server on a real socket, in a separate PHP: it greets, offers STARTTLS and
     * then answers the TLS handshake with plain text, as a server on the wrong port does.
     */
    private const PLAIN_TEXT_SERVER = <<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
        $client = stream_socket_accept($server, 30);
        fwrite($client, "220 ready\r\n");
        while ($client && ($line = fgets($client)) !== false) {
            if (str_starts_with($line, 'EHLO')) {
                fwrite($client, "250-smtp.site.test\r\n250 STARTTLS\r\n");
            } elseif (str_starts_with($line, 'STARTTLS')) {
                fwrite($client, "220 go ahead\r\n");
                usleep(100000);
                fwrite($client, "500 this is not TLS\r\n");
            }
        }
        PHP;

    /**
     * A TLS handshake that fails keeps OpenSSL's reason, not `error 0`: implicit TLS on a
     * plain-text port, and STARTTLS answered with plain text.
     */
    public function testAFailedTlsHandshakeSaysWhy(): void
    {
        foreach (['ssl' => 'SMTP connection to ssl://127.0.0.1:', 'starttls' => 'SMTP STARTTLS handshake failed: '] as $encryption => $failure) {
            $server = proc_open([PHP_BINARY, '-r', self::PLAIN_TEXT_SERVER], [1 => ['pipe', 'w']], $pipes);
            try {
                $port = (int) fgets($pipes[1]);
                Config::set('app.mail.smtp', ['host' => '127.0.0.1', 'port' => $port, 'encryption' => $encryption, 'timeout' => 2]);
                $logOffset = self::errorLogSize();

                $this->assertFalse(Mail::send('a@site.test', 'Hi', 'body'));

                $log = self::errorLogSince($logOffset);
                $this->assertStringContainsString($failure, $log);
                $this->assertStringContainsString('SSL', $log);
                $this->assertStringNotContainsString('error 0', $log);
            } finally {
                proc_terminate($server);
                proc_close($server);
            }
        }
    }

    public function testImplicitTlsIsChosenForPort465(): void
    {
        Config::set('app.mail.smtp', ['host' => 'smtp.site.test', 'port' => 465, 'timeout' => 1]);
        $this->serverSays('220 ready', '250 ok', '250 ok', '250 ok', '354 go', '250 queued', '221 bye');

        $this->assertTrue(Mail::send('a@site.test', 'Hi', 'body'));
        $this->assertSame('ssl://smtp.site.test:465', $this->address);
    }
}
