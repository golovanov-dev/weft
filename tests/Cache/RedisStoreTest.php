<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Cache;

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Cache\RedisStore;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Security\RateLimiter;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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

            public float $readTimeout = 0;

            public function connect(string $host, int $port = 6379, float $timeout = 0, ?string $persistent_id = null, int $retry_interval = 0, float $read_timeout = 0, ?array $context = null): bool
            {
                $this->calls[] = 'connect';
                $this->readTimeout = $read_timeout;
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
        $this->assertGreaterThan(0, $client->readTimeout, 'a hung Redis must not hold the request');
    }

    public function testCacheAndRateLimiterShareOneConnectionInSeparateKeySpaces(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // No Redis server in tests: the shared store gets a client that records the keys it is sent.
        $redis = new class extends \Redis {
            /** @var list<string> */
            public array $keys = [];
            /** @var array<string, string> */
            public array $values = [];

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->keys[] = 'set ' . $key;
                $this->values[$key] = (string) $value;
                return true;
            }

            public function get(string $key): mixed
            {
                return $this->values[$key] ?? false;
            }

            public function eval(string $script, array $args = [], int $num_keys = 0): mixed
            {
                $this->keys[] = 'eval ' . $args[0];
                return 1;
            }

            public function keys(string $pattern): \Redis|array|false
            {
                $this->keys[] = 'keys ' . $pattern;
                return [];
            }
        };
        $shared = (new \ReflectionClass(RedisStore::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RedisStore::class, 'redis'))->setValue($shared, $redis);
        (new \ReflectionProperty(RedisStore::class, 'shared'))->setValue(null, $shared);
        Config::set('app.url.base', 'https://Example.com');
        Config::set('app.cache', ['enabled' => true, 'driver' => 'redis']);

        Cache::set('page', new \ArrayObject([1]));
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, Cache::get('page'));
        RateLimiter::allow('form', 5, 60);
        Cache::flush();

        $this->assertSame(['set example.com:cache:page', 'eval example.com:ratelimit:form:cli', 'keys example.com:cache:*'], $redis->keys);
        $this->assertSame('mine:cache:', self::prefixWith(['prefix' => 'mine:']));
        $this->assertSame('weft:cache:', self::prefixWith(['base' => '']));
    }

    /**
     * A Redis that replies in order, as the real one does, but takes 1.2 s over EVAL: longer than
     * the read timeout. Prints its port, serves one connection and exits when it closes.
     */
    private const SLOW_EVAL_REDIS = <<<'PHP'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
        $client = stream_socket_accept($server, 30);
        while ($client && ($line = fgets($client)) !== false) {
            $args = [];
            for ($i = (int) substr($line, 1); $i > 0; $i--) {
                $length = (int) substr((string) fgets($client), 1);
                $args[] = substr((string) stream_get_contents($client, $length + 2), 0, $length);
            }
            $command = strtoupper($args[0] ?? '');
            if ($command === 'EVAL') {
                usleep(1200000);
                @fwrite($client, ":42\r\n");
            } elseif ($command === 'GET') {
                $value = serialize('value-of-' . $args[1]);
                @fwrite($client, '$' . strlen($value) . "\r\n" . $value . "\r\n");
            } else {
                @fwrite($client, "+PONG\r\n");
            }
        }
        PHP;

    #[RunInSeparateProcess]
    public function testAReplyThatCameTooLateNeverAnswersTheNextCommand(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        $server = proc_open([PHP_BINARY, '-r', self::SLOW_EVAL_REDIS], [1 => ['pipe', 'w']], $pipes);
        $dir = $this->makeTempDir();
        try {
            define('REDIS_PORT', (int) fgets($pipes[1]));
            Config::set('app.url.base', 'https://example.com');
            Config::set('app.cache', ['enabled' => true, 'driver' => 'redis', 'path' => $dir]);

            $this->assertSame('value-of-example.com:cache:page:home', Cache::get('page:home'));
            // EVAL times out after 1 s and fails open; its ":42" arrives later.
            $this->assertTrue(RateLimiter::allow('contact', 5, 60));
            // Neither the late reply nor the one it pushed back may answer these.
            $this->assertNull(Cache::get('page:about'));
            $this->assertNull(Cache::get('page:pricing'));
            // The rest of the request runs on files, as after a failed connection.
            $this->assertSame('file', RateLimiter::storeStatus()['store']);
        } finally {
            proc_terminate($server);
            proc_close($server);
            $this->removeDir($dir);
        }
    }

    public function testFlushTakesThePrefixLiterallyInThePattern(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // No Redis server in tests: KEYS matches with fnmatch(), the same glob rules as Redis.
        $redis = new class extends \Redis {
            /** @var list<string> */
            public array $stored = ['site[1]:cache:page', 'site1:cache:page', 'sitex:cache:page'];

            public function keys(string $pattern): \Redis|array|false
            {
                return array_values(array_filter($this->stored, fn (string $key): bool => fnmatch($pattern, $key)));
            }

            public function del(array|string $key, string ...$other_keys): \Redis|int|false
            {
                $this->stored = array_values(array_diff($this->stored, (array) $key));
                return 1;
            }
        };
        $store = (new \ReflectionClass(RedisStore::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(RedisStore::class, 'redis'))->setValue($store, $redis);
        (new \ReflectionProperty(RedisStore::class, 'prefix'))->setValue($store, 'site[1]:cache:');

        $store->flush();

        $this->assertSame(['site1:cache:page', 'sitex:cache:page'], $redis->stored);
    }

    /** @param array<string, string> $config prefix: app.cache.prefix; base: app.url.base */
    private static function prefixWith(array $config): string
    {
        Config::set('app.cache.prefix', $config['prefix'] ?? null);
        Config::set('app.url.base', $config['base'] ?? 'https://example.com');
        return (new \ReflectionProperty(RedisStore::class, 'prefix'))->getValue(RedisStore::fromConfig('cache:'));
    }
}
