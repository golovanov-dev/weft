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
        $check = $this->contactForm(['mail_to' => 'hi@site.test', 'mail_from' => 'noreply@site.test']);

        $this->assertSame([
            'ok' => true,
            'mail_to' => 'hi@site.test',
            'mail_from' => 'noreply@site.test',
            'mail_available' => true,
            'alertloop' => false,
            'problems' => [],
        ], $check);
    }

    public function testTemplateAddressesFailItEvenWithAlertLoop(): void
    {
        $check = $this->contactForm(['mail_to' => 'hello@example.com', 'mail_from' => 'noreply@example.com']);

        $this->assertFalse($check['ok']);
        $this->assertSame(['MAIL_TO is on example.com', 'MAIL_FROM is on example.com'], $check['problems']);

        $check = $this->contactForm(['mail_to' => 'hi@site.test', 'mail_from' => 'noreply@Mail.Example.COM'], alertloop: true);
        $this->assertSame(['MAIL_FROM is on example.com'], $check['problems']);

        $check = $this->contactForm(['mail_to' => 'hi@notexample.com', 'mail_from' => '']);
        $this->assertTrue($check['ok'], json_encode($check) ?: '');
    }

    public function testNoChannelFailsIt(): void
    {
        $check = $this->contactForm(['mail_to' => '', 'mail_from' => '']);

        $this->assertSame(['no channel: MAIL_TO is empty and AlertLoop is off'], $check['problems']);
        $this->assertTrue($this->contactForm(['mail_to' => '', 'mail_from' => ''], alertloop: true)['ok']);
    }

    public function testMailThatCannotRunFailsItWhenItIsTheOnlyChannel(): void
    {
        $contact = ['mail_to' => 'hi@site.test', 'mail_from' => 'noreply@site.test'];

        $check = $this->contactForm($contact, mail: '-d disable_functions=mail');
        $this->assertFalse($check['mail_available']);
        $this->assertSame(['mail() is not available and AlertLoop is off'], $check['problems']);

        $check = $this->contactForm($contact, alertloop: true, mail: '-d disable_functions=mail');
        $this->assertFalse($check['mail_available']);
        $this->assertTrue($check['ok']);

        if (PHP_OS_FAMILY !== 'Windows') {
            $check = $this->contactForm($contact, mail: '-d sendmail_path=' . escapeshellarg('/nonexistent/sendmail -t -i'));
            $this->assertSame(['mail() is not available and AlertLoop is off'], $check['problems']);
        }
    }

    /**
     * @param array<string, string> $contact app.contact
     * @param string|null $mail php -d options; null: a sendmail_path to a program that exists
     * @return array<string, mixed>
     */
    private function contactForm(array $contact, bool $alertloop = false, ?string $mail = null): array
    {
        $root = dirname(__DIR__, 2);
        $app = ['contact' => $contact, 'alertloop' => ['enabled' => $alertloop]];
        $dir = $this->makeTempDir('weft-contact-check-');
        file_put_contents($dir . '/check.php', '<?php'
            . "\nrequire " . var_export($root . '/vendor/autoload.php', true) . ';'
            . "\nrequire " . var_export($root . '/example/app/Bootstrap.php', true) . ';'
            . "\nGolovanov\\Weft\\Config\\Config::set('app', " . var_export($app, true) . ');'
            . "\necho json_encode(App\\Bootstrap::contactForm());\n");
        $mail ??= '-d sendmail_path=' . escapeshellarg(PHP_BINARY);

        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . $mail . ' ' . escapeshellarg($dir . '/check.php'));
        $this->removeDir($dir);
        $check = json_decode((string) $output, true);
        $this->assertIsArray($check, (string) $output);
        return $check;
    }
}
