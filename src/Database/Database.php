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
 * constants. The DB session timezone is set to the application timezone so NOW()/TIMESTAMP
 * agree with Clock. On errors it logs and, if a notifier is set (e.g. Alert::notifier()),
 * raises an alert.
 */
final class Database
{
    private static ?self $instance = null;

    /** @var null|callable(string):void */
    private static $errorNotifier = null;

    private PDO $pdo;

    private function __construct()
    {
        $host = (string) Config::env('DB_HOST', '127.0.0.1');
        $name = (string) Config::env('DB_NAME', '');
        $charset = (string) Config::env('DB_CHARSET', 'utf8mb4');
        $user = (string) Config::env('DB_USER', '');
        $password = (string) Config::env('DB_PASSWORD', '');
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);

        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec("SET time_zone = '" . Clock::numericOffset() . "'");
        } catch (PDOException $e) {
            $this->notify('Database connection error: ' . $e->getMessage());
            throw new \RuntimeException('Database connection error', $this->code($e), $e);
        }
    }

    /** @param null|callable(string):void $notifier */
    public static function setErrorNotifier(?callable $notifier): void
    {
        self::$errorNotifier = $notifier;
    }

    public static function connect(): self
    {
        if (self::$instance === null || !self::$instance->alive()) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
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

    public function execute(string $sql, array $params = []): bool
    {
        return $this->run($sql, $params)->rowCount() >= 0;
    }

    public function lastInsertId(): ?string
    {
        $id = $this->pdo->lastInsertId();
        return $id === false ? null : $id;
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->notify('Database query error: ' . $e->getMessage());
            throw new \RuntimeException('Database query failed', $this->code($e), $e);
        }
    }

    private function alive(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function code(PDOException $e): int
    {
        $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : (int) $e->getCode();
        return $code > 0 ? $code : 500;
    }

    private function notify(string $message): void
    {
        ErrorHandler::log('database', 500, $message, __FILE__, __LINE__);
        if (self::$errorNotifier !== null) {
            try {
                (self::$errorNotifier)($message);
            } catch (\Throwable) {
                // Never let the notifier break the main flow.
            }
        }
    }
}
