<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Database;

use Golovanov\Weft\Database\Database;
use Golovanov\Weft\Http\ServiceUnavailable;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Failures a real MySQL cannot be made to give, on a PDO that talks to no server. A lost connection,
 * in a transaction or not, is tested on a real one: DatabaseIntegrationTest. The next call would
 * open a real PDO, so the dropped connection is looked at through reflection.
 */
final class DatabaseTest extends TestCase
{
    public function testFailedRollbackKeepsTheOriginalExceptionAndDropsTheConnection(): void
    {
        $pdo = new FakePdo();
        $db = $pdo->install();
        $pdo->rollBackFailure = new \PDOException('rollback failed');
        $logOffset = self::errorLogSize();
        $original = new \DomainException('work failed');

        try {
            $db->transaction(static function () use ($original): never {
                throw $original;
            });
            $this->fail('The exception of the work must be rethrown.');
        } catch (\Throwable $e) {
            $this->assertSame($original, $e);
        }
        $this->assertStringContainsString('Database rollback failed: rollback failed', self::errorLogSince($logOffset));
        $this->assertNull(self::connection($db));
    }

    #[RunInSeparateProcess]
    public function testConnectionErrorIsServiceUnavailableAndAlertsUnderItsOwnKey(): void
    {
        // An unknown charset fails in the client, before any network access.
        define('DB_CHARSET', 'no-such-charset');
        $pdo = new FakePdo();
        $db = $pdo->install();
        $pdo->prepareFailure = new \PDOException('syntax error');

        try {
            $db->execute('SELEKT 1');
            $this->fail('A failed query must throw.');
        } catch (\RuntimeException $query) {
        }
        (new \ReflectionClass(Database::class))->setStaticPropertyValue('instance', null);
        try {
            Database::connect();
            $this->fail('A failed connection must throw.');
        } catch (\RuntimeException $connection) {
        }

        $this->assertSame('Database connection error', $connection->getMessage());
        // The Kernel answers a failed connection with 503; a failed query stays a 500.
        $this->assertInstanceOf(ServiceUnavailable::class, $connection);
        $this->assertNotInstanceOf(ServiceUnavailable::class, $query);
        // ErrorHandler::alertException() keys the alert by the file and line of the exception.
        $this->assertNotSame($query->getFile() . $query->getLine(), $connection->getFile() . $connection->getLine());
    }

    private static function connection(Database $db): mixed
    {
        return (new \ReflectionProperty(Database::class, 'pdo'))->getValue($db);
    }
}
