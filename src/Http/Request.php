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
        // The path is the request target up to the query string, as sent. Not parse_url(): it reads
        // "//about" as a host named "about" and returns no path, which would serve the home page.
        $path = substr($uri, 0, strcspn($uri, '?#'));
        if (!str_starts_with($path, '/')) {
            // Absolute form ("http://host/about") or an empty target.
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        }

        return new self(
            method: $method,
            uri: $uri,
            path: $path,
            clientIp: self::resolveClientIp($_SERVER, self::proxyIps()),
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
     * Client IP. REMOTE_ADDR, unless it is a trusted proxy (an address or CIDR range in the
     * PROXY_IPS constant): then the rightmost X-Forwarded-For entry that is not a trusted proxy.
     * An empty header, an invalid entry or a chain of trusted proxies only falls back to
     * REMOTE_ADDR. Entries left of the client are written by the client and never read.
     *
     * @param array<string, mixed> $server
     * @param array<mixed>         $trusted
     */
    private static function resolveClientIp(array $server, array $trusted): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '';
        }
        if (!self::isTrusted($remote, $trusted)) {
            return $remote;
        }

        $hops = explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        foreach (array_reverse($hops) as $hop) {
            $hop = trim($hop);
            if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                return $remote;
            }
            if (!self::isTrusted($hop, $trusted)) {
                return $hop;
            }
        }

        return $remote;
    }

    /**
     * Whether the visitor's request came over HTTPS. From a trusted proxy (PROXY_IPS) that sends
     * X-Forwarded-Proto, its rightmost value decides; otherwise $_SERVER['HTTPS'] set and not "off".
     */
    public static function isHttps(): bool
    {
        return self::resolveHttps($_SERVER, self::proxyIps());
    }

    /**
     * @param array<string, mixed> $server
     * @param array<mixed>         $trusted
     */
    private static function resolveHttps(array $server, array $trusted): bool
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        $proto = trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto !== '' && filter_var($remote, FILTER_VALIDATE_IP) && self::isTrusted($remote, $trusted)) {
            // A proxy that appends writes its own value last; values left of it come from the client.
            $values = explode(',', $proto);
            return strtolower(trim((string) end($values))) === 'https';
        }
        return !in_array(strtolower((string) ($server['HTTPS'] ?? '')), ['', 'off'], true);
    }

    /**
     * What is wrong with PROXY_IPS, for health checks: it is not an array (no proxy is trusted), an
     * entry is neither an IP address nor a CIDR range (it is ignored), or an entry trusts a large
     * part of the internet (an IPv4 prefix shorter than /8, an IPv6 one shorter than /16).
     *
     * @return list<string>
     */
    public static function proxyIpsErrors(): array
    {
        if (!defined('PROXY_IPS')) {
            return [];
        }
        if (!is_array(PROXY_IPS)) {
            return ['PROXY_IPS must be an array, ' . get_debug_type(PROXY_IPS) . ' given; no proxy is trusted'];
        }
        $errors = [];
        foreach (PROXY_IPS as $entry) {
            $proxy = self::parseProxy($entry);
            $shown = (string) json_encode($entry, JSON_UNESCAPED_SLASHES);
            if ($proxy === null) {
                $errors[] = "PROXY_IPS: {$shown} is not an IP address or CIDR range";
            } elseif ($proxy[1] < (strlen($proxy[0]) === 4 ? 8 : 16)) {
                $errors[] = "PROXY_IPS: {$shown} trusts too many addresses";
            }
        }
        return $errors;
    }

    /** @return array<mixed> */
    private static function proxyIps(): array
    {
        return defined('PROXY_IPS') && is_array(PROXY_IPS) ? PROXY_IPS : [];
    }

    /**
     * Whether a valid IP matches an entry of PROXY_IPS: an exact IPv4/IPv6 address or a CIDR range
     * ("173.245.48.0/20", "2400:cb00::/32"). Entries that are neither are ignored.
     *
     * @param array<mixed> $trusted
     */
    private static function isTrusted(string $ip, array $trusted): bool
    {
        $ipBin = (string) inet_pton($ip);
        foreach ($trusted as $entry) {
            $proxy = self::parseProxy($entry);
            if ($proxy === null) {
                continue;
            }
            [$netBin, $bits] = $proxy;
            if (strlen($ipBin) !== strlen($netBin)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = chr((0xFF << (8 - $rest)) & 0xFF);
            if (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A PROXY_IPS entry as [packed network address, prefix length], or null when it is neither an
     * IP address nor a CIDR range.
     *
     * @return array{0: string, 1: int}|null
     */
    private static function parseProxy(mixed $entry): ?array
    {
        if (!is_string($entry)) {
            return null;
        }
        [$net, $bits] = array_pad(explode('/', trim($entry), 2), 2, null);
        if (!filter_var($net, FILTER_VALIDATE_IP)) {
            return null;
        }
        $netBin = (string) inet_pton($net);
        $max = strlen($netBin) * 8;
        if ($bits === null) {
            return [$netBin, $max];
        }
        if (!ctype_digit($bits) || (int) $bits > $max) {
            return null;
        }
        return [$netBin, (int) $bits];
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
