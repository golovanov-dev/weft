<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Cache;

use Golovanov\Weft\Cache\RedisStore;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RedisStoreTest extends TestCase
{
    /**
     * @return array<string, array{string, int, array<string, mixed>, list<string>, ?string}>
     */
    public static function connections(): array
    {
        return [
            'no password, database 0' => ['', 0, [], ['connect', 'ping'], null],
            'password and database accepted' => ['secret', 3, [], ['connect', 'auth', 'select', 'ping'], null],
            'password rejected' => ['wrong', 3, ['auth' => false], ['connect', 'auth'], 'Redis AUTH failed: ERR rejected'],
            'database rejected' => ['', 99, ['select' => false], ['connect', 'select'], 'Redis SELECT 99 failed: ERR rejected'],
            'password required, none sent' => ['', 0, ['ping' => false], ['connect', 'ping'], 'Redis PING failed: ERR rejected'],
        ];
    }

    /**
     * @param array<string, mixed> $replies
     * @param list<string>         $calls
     */
    #[DataProvider('connections')]
    public function testConnectChecksTheConnectionBeforeUse(
        string $password,
        int $database,
        array $replies,
        array $calls,
        ?string $error,
    ): void {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // No Redis server in tests: the client answers every command with its canned reply.
        $client = new class extends \Redis {
            /** @var array<string, mixed> */
            public array $replies = [];
            /** @var list<string> */
            public array $calls = [];

            public function connect(string $host, int $port = 6379, float $timeout = 0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, ?array $context = null): bool
            {
                $this->calls[] = 'connect';
                return true;
            }

            public function auth(mixed $credentials): \Redis|bool
            {
                $this->calls[] = 'auth';
                return $this->replies['auth'] ?? true;
            }

            public function select(int $db): \Redis|bool
            {
                $this->calls[] = 'select';
                return $this->replies['select'] ?? true;
            }

            public function ping(?string $message = null): \Redis|string|bool
            {
                $this->calls[] = 'ping';
                return $this->replies['ping'] ?? true;
            }

            public function getLastError(): ?string
            {
                return 'ERR rejected';
            }
        };
        $client->replies = $replies;

        try {
            new RedisStore(password: $password, database: $database, client: $client);
            $thrown = null;
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        $this->assertSame($error, $thrown);
        $this->assertSame($calls, $client->calls);
    }
}
