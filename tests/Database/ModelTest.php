<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Database;

use Golovanov\Weft\Database\Model;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The SQL Model builds, on a PDO that records statements instead of running them. Column names
 * come from array keys and go into SQL as-is, so Model rejects anything that is not a plain
 * identifier; that check runs before Database::connect().
 */
final class ModelTest extends TestCase
{
    private const string INJECTION = 'name) VALUES (1); DROP TABLE users; --';

    /** @return iterable<string, array{\Closure(Model): mixed}> */
    public static function entryPoints(): iterable
    {
        yield 'create' => [static fn(Model $m): mixed => $m->create([self::INJECTION => 'x'])];
        yield 'update' => [static fn(Model $m): mixed => $m->update(1, [self::INJECTION => 'x'])];
        yield 'findBy' => [static fn(Model $m): mixed => $m->findBy([self::INJECTION => 'x'])];
        yield 'where' => [static fn(Model $m): mixed => $m->where([self::INJECTION => 'x'])];
        yield 'exists' => [static fn(Model $m): mixed => $m->exists([self::INJECTION => 'x'])];
    }

    /** @return iterable<string, array{\Closure(Model): mixed, string, list<mixed>}> */
    public static function statements(): iterable
    {
        yield 'find' => [static fn(Model $m): mixed => $m->find(5), 'SELECT * FROM users WHERE id = ? LIMIT 1', [5]];
        yield 'findBy' => [
            static fn(Model $m): mixed => $m->findBy(['email' => 'a@example.com', 'active' => 1]),
            'SELECT * FROM users WHERE email = ? AND active = ? LIMIT 1',
            ['a@example.com', 1],
        ];
        yield 'where without conditions' => [static fn(Model $m): mixed => $m->where([]), 'SELECT * FROM users ', []];
        yield 'exists' => [static fn(Model $m): mixed => $m->exists(['email' => 'a@example.com']), 'SELECT 1 FROM users WHERE email = ? LIMIT 1', ['a@example.com']];
        yield 'create' => [
            static fn(Model $m): mixed => $m->create(['name' => 'Ann', 'email' => 'a@example.com']),
            'INSERT INTO users (name, email) VALUES (?, ?)',
            ['Ann', 'a@example.com'],
        ];
        yield 'update' => [
            static fn(Model $m): mixed => $m->update(5, ['name' => 'Ann', 'active' => 0]),
            'UPDATE users SET name = ?, active = ? WHERE id = ?',
            ['Ann', 0, 5],
        ];
        yield 'delete' => [static fn(Model $m): mixed => $m->delete(5), 'DELETE FROM users WHERE id = ?', [5]];
    }

    /**
     * @param \Closure(Model): mixed $call
     * @param list<mixed> $params
     */
    #[DataProvider('statements')]
    public function testBuildsTheStatement(\Closure $call, string $sql, array $params): void
    {
        $pdo = new FakePdo();
        $pdo->install();

        $call($this->model());

        $this->assertSame([[$sql, $params]], $pdo->statements);
    }

    public function testCreateReturnsTheInsertIdAndUpdateAndDeleteTheChangedRows(): void
    {
        $pdo = new FakePdo();
        $pdo->install();
        $pdo->rowCount = 2;

        $this->assertSame('7', $this->model()->create(['name' => 'Ann']));
        $this->assertSame(2, $this->model()->update(5, ['name' => 'Ann']));
        $pdo->rowCount = 0;
        $this->assertSame(0, $this->model()->delete(5));
    }

    /** @param \Closure(Model): mixed $call */
    #[DataProvider('entryPoints')]
    public function testInvalidColumnNameIsRejectedBeforeAnyQuery(\Closure $call): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $call($this->model());
    }

    public function testPlainIdentifiersAreAccepted(): void
    {
        $columns = new \ReflectionMethod(Model::class, 'columns');

        $this->assertSame(['name', '_draft', 'Col2'], $columns->invoke(null, ['name' => 1, '_draft' => 2, 'Col2' => 3]));
    }

    public function testNumericKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->model()->create(['x']);
    }

    private function model(): Model
    {
        return new class extends Model {
            protected string $table = 'users';
        };
    }
}
