<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Security;

use Golovanov\Weft\Security\Headers;
use Golovanov\Weft\Tests\TestCase;

/**
 * Emitted headers cannot be observed from the CLI SAPI (header() is a no-op there), so this
 * is only a smoke test: send() must be callable without side effects or errors in any SAPI.
 * The actual header values are exercised through the example site.
 */
final class HeadersTest extends TestCase
{
    public function testSendIsSafeToCallFromCli(): void
    {
        $this->expectNotToPerformAssertions();
        Headers::send();
        Headers::send(); // idempotent second call must not error either
    }
}
