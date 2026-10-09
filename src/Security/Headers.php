<?php

declare(strict_types=1);

namespace Golovanov\Weft\Security;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;

/**
 * Baseline security response headers, sent once per request by the Kernel. Values are
 * conservative and safe for a content site; a Content-Security-Policy can be supplied via
 * config('app.security.csp') when you're ready to lock scripts down. HSTS goes out only with
 * app.security.hsts on and DEV_MODE off: max-age from app.security.hsts_max_age (default one
 * year), includeSubDomains only with app.security.hsts_subdomains on.
 */
final class Headers
{
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }
        foreach (self::lines() as $line) {
            header($line);
        }
    }

    /**
     * @internal The header lines send() emits; public so tests can read them from the CLI.
     * @return list<string>
     */
    public static function lines(): array
    {
        $lines = [
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: ' . (string) Config::get('app.security.frame_options', 'SAMEORIGIN'),
            'Referrer-Policy: ' . (string) Config::get('app.security.referrer_policy', 'strict-origin-when-cross-origin'),
        ];

        $permissions = (string) Config::get('app.security.permissions_policy', 'geolocation=(), microphone=(), camera=()');
        if ($permissions !== '') {
            $lines[] = 'Permissions-Policy: ' . $permissions;
        }

        $csp = (string) Config::get('app.security.csp', '');
        if ($csp !== '') {
            $lines[] = 'Content-Security-Policy: ' . $csp;
        }

        if (!(bool) Config::env('DEV_MODE', false) && (bool) Config::get('app.security.hsts', false)) {
            $lines[] = 'Strict-Transport-Security: max-age=' . (int) Config::get('app.security.hsts_max_age', 31536000)
                . ((bool) Config::get('app.security.hsts_subdomains', false) ? '; includeSubDomains' : '');
        }

        // Expose the correlation id so clients can quote it in support requests.
        $traceId = RequestContext::traceId();
        if ($traceId !== '') {
            $lines[] = 'X-Trace-Id: ' . $traceId;
        }

        return $lines;
    }
}
