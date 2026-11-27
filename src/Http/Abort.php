<?php

declare(strict_types=1);

namespace Golovanov\Weft\Http;

/**
 * Ends a page early with a real HTTP answer instead of the page itself:
 *
 *   throw Abort::notFound();                     // the site's 404 page, as for an unknown URL
 *   throw Abort::redirect(url('/new-place'));    // 301 to that location
 *   throw Abort::response(Response::json($data, 422));   // this response, as it is
 *
 * Thrown from a page template (or the layout), it is caught by the Kernel. In an api/ endpoint
 * notFound() is the standard JSON 404, redirect() the same 3xx and response() the same response,
 * so Pagination works there too. What the page printed before the throw is discarded; headers it
 * set with header() go out with a redirect or a response, not with the 404. The 404 page itself
 * (pages/404.php, or the layout around it) cannot use it: an Abort thrown while the 404 page
 * renders gives the engine's built-in 404 page.
 */
final class Abort extends \RuntimeException
{
    private function __construct(
        public readonly int $status,
        public readonly ?string $location = null,
        /** What the Kernel answers with: the redirect or the page's own response; null for notFound(). */
        public readonly ?Response $response = null,
    ) {
        parent::__construct(match (true) {
            $location !== null => 'Redirect to ' . $location,
            $response !== null => 'Response ' . $status,
            default => 'Not Found',
        }, $status);
    }

    public static function notFound(): self
    {
        return new self(404);
    }

    /** @param int $status a 3xx status; 301 by default */
    public static function redirect(string $location, int $status = 301): self
    {
        if ($status < 300 || $status > 399) {
            throw new \InvalidArgumentException('Abort::redirect() needs a 3xx status, got ' . $status);
        }
        return new self($status, $location, Response::redirect($location, $status));
    }

    /** Answer with $response — its status, headers and body — instead of the page or endpoint. */
    public static function response(Response $response): self
    {
        return new self($response->status, null, $response);
    }
}
