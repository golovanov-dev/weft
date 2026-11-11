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

    /** Keys, TTL, counters and flush are checked on a real Redis: RedisIntegrationTest. */
    public function testThePrefixIsTheConfiguredOneElseTheHostElseWeft(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        // No Redis server in tests: a client that is connected already is used as is.
        $redis = new class extends \Redis {
            public function isConnected(): bool
            {
                return true;
            }
        };
        (new \ReflectionProperty(RedisStore::class, 'connection'))->setValue(null, $redis);

        $this->assertSame('mine:cache:', self::prefixWith(['prefix' => 'mine:']));
        $this->assertSame('example.com:cache:', self::prefixWith(['base' => 'https://Example.com']));
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

    /**
     * A Redis that takes 1.5 s over GET: longer than the read timeout. It prints "accept" for every
     * connection it takes, one after another, and "quit" on QUIT, then exits.
     */
    private const SLOW_GET_REDIS = <<<'SERVER'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
        while ($client = stream_socket_accept($server, 30)) {
            echo "accept\n";
            while (($line = fgets($client)) !== false) {
                $args = [];
                for ($i = (int) substr($line, 1); $i > 0; $i--) {
                    $length = (int) substr((string) fgets($client), 1);
                    $args[] = substr((string) stream_get_contents($client, $length + 2), 0, $length);
                }
                $command = strtoupper($args[0] ?? '');
                if ($command === 'QUIT') {
                    echo "quit\n";
                    exit;
                }
                if ($command === 'GET') {
                    usleep(1500000);
                }
                @fwrite($client, "+PONG\r\n");
            }
        }
        SERVER;

    /** phpredis drops the socket after a reply timeout; closing it must not connect again first. */
    #[RunInSeparateProcess]
    public function testATimedOutGetClosesWithoutConnectingAgain(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        $server = proc_open([PHP_BINARY, '-r', self::SLOW_GET_REDIS], [1 => ['pipe', 'w']], $pipes);
        $dir = $this->makeTempDir();
        try {
            $port = (int) fgets($pipes[1]);
            define('REDIS_PORT', $port);
            Config::set('app.cache', ['enabled' => true, 'driver' => 'redis', 'path' => $dir]);

            $this->assertNull(Cache::get('page'));
            // The closed connection is not opened again for the rate limiter: it goes to files.
            $this->assertTrue(RateLimiter::allow('contact', 5, 60));
            $this->assertSame('file', RateLimiter::storeStatus()['store']);

            // The server takes connections in order: this one comes after any the store made.
            $last = stream_socket_client('tcp://127.0.0.1:' . $port);
            fwrite($last, "*1\r\n\$4\r\nQUIT\r\n");
            $lines = [];
            while (($line = fgets($pipes[1])) !== false && ($lines[] = trim($line)) !== 'quit') {
            }
            $this->assertSame(['accept', 'accept', 'quit'], $lines);
        } finally {
            proc_terminate($server);
            proc_close($server);
            $this->removeDir($dir);
        }
    }

    /**
     * A Redis that wants a password: it prints "accept" for every connection, answers its first
     * command with NOAUTH and closes it; on QUIT it prints "quit" and exits.
     */
    private const NOAUTH_REDIS = <<<'SERVER'
        $server = stream_socket_server('tcp://127.0.0.1:0');
        echo parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT), "\n";
        while ($client = stream_socket_accept($server, 30)) {
            echo "accept\n";
            $line = (string) fgets($client);
            $args = [];
            for ($i = (int) substr($line, 1); $i > 0; $i--) {
                $length = (int) substr((string) fgets($client), 1);
                $args[] = substr((string) stream_get_contents($client, $length + 2), 0, $length);
            }
            if (strtoupper($args[0] ?? '') === 'QUIT') {
                echo "quit\n";
                exit;
            }
            @fwrite($client, "-NOAUTH Authentication required.\r\n");
            fclose($client);
        }
        SERVER;

    /** A request waits for a Redis that rejects it once: the cache and the rate limiter share the failure. */
    #[RunInSeparateProcess]
    public function testAFailedConnectionIsNotTriedAgainInTheSameRequest(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed');
        }

        $server = proc_open([PHP_BINARY, '-r', self::NOAUTH_REDIS], [1 => ['pipe', 'w']], $pipes);
        $dir = $this->makeTempDir();
        try {
            $port = (int) fgets($pipes[1]);
            define('REDIS_PORT', $port);
            Config::set('app.cache', ['enabled' => true, 'driver' => 'redis', 'path' => $dir]);

            $this->assertNull(Cache::get('page'));
            $this->assertTrue(RateLimiter::allow('contact', 5, 60));
            $this->assertTrue(RateLimiter::allow('contact', 5, 60));
            // The rate limiter reports the reason Redis gave the cache.
            $this->assertStringContainsString('NOAUTH', (string) RateLimiter::storeStatus()['error']);

            $last = stream_socket_client('tcp://127.0.0.1:' . $port);
            fwrite($last, "*1\r\n\$4\r\nQUIT\r\n");
            $lines = [];
            while (($line = fgets($pipes[1])) !== false && ($lines[] = trim($line)) !== 'quit') {
            }
            $this->assertSame(['accept', 'accept', 'quit'], $lines);
        } finally {
            proc_terminate($server);
            proc_close($server);
            $this->removeDir($dir);
        }
    }

    /** @param array<string, string> $config prefix: app.cache.prefix; base: app.url.base */
    private static function prefixWith(array $config): string
    {
        Config::set('app.cache.prefix', $config['prefix'] ?? null);
        Config::set('app.url.base', $config['base'] ?? 'https://example.com');
        return (new \ReflectionProperty(RedisStore::class, 'prefix'))->getValue(RedisStore::fromConfig('cache:'));
    }
}
