<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Security\Headers;
use Golovanov\Weft\Tests\TestCase;

/**
 * Emitted headers cannot be observed from the CLI SAPI (header() is a no-op there): send() is only
 * checked to be safe to call, the values are read from lines(), which send() emits.
 */
final class HeadersTest extends TestCase
{
    public function testSendIsSafeToCallFromCli(): void
    {
        $this->expectNotToPerformAssertions();
        Headers::send();
        Headers::send(); // idempotent second call must not error either
    }

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
