<?php

declare(strict_types=1);

namespace Golovanov\Weft\Database;

/**
 * Minimal active-record-ish base model. Subclasses set $table (and optionally $primaryKey).
 * Intentionally small — enough for simple sites, not an ORM.
 */
abstract class Model
{
    protected string $table = '';
    protected string $primaryKey = 'id';

    protected function db(): Database
    {
        return Database::connect();
    }

    /** @return array<string, mixed>|null */
    public function find(int|string $id): ?array
    {
        return $this->db()->row(
            "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $conditions column => value (equality only)
     * @return array<string, mixed>|null
     */
    public function findBy(array $conditions): ?array
    {
        [$where, $params] = $this->buildWhere($conditions);
        return $this->db()->row("SELECT * FROM {$this->table} {$where} LIMIT 1", $params);
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array<int, array<string, mixed>>
     */
    public function where(array $conditions): array
    {
        [$where, $params] = $this->buildWhere($conditions);
        return $this->db()->all("SELECT * FROM {$this->table} {$where}", $params);
    }

    public function exists(array $conditions): bool
    {
        [$where, $params] = $this->buildWhere($conditions);
        return $this->db()->value("SELECT 1 FROM {$this->table} {$where} LIMIT 1", $params) !== null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): ?string
    {
        $columns = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $columns) . ") VALUES ($placeholders)";
        $this->db()->execute($sql, array_values($data));
        return $this->db()->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int|string $id, array $data): bool
    {
        $set = implode(', ', array_map(static fn(string $c): string => "$c = ?", array_keys($data)));
        $params = array_values($data);
        $params[] = $id;
        return $this->db()->execute(
            "UPDATE {$this->table} SET $set WHERE {$this->primaryKey} = ?",
            $params
        );
    }

    public function delete(int|string $id): bool
    {
        return $this->db()->execute(
            "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?",
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function buildWhere(array $conditions): array
    {
        if ($conditions === []) {
            return ['', []];
        }
        $clauses = [];
        $params = [];
        foreach ($conditions as $column => $value) {
            $clauses[] = "$column = ?";
            $params[] = $value;
        }
        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }
}
