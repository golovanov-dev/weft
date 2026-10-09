<?php

declare(strict_types=1);

namespace Golovanov\Weft\Database;

use PDO;
use PDOException;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Support\Clock;

/**
 * Thin PDO (MySQL) wrapper with one connection per process.
 *
 * Optional: sites that don't touch a database never call it. Configuration comes from DB_*
 * constants; DB_TIMEOUT (seconds, default 5) bounds connecting. The DB session timezone is the
 * application timezone, so NOW()/TIMESTAMP agree with Clock.
 *
 * A lost connection (MySQL 2006/2013) is dropped and the call throws; the next call opens a new one,
 * except inside transaction(): there every call throws until transaction() returns, so the rest of
 * the work never runs outside the transaction. Errors are written to the error log and thrown as
 * RuntimeException with the PDOException as previous; Database sends no alert itself: an exception
 * nobody catches reaches the Kernel or the exception handler, which alert once per dedupe window.
 */
final class Database
{
    /** MySQL client errors of a lost connection: "server has gone away", "lost connection during query". */
    private const LOST_CONNECTION = [2006, 2013];

    /** MySQL error for a time zone name the server has no tables for. */
    private const UNKNOWN_TIME_ZONE = 1298;

    private static ?self $instance = null;

    /** Null after the connection was lost: the next call opens a new one. */
    private ?PDO $pdo;

    /** Whether transaction() is running. */
    private bool $inTransaction = false;

    /** The connection lost inside transaction(): every call throws this until transaction() returns. */
    private ?\RuntimeException $lostInTransaction = null;

    private function __construct()
    {
        $this->pdo = self::open();
    }

    public static function connect(): self
    {
        return self::$instance ??= new self();
    }

    public function pdo(): PDO
    {
        if ($this->lostInTransaction !== null) {
            throw $this->lostInTransaction;
        }
        return $this->pdo ??= self::open();
    }

    /** @return array<string, mixed>|null */
    public function row(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** Runs a statement and returns the number of rows it changed (MySQL counts changed, not matched, rows). */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastInsertId(): ?string
    {
        $id = $this->pdo()->lastInsertId();
        return $id === false ? null : $id;
    }

    /**
     * Runs $work in a transaction: commits what it returns, rolls back and rethrows what it throws.
     * If the rollback fails too, the error log gets a line and the original throwable is rethrown.
     */
    public function transaction(callable $work): mixed
    {
        $this->attempt(static fn(PDO $pdo): bool => $pdo->beginTransaction(), 'Database transaction failed');
        $this->inTransaction = true;
        try {
            $result = $work($this);
            $this->attempt(static fn(PDO $pdo): bool => $pdo->commit(), 'Database transaction failed');
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        } finally {
            $this->inTransaction = false;
            $this->lostInTransaction = null;
        }
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        return $this->attempt(static function (PDO $pdo) use ($sql, $params): \PDOStatement {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }, 'Database query failed');
    }

    /**
     * Runs $operation on the connection; a failure is logged and thrown. A lost connection is
     * dropped, and inside transaction() it stays lost until transaction() returns.
     *
     * @template T
     * @param \Closure(PDO): T $operation
     * @return T
     */
    private function attempt(\Closure $operation, string $failure): mixed
    {
        try {
            return $operation($this->pdo());
        } catch (PDOException $e) {
            if (!$this->lost($e) || !$this->inTransaction) {
                throw self::failure($failure, $e);
            }
            throw $this->lostInTransaction = self::failure('Database connection lost in a transaction', $e);
        }
    }

    /** Whether $e is a lost connection; if so, the connection is dropped. */
    private function lost(PDOException $e): bool
    {
        if (!in_array((int) ($e->errorInfo[1] ?? 0), self::LOST_CONNECTION, true)) {
            return false;
        }
        $this->pdo = null;
        return true;
    }

    private function rollBack(): void
    {
        // A lost connection has nothing to roll back: the server did it when the connection closed.
        if ($this->pdo === null || !$this->pdo->inTransaction()) {
            return;
        }
        try {
            $this->pdo->rollBack();
        } catch (PDOException $e) {
            // Whatever the cause, the connection may still be in the transaction: drop it.
            $this->pdo = null;
            self::logged('Database rollback failed', $e);
        }
    }

    private static function open(): PDO
    {
        $host = (string) Config::env('DB_HOST', '127.0.0.1');
        $name = (string) Config::env('DB_NAME', '');
        $charset = (string) Config::env('DB_CHARSET', 'utf8mb4');
        $user = (string) Config::env('DB_USER', '');
        $password = (string) Config::env('DB_PASSWORD', '');
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => max(1, (int) Config::env('DB_TIMEOUT', 5)),
            ]);
            self::setTimeZone($pdo);
            return $pdo;
        } catch (PDOException $e) {
            // Created here, not in failure(): the alert dedupe key is the line of the exception, and
            // an alert about failed queries must not hold back one about the database being down.
            $message = 'Database connection error';
            throw new \RuntimeException($message, self::logged($message, $e), $e);
        }
    }

    /** The zone by name when the server has time zone tables, otherwise today's offset. */
    private static function setTimeZone(PDO $pdo): void
    {
        try {
            $pdo->exec('SET time_zone = ' . $pdo->quote(Clock::timezone()->getName()));
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== self::UNKNOWN_TIME_ZONE) {
                throw $e;
            }
            $pdo->exec("SET time_zone = '" . Clock::numericOffset() . "'");
        }
    }

    private static function failure(string $message, PDOException $e): \RuntimeException
    {
        return new \RuntimeException($message, self::logged($message, $e), $e);
    }

    /** Writes $message with the PDO error to the error log; returns the code for the exception. */
    private static function logged(string $message, PDOException $e): int
    {
        ErrorHandler::log('database', 500, $message . ': ' . $e->getMessage(), __FILE__, __LINE__);
        $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : (int) $e->getCode();
        return $code > 0 ? $code : 500;
    }
}
