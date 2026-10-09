<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Database;

use Golovanov\Weft\Database\Database;
use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO that talks to no server: it records each prepared statement with its parameters, answers
 * with $rows / $rowCount, and throws $failure (once per entry) where a test asks it to.
 */
final class FakePdo extends PDO
{
    /** @var list<array{0: string, 1: array<int|string, mixed>}> */
    public array $statements = [];

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public int $rowCount = 0;

    public bool $inTransaction = false;

    /** @var list<PDOException|null> thrown by the next prepare() calls, one each; null lets one through */
    public array $prepareFailures = [];

    public ?PDOException $rollBackFailure = null;

    public function __construct()
    {
        // No parent::__construct(): there is nothing to connect to.
    }

    /** Make Database::connect() return a Database on this PDO. */
    public function install(): Database
    {
        $class = new \ReflectionClass(Database::class);
        $db = $class->newInstanceWithoutConstructor();
        $class->getProperty('pdo')->setValue($db, $this);
        $class->setStaticPropertyValue('instance', $db);
        return $db;
    }

    public static function lostConnection(): PDOException
    {
        $e = new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
        return $e;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $failure = array_shift($this->prepareFailures);
        if ($failure !== null) {
            throw $failure;
        }
        $pdo = $this;
        return new class ($pdo, $query) extends PDOStatement {
            public function __construct(private FakePdo $pdo, private string $sql)
            {
            }

            public function execute(?array $params = null): bool
            {
                $this->pdo->statements[] = [$this->sql, $params ?? []];
                return true;
            }

            public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
            {
                return $this->pdo->rows[0] ?? false;
            }

            public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
            {
                return $this->pdo->rows;
            }

            public function fetchColumn(int $column = 0): mixed
            {
                return isset($this->pdo->rows[0]) ? array_values($this->pdo->rows[0])[$column] : false;
            }

            public function rowCount(): int
            {
                return $this->pdo->rowCount;
            }
        };
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function beginTransaction(): bool
    {
        $this->inTransaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->inTransaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->rollBackFailure !== null) {
            throw $this->rollBackFailure;
        }
        $this->inTransaction = false;
        return true;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '7';
    }
}
