<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Observability;

use Golovanov\Weft\Tests\TestCase;

final class ErrorHandlerTest extends TestCase
{
    /**
     * Registering an exception handler changes how PHP dies: the handler runs and the script
     * then terminates with status 0. A deploy step or cron job that died therefore looked
     * successful. The run has to end non-zero and say why on stderr.
     */
    public function testUncaughtExceptionEndsACliRunWithFailure(): void
    {
        $script = dirname(__DIR__) . '/fixtures/cli/uncaught.php';
        $this->assertFileExists($script);

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);
        $text = implode("\n", $output);

        $this->assertSame(1, $status, 'A failed CLI run must not report success. Output: ' . $text);
        $this->assertStringContainsString('Uncaught RuntimeException: boom', $text);
    }
}
