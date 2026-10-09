<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Database;

use Golovanov\Weft\Database\Database;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Failures on a PDO that talks to no server. Reconnecting needs a real one: DatabaseIntegrationTest.
 * After a lost connection the next call would open a real PDO, so these tests look at the dropped
 * connection through reflection instead.
 */
final class DatabaseTest extends TestCase
{
    public function testLostConnectionThrowsAndDropsTheConnection(): void
    {
        $pdo = new FakePdo();
        $db = $pdo->install();
        $pdo->prepareFailures = [FakePdo::lostConnection()];

        try {
            $db->execute('INSERT INTO t VALUES (1)');
            $this->fail('A lost connection must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database query failed', $e->getMessage());
            $this->assertSame(2006, $e->getCode());
        }
        $this->assertSame([], $pdo->statements);
        $this->assertNull(self::connection($db));
    }

    public function testLostConnectionInATransactionThrowsWithoutRepeatingTheStatement(): void
    {
        $pdo = new FakePdo();
        $db = $pdo->install();
        $pdo->prepareFailures = [FakePdo::lostConnection()];

        try {
            $db->transaction(static fn(Database $db): int => $db->execute('INSERT INTO t VALUES (1)'));
            $this->fail('A lost connection in a transaction must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database connection lost in a transaction', $e->getMessage());
            $this->assertSame(2006, $e->getCode());
        }
        $this->assertSame([], $pdo->statements);
    }

    public function testCaughtLossInATransactionKeepsEveryLaterCallFailing(): void
    {
        $pdo = new FakePdo();
        $db = $pdo->install();
        $pdo->prepareFailures = [null, FakePdo::lostConnection()];
        $later = null;

        try {
            $db->transaction(static function (Database $db) use (&$later): void {
                $db->execute("INSERT INTO t VALUES ('a')");
                try {
                    $db->execute("INSERT INTO t VALUES ('b')");
                } catch (\RuntimeException) {
                    // The callback swallows the loss and goes on.
                }
                try {
                    $db->execute("INSERT INTO t VALUES ('c')");
                } catch (\RuntimeException $e) {
                    $later = $e;
                }
            });
            $this->fail('A lost connection in a transaction must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database connection lost in a transaction', $e->getMessage());
        }
        $this->assertSame('Database connection lost in a transaction', $later?->getMessage());
        $this->assertSame([["INSERT INTO t VALUES ('a')", []]], $pdo->statements);
        $this->assertNull(self::connection($db));
        $this->assertNull((new \ReflectionProperty(Database::class, 'lostInTransaction'))->getValue($db));
    }

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
    public function testConnectionErrorAndQueryErrorAlertUnderDifferentKeys(): void
    {
        // An unknown charset fails in the client, before any network access.
        define('DB_CHARSET', 'no-such-charset');
        $pdo = new FakePdo();
        $db = $pdo->install();
        $pdo->prepareFailures = [new \PDOException('syntax error')];

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
        // ErrorHandler::alertException() keys the alert by the file and line of the exception.
        $this->assertNotSame($query->getFile() . $query->getLine(), $connection->getFile() . $connection->getLine());
    }

    private static function connection(Database $db): mixed
    {
        return (new \ReflectionProperty(Database::class, 'pdo'))->getValue($db);
    }
}
