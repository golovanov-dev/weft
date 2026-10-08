<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Observability;

use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Support\Clock;
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

    public function testLogDirectoryAndFileAreGroupWritableWhateverTheUmask(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX file modes');
        }
        $dir = sys_get_temp_dir() . '/weft-logs/errors';
        $this->removeDir($dir);

        $previous = umask(0077);
        try {
            ErrorHandler::log('test', 0, 'mode check', __FILE__, __LINE__);
        } finally {
            umask($previous);
        }

        clearstatcache();
        $this->assertSame('2770', decoct(fileperms($dir) & 07777));
        $this->assertSame('660', decoct(fileperms($dir . '/' . Clock::date('Y-m-d') . '.log') & 0777));
    }

    public function testLogsErrorNamesTodaysLogFilePhpCannotWrite(): void
    {
        ErrorHandler::log('test', 0, 'make sure the file exists', __FILE__, __LINE__);
        $this->assertNull(ErrorHandler::logsError());

        $file = sys_get_temp_dir() . '/weft-logs/errors/' . Clock::date('Y-m-d') . '.log';
        chmod($file, 0444);
        try {
            $error = ErrorHandler::logsError();
        } finally {
            chmod($file, 0660);
        }
        $this->assertStringContainsString(Clock::date('Y-m-d') . '.log', (string) $error);
    }
}
