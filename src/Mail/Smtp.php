<?php

declare(strict_types=1);

namespace Golovanov\Weft\Mail;

use Golovanov\Weft\Config\Config;

/**
 * A minimal SMTP client for Mail: one message per connection, AUTH PLAIN or LOGIN, TLS from the
 * start (`ssl`, port 465) or upgraded with STARTTLS (`starttls`, the default, port 587); `none`
 * sends in the clear and only when set. The certificate is verified.
 *
 * The whole exchange, connecting included, has one deadline of app.mail.smtp.timeout seconds
 * (default 10): every wait is cut to the time left. The TLS handshake is the exception, with either
 * encryption: PHP bounds it by the full timeout on its own, so with `ssl` or `starttls` the worst
 * case is twice the timeout. Resolving the host name is bounded by no timeout at all. Any failure
 * throws RuntimeException with the step and the server's reply, or, for a failed connection or TLS
 * handshake, PHP's warning (the OpenSSL reason, such as `certificate verify failed`); Mail turns it
 * into false.
 *
 * @internal Used by Mail.
 */
final class Smtp
{
    public const ENCRYPTION = ['starttls', 'ssl', 'none'];

    /**
     * Connection seam: null opens a socket. Tests replace it (through reflection) with a scripted
     * stream. Gets the address (`ssl://host:465`, `tcp://host:587`) and the seconds to wait.
     *
     * @var null|\Closure(string, float): resource
     */
    private static ?\Closure $connector = null;

    /** @var resource */
    private $stream;

    private function __construct(private float $deadline)
    {
    }

