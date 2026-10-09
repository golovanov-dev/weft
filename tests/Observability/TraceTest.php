<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Observability;

use Golovanov\Traceloom\Configuration;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the trace keeps about a request: the body (parsed, raw or summarised, app.trace.log_body),
 * the query string parsed out of the URI, and the traceloom settings taken from app.trace.*.
 */
final class TraceTest extends TestCase
{
    /** @param array<string, mixed> $post */
    private function post(string $contentType, string $raw, array $post = []): Request
    {
        return new Request(
            method: 'POST',
            uri: '/api/contact',
            path: '/api/contact',
            clientIp: '127.0.0.1',
            headers: ['content-type' => $contentType, 'content-length' => (string) strlen($raw)],
            query: [],
            post: $post,
            rawBody: $raw,
        );
    }

    /**
     * The `request_start` event of the current trace, read back from the JSONL timeline, plus the
     * raw line it was read from.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function requestStart(Request $request): array
    {
        $id = Trace::boot($request);
        Trace::httpStart($request);
        Trace::flush();

        foreach (glob(Trace::directory() . '/*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $record = json_decode($line, true);
                if (is_array($record) && ($record['trace_id'] ?? null) === $id && ($record['event'] ?? null) === 'request_start') {
                    return [$record['data'], $line];
                }
            }
        }
        $this->fail('request_start was not written');
    }

    private function configuration(): Configuration
    {
        $tracer = (new \ReflectionClass(Trace::class))->getProperty('tracer')->getValue();
        return (new \ReflectionProperty($tracer, 'configuration'))->getValue($tracer);
    }

    public function testBodyIsParsedByDefaultAndMaskedByTraceloomDefaults(): void
    {
        $raw = 'email=ann%40example.com&password=s3cret-pass';

        [$data, $line] = $this->requestStart($this->post('application/x-www-form-urlencoded', $raw));

        $this->assertSame(strlen($raw), $data['body']['size']);
        $this->assertSame('application/x-www-form-urlencoded', $data['body']['content_type']);
        $this->assertSame(['email' => 'ann@example.com', 'password' => Configuration::REDACTED], $data['body']['data']);
        $this->assertStringNotContainsString('s3cret-pass', $line);
    }

    public function testBodyIsSummarisedWhenLogBodyIsOff(): void
    {
        Config::set('app.trace.log_body', false);

        [$data, $line] = $this->requestStart($this->post('application/x-www-form-urlencoded', 'name=Ann&phone=%2B79001234567'));

        $this->assertSame(['size' => 29, 'content_type' => 'application/x-www-form-urlencoded'], $data['body']);
        $this->assertStringNotContainsString('79001234567', $line);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function parsedBodies(): iterable
    {
        yield 'json' => ['application/json; charset=utf-8', '{"name":"Ann","phone":"+79001234567"}', []];
        yield 'urlencoded' => ['application/x-www-form-urlencoded', 'name=Ann&phone=%2B79001234567', []];
        yield 'multipart' => ['multipart/form-data; boundary=x', '', ['name' => 'Ann', 'phone' => '+79001234567']];
    }

    /** @param array<string, mixed> $post */
    #[DataProvider('parsedBodies')]
    public function testParsedBodyIsMaskedBySensitiveKeys(string $type, string $raw, array $post): void
    {
        Config::set('app.trace.sensitive_keys', ['phone']);
        $request = new Request('POST', '/f', '/f', '127.0.0.1', ['content-type' => $type, 'content-length' => '120'], [], $post, $raw);

        [$data, $line] = $this->requestStart($request);

        $this->assertSame(['name' => 'Ann', 'phone' => Configuration::REDACTED], $data['body']['data']);
        $this->assertStringNotContainsString('79001234567', $line);
    }

