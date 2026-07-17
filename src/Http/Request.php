<?php

declare(strict_types=1);

namespace Golovanov\Weft\Http;

/**
 * Immutable snapshot of the incoming HTTP request.
 */
final class Request
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly string $path,
        public readonly string $clientIp,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $post,
        public readonly ?string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        return new self(
            method: $method,
            uri: $uri,
            path: $path,
            clientIp: self::resolveClientIp(),
            headers: self::collectHeaders(),
            query: $_GET,
            post: $_POST,
            rawBody: self::readRawBody($method),
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * True when the client expects JSON (fetch/XHR, Accept header, or /api/ path).
     */
    public function wantsJson(): bool
    {
        if (($this->header('x-requested-with') ?? '') === 'XMLHttpRequest') {
            return true;
        }
        $accept = $this->header('accept') ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Decoded body: JSON when Content-Type is JSON, otherwise the parsed form ($_POST).
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $type = $this->header('content-type') ?? '';
        if (str_contains($type, 'application/json') && $this->rawBody !== null && $this->rawBody !== '') {
            $decoded = json_decode($this->rawBody, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $this->post;
    }

    /** @return array<string, string> */
    private static function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }

    /**
     * Client IP. Trusts X-Forwarded-For / X-Client-Ip ONLY when the request comes from a
     * proxy listed in the PROXY_IPS constant; otherwise uses REMOTE_ADDR.
     */
    private static function resolveClientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trusted = defined('PROXY_IPS') && is_array(PROXY_IPS) ? PROXY_IPS : [];

        if ($trusted !== [] && in_array($remote, $trusted, true)) {
            $forwarded = (string) ($_SERVER['HTTP_X_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            $candidate = trim(explode(',', $forwarded)[0] ?? '');
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    }

    private static function readRawBody(string $method): ?string
    {
        if ($method === 'GET' || $method === 'HEAD') {
            return null;
        }
        $raw = file_get_contents('php://input');
        return $raw === false ? null : $raw;
    }
}
