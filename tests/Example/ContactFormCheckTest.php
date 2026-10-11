<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Example;

use App\Bootstrap;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Tests\TestCase;

/**
 * The example's `contact_form` health check (App\Bootstrap::contactForm()): a request is delivered
 * only by email, so MAIL_TO must hold real plain addresses.
 */
final class ContactFormCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../example/app/Bootstrap.php';
    }

    public function testRealAddressesAreOk(): void
    {
        Config::set('app.contact.mail_to', 'hi@site.test, sales@site.test');

        $this->assertSame(
            ['ok' => true, 'mail_to' => 'hi@site.test, sales@site.test', 'problems' => []],
            Bootstrap::contactForm(),
        );
    }

    public function testEmptyMailToFailsItWhateverTheAlertChannels(): void
    {
        Config::set('app.contact.mail_to', '');
        Config::set('app.alerts.channels', ['alertloop']);
        Config::set('app.alertloop', ['base_url' => 'https://alerts.site.test', 'api_key' => 'k']);

        $this->assertSame(['MAIL_TO is empty'], Bootstrap::contactForm()['problems']);
    }

    /** Mail sends only to plain addresses, and the template's address reaches nobody. */
    public function testAnAddressThatIsNotPlainOrOnExampleComFailsIt(): void
    {
        Config::set('app.contact.mail_to', 'hi@site.test, Sales <sales@site.test>');
        $this->assertSame(['MAIL_TO is not an email address'], Bootstrap::contactForm()['problems']);

        Config::set('app.contact.mail_to', 'hi@site.test, hello@Mail.Example.COM');
        $this->assertSame(['MAIL_TO is on example.com'], Bootstrap::contactForm()['problems']);
    }
}
