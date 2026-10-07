<?php

declare(strict_types=1);

namespace Golovanov\Weft\Http;

use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Observability\Trace;

/**
 * Simple cURL-based HTTP client for outbound requests to external services.
 *
 * Safe defaults: SSL verification on, short timeouts, JSON body/decoding, and outbound
 * request/response events written to the current request's trace timeline (log => true).
 * The request body is traced as size and content type, its fields only with
 * app.trace.log_body on (Trace::body()); the response body is never traced.
 *
 * @phpstan-type Options array{
 *   method?: string, headers?: array<int, string>, timeout?: int, connectTimeout?: int,
 *   sslVerify?: bool, body?: mixed, json?: bool, decodeJson?: bool, log?: bool
 * }
 */
final class HttpClient
{
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
        $debug = ErrorHandler::debug();

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $opt['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $opt['connectTimeout']);

        if (!$opt['sslVerify']) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        // Capture response headers.
        $respHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($_ch, string $line) use (&$respHeaders): int {
            $len = strlen($line);
            $line = trim($line);
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $respHeaders[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
            }
            return $len;
        });

        // Headers: strip any caller Content-Type, then set ours.
        $headers = array_filter(
            is_array($opt['headers']) ? $opt['headers'] : [],
            static fn(string $h): bool => stripos($h, 'content-type:') !== 0
        );
        $contentType = $json ? 'application/json' : 'application/x-www-form-urlencoded';
        $headers[] = 'Content-Type: ' . $contentType;
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_values($headers));

        // Body.
        $postData = null;
        if ($opt['body'] !== null) {
            $body = $opt['body'];
            $postData = $json
                ? (is_array($body) ? (string) json_encode($body) : (string) $body)
                : (is_array($body) ? http_build_query($body) : (string) $body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        if ($log) {
            Trace::event('http_out_request', [
                'url' => $url,
                'method' => $method,
                'ssl_verify' => (bool) $opt['sslVerify'],
                'body' => $postData === null ? null : Trace::body($postData, $contentType),
            ]);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $info = $debug ? (curl_getinfo($ch) ?: []) : null;
        curl_close($ch);

        $ok = $response !== false && $error === '';
        $bodyStr = $response === false ? '' : (string) $response;
        $bodyOut = $bodyStr;

        if ($ok && (bool) $opt['decodeJson'] && $bodyStr !== '') {
            $decoded = json_decode($bodyStr, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $bodyOut = $decoded;
            } else {
                $ok = false;
                $error = $error !== '' ? $error : 'Invalid JSON in response';
            }
        }

        $out = [
            'ok' => $ok,
            'status' => $status,
            'headers' => $respHeaders,
            'error' => $ok ? null : ($error !== '' ? $error : 'HTTP request failed'),
            'body' => $bodyOut,
        ];
        if ($debug && is_array($info)) {
            $out['info'] = $info;
        }

        if ($log) {
            Trace::event('http_out_response', [
                'url' => $url,
                'ok' => $ok,
                'status' => $status,
                'error' => $out['error'],
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
}
