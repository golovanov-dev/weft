<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Database;

use Golovanov\Weft\Database\Database;
use Golovanov\Weft\Database\Model;
use Golovanov\Weft\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * Database against a real MySQL server, on port 3306 of WEFT_TEST_DB_HOST (CONTRIBUTING.md).
 * Without that variable every test here is skipped.
 */
#[Group('integration')]
final class DatabaseIntegrationTest extends TestCase
{
    /** Another connection of the same user: it kills the one under test and checks what was written. */
    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $host = (string) getenv('WEFT_TEST_DB_HOST');
        if ($host === '') {
            $this->markTestSkipped('Set WEFT_TEST_DB_HOST to run the integration tests.');
        }
        $name = (string) (getenv('WEFT_TEST_DB_NAME') ?: 'weft_test');
        $user = (string) (getenv('WEFT_TEST_DB_USER') ?: 'root');
        $password = (string) getenv('WEFT_TEST_DB_PASSWORD');
        foreach (['DB_HOST' => $host, 'DB_NAME' => $name, 'DB_USER' => $user, 'DB_PASSWORD' => $password] as $constant => $value) {
            if (!defined($constant)) {
                define($constant, $value);
            }
        }

        $this->admin = new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->admin->exec('DROP TABLE IF EXISTS weft_items');
        $this->admin->exec('CREATE TABLE weft_items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(20), `order` INT NULL) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->exec('DROP TABLE IF EXISTS weft_items');
        }
        parent::tearDown();
    }

    public function testStatementAfterALostConnectionRunsOnANewOne(): void
    {
        $db = Database::connect();
        $killed = $this->kill($db);

        try {
            $db->execute("INSERT INTO weft_items (name) VALUES ('lost')");
            $this->fail('A statement on a lost connection must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database query failed', $e->getMessage());
            $this->assertContains($e->getCode(), [2006, 2013]);
        }
        $this->assertSame(0, $this->rows());

        $this->assertSame(1, $db->execute("INSERT INTO weft_items (name) VALUES ('after')"));
        $this->assertNotSame($killed, (int) $db->value('SELECT CONNECTION_ID()'));
        $this->assertSame(1, $this->rows());
    }

    public function testCaughtLossInATransactionWritesNothingAfterIt(): void
    {
        $db = Database::connect();

        try {
            $db->transaction(function (Database $db): void {
                $db->execute("INSERT INTO weft_items (name) VALUES ('a')");
                $this->kill($db);
                try {
                    $db->execute("INSERT INTO weft_items (name) VALUES ('b')");
                } catch (\RuntimeException) {
                    // The callback swallows the loss and goes on.
                }
                $db->execute("INSERT INTO weft_items (name) VALUES ('c')");
            });
            $this->fail('A lost connection in a transaction must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database connection lost in a transaction', $e->getMessage());
        }
        $this->assertSame(0, $this->rows());
    }

    public function testLostConnectionInATransactionThrowsAndWritesNothing(): void
    {
        $db = Database::connect();

        try {
            $db->transaction(function (Database $db): void {
                $db->execute("INSERT INTO weft_items (name) VALUES ('first')");
                $this->kill($db);
                $db->execute("INSERT INTO weft_items (name) VALUES ('second')");
            });
            $this->fail('A lost connection in a transaction must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Database connection lost in a transaction', $e->getMessage());
        }
        $this->assertSame(0, $this->rows());

        // The next call outside the transaction gets a new connection.
        $this->assertSame(1, $db->execute("INSERT INTO weft_items (name) VALUES ('later')"));
        $this->assertSame(1, $this->rows());
    }

    public function testExecuteReturnsTheChangedRows(): void
    {
        $db = Database::connect();
        $db->execute("INSERT INTO weft_items (name) VALUES ('a'), ('b')");

        $this->assertSame(2, $db->execute("UPDATE weft_items SET name = 'c'"));
        $this->assertSame(0, $db->execute("UPDATE weft_items SET name = 'c'"));
    }

    public function testSessionUsesTheZoneNameWhenTheServerKnowsIt(): void
    {
        // APP_TIMEZONE is not set in the suite: the application timezone is UTC.
        $known = $this->admin->query("SELECT CONVERT_TZ('2026-01-01 00:00:00', 'UTC', 'UTC')")->fetchColumn() !== null;

        $this->assertSame($known ? 'UTC' : '+00:00', Database::connect()->value('SELECT @@session.time_zone'));
    }

    /** A column named after a reserved word works in every statement Model builds. */
    public function testModelQuotesAColumnNamedOrder(): void
    {
        $items = new class extends Model {
            protected string $table = 'weft_items';
        };

        $id = (string) $items->create(['name' => 'first', 'order' => 2]);
        $this->assertSame(1, $items->update($id, ['order' => 3]));
        $this->assertSame('first', $items->findBy(['order' => 3])['name'] ?? null);
        $this->assertCount(1, $items->where(['order' => 3]));
        $this->assertTrue($items->exists(['order' => 3]));
        $this->assertSame(1, $items->delete($id));
    }

    /** Kill the connection $db uses and return its id. */
    private function kill(Database $db): int
    {
        $id = (int) $db->pdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->admin->exec("KILL CONNECTION $id");
        return $id;
    }

    private function rows(): int
    {
        return (int) $this->admin->query('SELECT COUNT(*) FROM weft_items')->fetchColumn();
    }
}
