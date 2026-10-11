<?php

declare(strict_types=1);

namespace Golovanov\Weft\Http;

use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Observability\Trace;

/**
 * cURL HTTP client for outbound requests: SSL verification on, short timeouts, JSON body and
 * decoding, and request/response events on the trace (log => true; the response body is never
 * traced).
 *
 * `ok` is true only when a response arrived, its status is below 400 and, with decodeJson, its
 * body is valid JSON. `status` and the (decoded) `body` are returned either way.
 *
 * @phpstan-type Options array{
 *   method?: string, headers?: array<int, string>, timeout?: int, connectTimeout?: int,
 *   sslVerify?: bool, body?: mixed, json?: bool, decodeJson?: bool, log?: bool
 * }
 * @phpstan-type TransportRequest array{
 *   method: string, url: string, headers: list<string>, body: ?string,
 *   timeout: int, connectTimeout: int, sslVerify: bool
 * }
 * @phpstan-type TransportResponse array{
 *   status: int, headers: array<string, string>, body: string|false, error: string, info?: array<string, mixed>
 * }
 */
final class HttpClient
{
    /**
     * Transport seam: null means curl. Tests replace it (through reflection) to answer requests
     * without a network; `body` false means no response arrived, `error` is the transport's message.
     *
     * @var null|\Closure(TransportRequest): TransportResponse
     */
    private static ?\Closure $transport = null;

    public function __construct(
        private int $timeout = 8,
        private int $connectTimeout = 5,
        private bool $sslVerify = true,
    ) {
    }

    /**
     * @param Options $options
     * @return array{ok: bool, status: int, headers: array<string, string>, error: string|null, body: mixed, info?: array<string, mixed>}
     */
    public function request(string $url, array $options = []): array
    {
        if ($url === '') {
            throw new \InvalidArgumentException('URL is required');
        }

        $opt = array_merge([
            'method' => 'GET',
            'headers' => [],
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'sslVerify' => $this->sslVerify,
            'body' => null,
            'json' => true,
            'decodeJson' => true,
            'log' => true,
        ], $options);

        $method = strtoupper((string) $opt['method']);
        $json = (bool) $opt['json'];
        $log = (bool) $opt['log'];

        // Headers: strip any caller Content-Type, then set ours.
        $headers = array_filter(
            is_array($opt['headers']) ? $opt['headers'] : [],
            static fn(string $h): bool => stripos($h, 'content-type:') !== 0
        );
        $contentType = $json ? 'application/json' : 'application/x-www-form-urlencoded';
        $headers[] = 'Content-Type: ' . $contentType;

        $postData = null;
        if ($opt['body'] !== null) {
            $body = $opt['body'];
            $postData = $json
                ? (is_array($body) ? (string) json_encode($body) : (string) $body)
                : (is_array($body) ? http_build_query($body) : (string) $body);
        }

        // The trace gets the URL without its query string and the query parsed, masked by key.
        [$traceUrl, $traceQuery] = Trace::splitQuery($url);
        $traceQuery = $traceQuery !== [] ? ['query' => $traceQuery] : [];

        if ($log) {
            Trace::event('http_out_request', [
                'url' => $traceUrl,
                ...$traceQuery,
                'method' => $method,
                'ssl_verify' => (bool) $opt['sslVerify'],
                // A string body sent with json off is the caller's own format (XML, text): it is
                // labelled urlencoded only because every non-JSON body is, so it is not parsed as a form.
                'body' => $postData === null
                    ? null
                    : Trace::body($postData, $contentType, parseForm: is_array($opt['body'])),
            ]);
        }

        $response = (self::$transport ?? self::curl(...))([
            'method' => $method,
            'url' => $url,
            'headers' => array_values($headers),
            'body' => $postData,
            'timeout' => (int) $opt['timeout'],
            'connectTimeout' => (int) $opt['connectTimeout'],
            'sslVerify' => (bool) $opt['sslVerify'],
        ]);

        $status = $response['status'];
        $received = $response['body'] !== false && $response['error'] === '';
        $bodyOut = $response['body'] === false ? '' : $response['body'];
        $invalidJson = false;

        if ($received && (bool) $opt['decodeJson'] && $bodyOut !== '') {
            $decoded = json_decode($bodyOut, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $bodyOut = $decoded;
            } else {
                $invalidJson = true;
            }
        }

        $error = match (true) {
            !$received => $response['error'] !== '' ? $response['error'] : 'HTTP request failed',
            $status >= 400 => 'HTTP ' . $status,
            $invalidJson => 'Invalid JSON in response',
            default => null,
        };

        $out = [
            'ok' => $error === null,
            'status' => $status,
            'headers' => $response['headers'],
            'error' => $error,
            'body' => $bodyOut,
        ];
        if (ErrorHandler::debug()) {
            $out['info'] = $response['info'] ?? [];
        }

        if ($log) {
            Trace::event('http_out_response', [
                'url' => $traceUrl,
                ...$traceQuery,
                'ok' => $out['ok'],
                'status' => $status,
                'error' => $error,
            ]);
        }

        return $out;
    }

    /** @param array<string, mixed>|null $query */
    public function get(string $url, ?array $query = null, array $options = []): array
    {
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->request($url, ['method' => 'GET'] + $options);
    }

    public function post(string $url, mixed $body, array $options = []): array
    {
        return $this->request($url, ['method' => 'POST', 'body' => $body] + $options);
    }

    /**
     * @param TransportRequest $request
     * @return TransportResponse
     */
    private static function curl(array $request): array
    {
        $ch = curl_init($request['url']);
        if ($ch === false) {
            return ['status' => 0, 'headers' => [], 'body' => false, 'error' => 'curl_init() failed'];
        }

        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $request['timeout'],
            CURLOPT_CONNECTTIMEOUT => $request['connectTimeout'],
            CURLOPT_HTTPHEADER => $request['headers'],
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    // A header sent twice (two X-Robots-Tag lines) keeps both values, comma-joined
                    // as HTTP combines a repeated field (RFC 9110, 5.3).
                    $name = trim(substr($line, 0, $pos));
                    $value = trim(substr($line, $pos + 1));
                    $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
                }
                return strlen($line);
            },
        ]);
        if (!$request['sslVerify']) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        if ($request['method'] === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request['method']);
        }
        if ($request['body'] !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
        }

        $body = curl_exec($ch);

        return [
            'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'headers' => $headers,
            'body' => is_string($body) ? $body : false,
            'error' => curl_error($ch),
            'info' => curl_getinfo($ch) ?: [],
        ];
    }
}
