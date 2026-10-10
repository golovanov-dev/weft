<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Security\Headers;
use Golovanov\Weft\Tests\TestCase;

/**
 * The header lines send() emits. That they reach the response is checked over HTTP by the smoke test
 * of the example site (tests/smoke/run.php): the CLI SAPI sends no headers.
 */
final class HeadersTest extends TestCase
{
    public function testHstsIsOffUntilEnabledAndThenCoversOnlyTheHost(): void
    {
        $this->assertSame([], preg_grep('/^Strict-Transport-Security:/', Headers::lines()));

        Config::set('app.security.hsts', true);
        $this->assertContains('Strict-Transport-Security: max-age=31536000', Headers::lines());
    }

    public function testHstsLifetimeAndSubdomainsComeFromConfig(): void
    {
        Config::set('app.security', ['hsts' => true, 'hsts_max_age' => 600, 'hsts_subdomains' => true]);

        $this->assertContains('Strict-Transport-Security: max-age=600; includeSubDomains', Headers::lines());
    }
}
