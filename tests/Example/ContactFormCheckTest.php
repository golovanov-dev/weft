<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Example;

use Golovanov\Weft\Tests\TestCase;

/**
 * The example's `contact_form` health check (App\Bootstrap::contactForm()), run in a separate PHP
 * whose mail() is switched on or off with -d: disable_functions=mail, or sendmail_path pointing at
 * a program that exists or not (Windows does not look at sendmail_path).
 */
final class ContactFormCheckTest extends TestCase
{
    public function testARealAddressWithWorkingMailIsOk(): void
    {
        $check = $this->contactForm('hi@site.test', 'noreply@site.test');

        $this->assertSame([
            'ok' => true,
            'mail_to' => 'hi@site.test',
            'mail_from' => 'noreply@site.test',
            'mail_driver' => 'mail',
            'mail_available' => true,
            'alert_channels' => [],
            'problems' => [],
        ], $check);
    }

    public function testTemplateAddressesFailItEvenWithAnAlertChannel(): void
    {
        $check = $this->contactForm('hello@example.com', 'noreply@example.com');

        $this->assertFalse($check['ok']);
        $this->assertSame(['MAIL_TO is on example.com', 'MAIL_FROM is on example.com'], $check['problems']);

        $check = $this->contactForm('hi@site.test', 'noreply@Mail.Example.COM', alertloop: true);
        $this->assertSame(['MAIL_FROM is on example.com'], $check['problems']);
        $this->assertSame(['alertloop'], $check['alert_channels']);
    }

    public function testNoChannelFailsIt(): void
    {
        $check = $this->contactForm('', 'noreply@site.test');

        $this->assertSame(['no channel: MAIL_TO is empty and no alert channel reaches a person'], $check['problems']);
        $this->assertTrue($this->contactForm('', 'noreply@site.test', alertloop: true)['ok']);
    }

    /** MAIL_TO takes addresses separated by commas; each must be plain, as Mail sends only to those. */
    public function testMailToIsOneOrSeveralPlainAddresses(): void
    {
        $this->assertTrue($this->contactForm('hi@site.test, sales@site.test', 'noreply@site.test')['ok']);

        $check = $this->contactForm('hi@site.test, Sales <sales@site.test>', 'noreply@site.test');
        $this->assertSame(['MAIL_TO is not an email address'], $check['problems']);
        // An alert channel does not make up for it: the MAIL_TO inbox would get nothing.
        $this->assertFalse($this->contactForm('Sales <sales@site.test>', 'noreply@site.test', alertloop: true)['ok']);

        $this->assertSame(['MAIL_TO is on example.com'], $this->contactForm('hi@site.test, hello@example.com', 'noreply@site.test')['problems']);
    }

    public function testMailSettingsThatCannotSendFailItWhenMailIsTheOnlyChannel(): void
    {
        $check = $this->contactForm('hi@site.test', 'noreply@site.test', driver: 'smtp');
        $this->assertSame(['no channel: app.mail.smtp.host is empty and no alert channel reaches a person'], $check['problems']);

        $check = $this->contactForm('hi@site.test', 'noreply@site.test', php: '-d disable_functions=mail');
        $this->assertFalse($check['mail_available']);
        $this->assertSame(['no channel: mail() is not available and no alert channel reaches a person'], $check['problems']);

        $check = $this->contactForm('hi@site.test', 'noreply@site.test', alertloop: true, php: '-d disable_functions=mail');
        $this->assertTrue($check['ok']);

        // The alert channel mail goes through the same mail(), so it is no channel either.
        $check = $this->contactForm('', 'noreply@site.test', alertMailTo: 'ops@site.test', php: '-d disable_functions=mail');
        $this->assertSame([], $check['alert_channels']);
        $this->assertSame(['no channel: MAIL_TO is empty and no alert channel reaches a person'], $check['problems']);
        $this->assertSame(['mail'], $this->contactForm('', 'noreply@site.test', alertMailTo: 'ops@site.test')['alert_channels']);

        if (PHP_OS_FAMILY !== 'Windows') {
            $check = $this->contactForm('hi@site.test', 'noreply@site.test', php: '-d sendmail_path=' . escapeshellarg('/nonexistent/sendmail -t -i'));
            $this->assertFalse($check['mail_available']);
        }
    }

    /**
     * @param string|null $php php -d options; null: a sendmail_path to a program that exists
     * @param string $alertMailTo '' or the address of the alert channel mail
     * @return array<string, mixed>
     */
    private function contactForm(string $mailTo, string $mailFrom, string $driver = 'mail', bool $alertloop = false, ?string $php = null, string $alertMailTo = ''): array
    {
        $root = dirname(__DIR__, 2);
        $app = [
            'contact' => ['mail_to' => $mailTo],
            'mail' => ['driver' => $driver, 'from' => $mailFrom],
            'alerts' => ['channels' => [...($alertloop ? ['alertloop'] : []), ...($alertMailTo !== '' ? ['mail'] : [])], 'mail_to' => $alertMailTo],
            'alertloop' => ['base_url' => 'https://alerts.site.test', 'api_key' => 'k'],
        ];
        $dir = $this->makeTempDir('weft-contact-check-');
        file_put_contents($dir . '/check.php', '<?php'
            . "\nrequire " . var_export($root . '/vendor/autoload.php', true) . ';'
            . "\nrequire " . var_export($root . '/example/app/Bootstrap.php', true) . ';'
            . "\nGolovanov\Weft\Config\Config::set('app', " . var_export($app, true) . ');'
            . "\necho json_encode(App\Bootstrap::contactForm());\n");
        $php ??= '-d sendmail_path=' . escapeshellarg(PHP_BINARY);

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . $php . ' ' . escapeshellarg($dir . '/check.php'));
        $this->removeDir($dir);
        $check = json_decode((string) $output, true);
        $this->assertIsArray($check, (string) $output);
        return $check;
    }
}