    /**
     * Rows: a content type that is neither JSON nor a form is not parsed even when the body looks
     * like a form; JSON that decodes to a scalar is not data.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function unparseableBodies(): iterable
    {
        yield 'form-looking text' => ['text/plain', 'name=Ann&password=x'];
        yield 'json scalar' => ['application/json', '"Ann"'];
    }

    #[DataProvider('unparseableBodies')]
    public function testUnparseableBodyIsLoggedAsTheRawString(string $type, string $raw): void
    {
        [$data] = $this->requestStart($this->post($type, $raw));

        $this->assertSame(['size' => strlen($raw), 'content_type' => $type, 'data' => $raw], $data['body']);
    }

    public function testAnyUrlencodedBodyIsParsedAsAForm(): void
    {
        [$data, $line] = $this->requestStart($this->post('application/x-www-form-urlencoded', 'password=s3cret&remember&1st=x'));

        $this->assertSame(['password' => Configuration::REDACTED, 'remember' => '', '1st' => 'x'], $data['body']['data']);
        $this->assertStringNotContainsString('s3cret', $line);
    }

    /** JSON wins over the content type: `curl -d` sends JSON labelled as a form. */
    public function testJsonIsParsedWhateverTheContentType(): void
    {
        [$data, $line] = $this->requestStart($this->post('application/x-www-form-urlencoded', '{"user":{"password":"s3cret"},"list":[1,2]}'));

        $this->assertSame(['user' => ['password' => Configuration::REDACTED], 'list' => [1, 2]], $data['body']['data']);
        $this->assertStringNotContainsString('s3cret', $line);
    }

    public function testJsonArrayIsParsedWithoutAContentType(): void
    {
        [$data] = $this->requestStart($this->post('', '[{"token":"t0k"},"x"]'));

        $this->assertSame([['token' => Configuration::REDACTED], 'x'], $data['body']['data']);
    }

    public function testRequestWithoutABodyHasANullBody(): void
    {
        [$data] = $this->requestStart(new Request('GET', '/', '/', '127.0.0.1', [], [], [], ''));

        $this->assertNull($data['body']);
    }

    public function testLogBodyStringFalseTurnsItOff(): void
    {
        Config::set('app.trace.log_body', 'false');

        [$data] = $this->requestStart($this->post('application/x-www-form-urlencoded', 'name=Ann'));

        $this->assertArrayNotHasKey('data', $data['body']);
    }

    public function testSensitiveKeysGivenAsAStringIsOneKey(): void
    {
        Config::set('app.trace.sensitive_keys', 'phone');

        [$data] = $this->requestStart($this->post('application/x-www-form-urlencoded', 'name=Ann&phone=%2B79001234567'));

        $this->assertSame(['name' => 'Ann', 'phone' => Configuration::REDACTED], $data['body']['data']);
    }

    public function testQueryIsParsedOutOfTheUriAndMaskedByKey(): void
    {
        // Parsed regardless of log_body: the query string is not a body.
        Config::set('app.trace.log_body', false);
        $uri = '/search?q=shoes&token=t0ken-value&user%5Bpassword%5D=s3cret-pass#top';

        [$data, $line] = $this->requestStart(new Request('GET', $uri, '/search', '127.0.0.1', [], [], [], ''));

        $this->assertSame('/search', $data['uri']);
        $this->assertSame('/search', $data['path']);
        $this->assertSame(
            ['q' => 'shoes', 'token' => Configuration::REDACTED, 'user' => ['password' => Configuration::REDACTED]],
            $data['query'],
        );
        $this->assertStringNotContainsString('t0ken-value', $line);
        $this->assertStringNotContainsString('s3cret-pass', $line);
    }

    /** @return iterable<string, array{Request, string}> */
    public static function nonUtf8Names(): iterable
    {
        // cp1251 "имя" as %E8%EC%FF: a scanner's junk parameter or an old form's field name.
        $headers = ['accept' => 'text/html'];
        yield 'query' => [new Request('GET', '/about?%E8%EC%FF=1&token=t0ken-value', '/about', '127.0.0.1', $headers, [], [], ''), 'query'];
        yield 'form' => [
            new Request('POST', '/f', '/f', '127.0.0.1', $headers + ['content-type' => 'application/x-www-form-urlencoded'], [], [], '%E8%EC%FF=1&token=t0ken-value'),
            'body',
        ];
        yield 'multipart' => [
            new Request('POST', '/f', '/f', '127.0.0.1', $headers + ['content-type' => 'multipart/form-data; boundary=x', 'content-length' => '99'], [], ["\xE8\xEC\xFF" => '1', 'token' => 't0ken-value'], ''),
            'body',
        ];
    }

