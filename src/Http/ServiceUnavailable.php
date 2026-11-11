<?php

declare(strict_types=1);

namespace Golovanov\Weft\Http;

/**
 * A dependency the request needs is down for now: the Kernel answers 503 with Retry-After instead
 * of 500, so search engines retry the page rather than drop it. It is logged and alerted like any
 * other uncaught error; the page is the site's pages/500.php, an endpoint gets the JSON error.
 *
 * Database throws it when it cannot connect. A site throws it when a dependency of its own fails:
 *
 *   throw new ServiceUnavailable('Payments API unreachable', previous: $e);
 */
final class ServiceUnavailable extends \RuntimeException
{
    /** @param int $retryAfter seconds for the Retry-After header */
    public function __construct(
        string $message = 'Service Unavailable',
        public readonly int $retryAfter = 120,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        if ($retryAfter < 0) {
            throw new \InvalidArgumentException('ServiceUnavailable needs a Retry-After of 0 seconds or more, got ' . $retryAfter);
        }
        parent::__construct($message, $code, $previous);
    }
}