    /**
     * @param array<string, mixed> $settings app.mail.smtp: host, port, encryption, username, password, timeout
     * @param list<string> $to
     * @param string $data the message: headers, a blank line and the body
     */
    public static function send(array $settings, string $from, array $to, string $data): void
    {
        $host = (string) ($settings['host'] ?? '');
        $port = (int) ($settings['port'] ?? 0);
        $encryption = (string) ($settings['encryption'] ?? '');
        if ($encryption === '') {
            $encryption = $port === 465 ? 'ssl' : 'starttls';
        }
        if ($port === 0) {
            $port = $encryption === 'ssl' ? 465 : 587;
        }
        $timeout = max(1.0, (float) ($settings['timeout'] ?? 10));
        $username = (string) ($settings['username'] ?? '');

        $smtp = new self(microtime(true) + $timeout);
        $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $smtp->stream = (self::$connector ?? self::connect(...))($address, $timeout);

        try {
            $smtp->expect('connect', 220);
            $features = $smtp->command('EHLO ' . self::helo(), 'EHLO', 250);
            if ($encryption === 'starttls') {
                if (!self::offers($features, 'STARTTLS')) {
                    throw new \RuntimeException('SMTP server does not offer STARTTLS; set encryption to ssl or none');
                }
                $smtp->command('STARTTLS', 'STARTTLS', 220);
                $smtp->limitWait();
                $warning = '';
                $stream = $smtp->stream;
                $secured = self::keepWarning(static fn(): mixed => stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT), $warning);
                if ($secured !== true) {
                    throw new \RuntimeException('SMTP STARTTLS handshake failed' . ($warning !== '' ? ': ' . $warning : ''));
                }
                $features = $smtp->command('EHLO ' . self::helo(), 'EHLO', 250);
            }
            if ($username !== '') {
                $smtp->authenticate($features, $username, (string) ($settings['password'] ?? ''));
            }
            $smtp->command('MAIL FROM:<' . $from . '>', 'MAIL FROM', 250);
            foreach ($to as $recipient) {
                $smtp->command('RCPT TO:<' . $recipient . '>', 'RCPT TO', 250, 251);
            }
            $smtp->command('DATA', 'DATA', 354);
            $data = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $data);
            $data = (string) preg_replace('/^\./m', '..', $data);
            $smtp->command(rtrim($data, "\r\n") . "\r\n.", 'message', 250);
            try {
                $smtp->command('QUIT', 'QUIT', 221);
            } catch (\RuntimeException) {
                // The message is accepted; how the server says goodbye does not matter.
            }
        } finally {
            @fclose($smtp->stream);
        }
    }

    /** @return resource */
    private static function connect(string $address, float $timeout)
    {
        $warning = '';
        $errno = 0;
        $errstr = '';
        $stream = self::keepWarning(static function () use ($address, $timeout, &$errno, &$errstr): mixed {
            return stream_socket_client($address, $errno, $errstr, $timeout);
        }, $warning);
        if ($stream === false) {
            // A failed TLS handshake leaves $errstr empty; its reason is only in PHP's warning.
            $reason = $errstr !== '' ? $errstr : ($warning !== '' ? $warning : 'error ' . $errno);
            throw new \RuntimeException('SMTP connection to ' . $address . ' failed: ' . $reason);
        }
        return $stream;
    }

    /**
     * Run a socket call and keep the first warning it raises, without the function name: for a
     * failed TLS handshake that warning is the only place the reason is.
     */
    private static function keepWarning(\Closure $call, string &$warning): mixed
    {
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            if ($warning === '') {
                $warning = (string) preg_replace('/^\w+\(\): /', '', $message);
            }
            return true;
        });
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    /** The name the client gives in EHLO: the host of app.url.base, or localhost. */
    private static function helo(): string
    {
        $host = (string) parse_url((string) Config::get('app.url.base', ''), PHP_URL_HOST);
        return preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1 ? $host : 'localhost';
    }

    /** @param list<string> $features the EHLO reply lines */
    private static function offers(array $features, string $keyword): bool
    {
        foreach ($features as $line) {
            if (strcasecmp(strtok(substr($line, 4), ' ') ?: '', $keyword) === 0) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $features */
    private function authenticate(array $features, string $username, string $password): void
    {
        $mechanisms = [];
        foreach ($features as $line) {
            if (preg_match('/^\d{3}[ -]AUTH[ =](.*)$/i', $line, $m) === 1) {
                $mechanisms = [...$mechanisms, ...preg_split('/\s+/', strtoupper(trim($m[1]))) ?: []];
            }
        }
        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), 'AUTH', 235);
        } elseif (in_array('LOGIN', $mechanisms, true)) {
            $this->command('AUTH LOGIN', 'AUTH', 334);
            $this->command(base64_encode($username), 'AUTH', 334);
            $this->command(base64_encode($password), 'AUTH', 235);
        } else {
            throw new \RuntimeException('SMTP server offers no AUTH PLAIN or LOGIN');
        }
    }

    /**
     * Send one command and read its reply.
     *
     * @return list<string> the reply lines
     */
    private function command(string $line, string $step, int ...$codes): array
    {
        $this->write($line . "\r\n");
        return $this->expect($step, ...$codes);
    }

    private function write(string $bytes): void
    {
        while ($bytes !== '') {
            $this->limitWait();
            $written = @fwrite($this->stream, $bytes);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('SMTP connection lost while sending');
            }
            $bytes = substr($bytes, $written);
        }
    }

    /**
     * Read a reply, all lines of a multiline one, and check its code.
     *
     * @return list<string>
     */
    private function expect(string $step, int ...$codes): array
    {
        $lines = [];
        do {
            $this->limitWait();
            $line = fgets($this->stream, 2048);
            if ($line === false) {
                throw new \RuntimeException(stream_get_meta_data($this->stream)['timed_out']
                    ? 'SMTP timed out waiting for ' . $step
                    : 'SMTP connection closed before ' . $step . ' was answered');
            }
            $lines[] = rtrim($line, "\r\n");
        } while (strlen($line) > 3 && $line[3] === '-');

        if (!in_array((int) substr($lines[0], 0, 3), $codes, true)) {
            throw new \RuntimeException('SMTP ' . $step . ' refused: ' . mb_substr(implode(' ', $lines), 0, 200));
        }
        return $lines;
    }

    /** Let the next wait last no longer than the time left. */
    private function limitWait(): void
    {
        $left = $this->deadline - microtime(true);
        if ($left <= 0) {
            throw new \RuntimeException('SMTP timed out');
        }
        stream_set_timeout($this->stream, (int) $left, (int) (($left - (int) $left) * 1_000_000));
    }
}
