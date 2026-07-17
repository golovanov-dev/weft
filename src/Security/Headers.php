<?php

declare(strict_types=1);

namespace Golovanov\Weft\Security;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;

/**
 * Baseline security response headers, sent once per request by the Kernel. Values are
 * conservative and safe for a content site; a Content-Security-Policy can be supplied via
 * config('app.security.csp') when you're ready to lock scripts down.
 */
final class Headers
{
    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: ' . (string) Config::get('app.security.frame_options', 'SAMEORIGIN'));
        header('Referrer-Policy: ' . (string) Config::get('app.security.referrer_policy', 'strict-origin-when-cross-origin'));

        $permissions = (string) Config::get('app.security.permissions_policy', 'geolocation=(), microphone=(), camera=()');
        if ($permissions !== '') {
            header('Permissions-Policy: ' . $permissions);
        }

        $csp = (string) Config::get('app.security.csp', '');
        if ($csp !== '') {
            header('Content-Security-Policy: ' . $csp);
        }

        if (!(bool) Config::env('DEV_MODE', false) && (bool) Config::get('app.security.hsts', false)) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        // Expose the correlation id so clients can quote it in support requests.
        $traceId = RequestContext::traceId();
        if ($traceId !== '') {
            header('X-Trace-Id: ' . $traceId);
        }
    }
}