    #[DataProvider('nonUtf8Names')]
    public function testNameThatIsNotUtf8KeepsTheEvent(Request $request, string $field): void
    {
        [$data, $line] = $this->requestStart($request);

        $this->assertArrayNotHasKey('_encoding_error', $data);
        $this->assertSame($request->method, $data['method']);
        $this->assertSame($request->path, $data['path']);
        $this->assertSame('text/html', $data['headers']['accept']);
        $fields = $field === 'query' ? $data['query'] : $data['body']['data'];
        $this->assertSame(Configuration::REDACTED, $fields['token']);
        unset($fields['token']);
        // traceloom turns each invalid byte into U+FFFD and appends a digest of the original name.
        $this->assertSame(['1'], array_values($fields));
        $this->assertMatchesRegularExpression('/^\x{FFFD}{3}~[0-9a-f]{16}$/u', (string) array_key_first($fields));
        $this->assertStringNotContainsString('t0ken-value', $line);
    }

    public function testRenamedNameDoesNotOverwriteAnotherField(): void
    {
        // %E8 and %FF both become U+FFFD, and %EF%BF%BD is a literal U+FFFD already.
        $raw = 'a%E8=1&a%FF=2&a%EF%BF%BD=3&user%5Bpass%FFword%5D=s3cret-pass';

        [$data, $line] = $this->requestStart($this->post('application/x-www-form-urlencoded', $raw));

        $fields = $data['body']['data'];
        $this->assertSame('3', $fields["a\u{FFFD}"]);
        $this->assertSame([Configuration::REDACTED], array_values($fields['user']));
        $this->assertMatchesRegularExpression('/^pass\x{FFFD}word~[0-9a-f]{16}$/u', (string) array_key_first($fields['user']));
        unset($fields["a\u{FFFD}"], $fields['user']);
        // Both renamed names stay, each with its own value: the digest is of the original name.
        $this->assertCount(2, $fields);
        foreach (array_keys($fields) as $key) {
            $this->assertMatchesRegularExpression('/^a\x{FFFD}~[0-9a-f]{16}$/u', (string) $key);
        }
        $values = array_values($fields);
        sort($values);
        $this->assertSame(['1', '2'], $values);
        $this->assertStringNotContainsString('s3cret-pass', $line);
    }

    public function testEmptyQueryMeansNoQueryField(): void
    {
        [$data] = $this->requestStart(new Request('GET', '/about?', '/about', '127.0.0.1', [], [], [], ''));

        $this->assertSame('/about', $data['uri']);
        $this->assertArrayNotHasKey('query', $data);
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function retention(): iterable
    {
        yield 'unset' => [null, 30];
        yield 'configured' => [7, 7];
        yield 'invalid' => [-1, 30];
    }

    #[DataProvider('retention')]
    public function testRetentionDaysReachTraceloom(mixed $configured, int $expected): void
    {
        if ($configured !== null) {
            Config::set('app.trace.retention_days', $configured);
        }

        Trace::boot();

        $this->assertSame($expected, $this->configuration()->retentionDays);
    }

    /** A console script and the web never write to one trace directory. */
    public function testScriptTracesGoToCliAndWebTracesToRequests(): void
    {
        $logs = sys_get_temp_dir() . '/weft-logs';
        $this->assertSame($logs . '/requests', Trace::directory('cli-server'));
        $this->assertSame($logs . '/cli', Trace::directory());

        $id = Trace::boot();
        Trace::event('script_step');
        Trace::flush();

        $file = $logs . '/cli/' . gmdate('Y-m-d') . '.jsonl';
        $this->assertFileExists($file);
        $this->assertStringContainsString($id, (string) file_get_contents($file));
    }
}
