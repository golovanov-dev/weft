<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Database;

use Golovanov\Weft\Database\Model;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Column names come from array keys and go into SQL as-is, so Model rejects anything that is not
 * a plain identifier. The check runs before Database::connect(): these tests need no database.
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
