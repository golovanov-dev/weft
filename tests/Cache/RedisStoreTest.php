<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Cache;

use Golovanov\Weft\Cache\RedisStore;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class RedisStoreTest extends TestCase
{
    /**
     * @return array<string, array{string, int, array<string, string>, list<string>, ?string}>
     */
    public static function connections(): array
    {
        return [
            'no password, database 0' => ['', 0, [], ['connect', 'ping'], null],
            'password and database accepted' => ['secret', 3, [], ['connect', 'auth', 'select', 'ping'], null],
            'password rejected, Redis 6+' => ['wrong', 3, ['auth' => 'WRONGPASS invalid username-password pair'], ['connect', 'auth'], 'WRONGPASS invalid username-password pair'],
            'password rejected, Redis 5' => ['wrong', 3, ['auth' => 'ERR invalid password'], ['connect', 'auth'], 'Redis AUTH failed (wrong password, or Redis has no password set): ERR invalid password'],
            'database rejected' => ['', 99, ['select' => 'ERR DB index is out of range'], ['connect', 'select'], 'Redis SELECT 99 failed: ERR DB index is out of range'],
            'password required, none sent' => ['', 0, ['ping' => 'NOAUTH Authentication required.'], ['connect', 'ping'], 'NOAUTH Authentication required.'],
            'first command refused with ERR' => ['', 0, ['ping' => 'ERR max number of clients reached'], ['connect', 'ping'], 'Redis PING failed: ERR max number of clients reached'],
        ];
    }

    /**
     * @param array<string, string> $rejects command => Redis error reply
     * @param list<string>          $calls
     */
    #[DataProvider('connections')]
    public function testConnectChecksTheConnectionBeforeUse(
        string $password,
        int $database,
        array $rejects,
        array $calls,
        ?string $error,
    ): void {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // No Redis server in tests. The client answers a rejected command the way phpredis does:
        // a plain "ERR ..." reply returns false with the reply in getLastError(), any other
        // (WRONGPASS, NOAUTH) throws RedisException.
        $client = new class extends \Redis {
            /** @var array<string, string> */
            public array $rejects = [];
            /** @var list<string> */
            public array $calls = [];
            private ?string $lastError = null;

            public function connect(string $host, int $port = 6379, float $timeout = 0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, ?array $context = null): bool
            {
                $this->calls[] = 'connect';
                return true;
            }

            public function auth(mixed $credentials): \Redis|bool
            {
                return $this->reply('auth');
            }

            public function select(int $db): \Redis|bool
            {
                return $this->reply('select');
            }

            public function ping(?string $message = null): \Redis|string|bool
            {
                return $this->reply('ping');
            }

            public function getLastError(): ?string
            {
                return $this->lastError;
            }

            private function reply(string $command): bool
            {
                $this->calls[] = $command;
                $error = $this->rejects[$command] ?? null;
                if ($error === null) {
                    return true;
                }
                if (!str_starts_with($error, 'ERR ')) {
                    throw new \RedisException($error);
                }
                $this->lastError = $error;
                return false;
            }
        };
        $client->rejects = $rejects;

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
