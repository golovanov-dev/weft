<?php

declare(strict_types=1);

namespace Golovanov\Weft\Http;

/**
 * Ends a page early with a real HTTP answer instead of the page itself:
 *
 *   throw Abort::notFound();                     // the site's 404 page, as for an unknown URL
 *   throw Abort::redirect(url('/new-place'));    // 301 to that location
 *
 * Thrown from a page template (or the layout), it is caught by the Kernel. In an api/ endpoint
 * notFound() is the standard JSON 404 and redirect() the same 3xx, so Pagination works there too.
 * The 404 page itself (pages/404.php, or the layout around it) cannot use it: an Abort thrown
 * while the 404 page renders gives the engine's built-in 404 page, not the redirect.
 */
final class Abort extends \RuntimeException
{
    private function __construct(
        public readonly int $status,
        public readonly ?string $location = null,
    ) {
        parent::__construct($location === null ? 'Not Found' : 'Redirect to ' . $location, $status);
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
        return new self($status, $location);
    }
}
