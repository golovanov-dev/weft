<?php

declare(strict_types=1);

namespace Golovanov\Weft;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Abort;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Http\Response;
use Golovanov\Weft\I18n\I18n;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Security\Headers;
use Golovanov\Weft\Seo\IndexNow;
use Golovanov\Weft\Seo\LlmsTxt;
use Golovanov\Weft\Seo\Robots;
use Golovanov\Weft\Seo\Seo;
use Golovanov\Weft\Seo\Sitemap;
use Golovanov\Weft\Support\Sanitize;
use Golovanov\Weft\View\View;

/**
 * Front controller. One entry point turns an HTTP request into a rendered page or a JSON API
 * response, with tracing, locale resolution, SEO defaults and security headers applied around it.
 *
 * Convention over configuration, matching the reference site:
 *   /pages/<route>.php        a view (wrapped in the layout)
 *   /pages/<route>/index.php  a view in a sub-directory
 *   /pages/<dir>/[param].php  the last segment under <dir>, as $param
 *   /pages/<dir>/[...param].php  any deeper path under <dir> ("a/b/c"), as $param
 *   /api/<route>.php          a JSON endpoint (manages its own output)
 *   /components/layout.php     the page layout (receives $content)
 *
 * The default locale is served without a URL prefix; other locales use /<locale>/...
 * Every page has one address: GET/HEAD of /about/, /home, /en/about or /RU/about is redirected
 * (301) to /about, /, /about or /ru/about; a path with characters Sanitize::path() would drop or
 * fold is a 404. A page or an API endpoint may end itself with throw Abort::notFound() or
 * Abort::redirect(): a page gets the 404 page, an endpoint the JSON 404, both the redirect.
 */
final class Kernel
{
    /** Whether route() took the request for an API endpoint; errorResponse() then answers JSON. */
    private static bool $api = false;

    public static function handle(): void
    {
        self::$api = false;
        $request = Request::fromGlobals();
        RequestContext::setRequest($request);
        Trace::boot($request);
        Trace::httpStart($request);
        Headers::send();

        $failure = null;
        try {
            $response = self::route($request);
        } catch (\Throwable $e) {
            $failure = $e;
            ErrorHandler::logException($e, 'kernel');
            $response = self::errorResponse($request, $e);
        }

        Trace::httpEnd($response->status, RequestContext::elapsed());
        Trace::flush();
        $response->send();

        if ($failure !== null) {
            // The alert waits for its channels and its window (see Alert). Under PHP-FPM the 500
            // is handed to the client first. Elsewhere the connection stays open until the alert
            // is done, and with output_buffering on a short 500 stays in PHP's buffer till then.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            // Release the session lock, or the visitor's next request waits for the alert too.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            ErrorHandler::alertException($failure);
            Trace::flush();
        }
    }

    private static function route(Request $request): Response
    {
        // 1) Path relative to the mount point. A path that Sanitize::path() would change is not
        //    an address of any page: it gets a 404, never the page its clean form points to.
        //    One trailing slash is not part of the path (redirected below, served as is on POST);
        //    "//" is not the root with a trailing slash but an empty segment, so it is a 404 too.
        $raw = self::stripBaseUri($request->path);
        $requested = ($raw !== '/' && $raw !== '//' && str_ends_with($raw, '/')) ? substr($raw, 0, -1) : $raw;
        $path = '/' . Sanitize::path($requested);
        $clean = $path === $requested;
        // GET/HEAD of a clean path is redirected to its one canonical address.
        $canonicalise = $clean && ($request->method === 'GET' || $request->method === 'HEAD');
        $redirect = $canonicalise && $requested !== $raw;

        // 2) Locale from the first segment (default locale has no prefix).
        $locale = Locale::default();
        if (Locale::enabled() && $path !== '/') {
            $parts = explode('/', ltrim($path, '/'), 2);
            $seg = $parts[0] ?? '';
            if ($seg !== '' && Locale::isSupported($seg)) {
                $locale = strtolower($seg);
                $path = '/' . ($parts[1] ?? '');
                // /en/about -> /about: the default locale is served without a prefix.
                // /RU/about -> /ru/about: a locale prefix has one spelling.
                $redirect = $redirect || ($canonicalise && ($locale === Locale::default() || $seg !== $locale));
            }
        }
        Locale::set($locale);

        // pages/404.php, pages/500.php and pages/<dir>/index.php have no address of their own (the
        // last is served at /<dir>): /404, /500 and /<dir>/index are a 404, with or without a slash,
        // never a redirect.
        $isApi = self::$api = $path === '/api' || str_starts_with($path, '/api/');
        if (!$isApi && ($path === '/404' || $path === '/500' || ($path !== '/index' && str_ends_with($path, '/index')))) {
            $clean = $canonicalise = $redirect = false;
        }

        // 3) The default route is the locale root: /home -> /, /ru/home -> /ru.
        if ($canonicalise && trim($path, '/') === (string) Config::get('app.default_route', 'home')) {
            $path = '/';
            $redirect = true;
        }
        if ($redirect) {
            $query = (string) parse_url($request->uri, PHP_URL_QUERY);
            return Response::redirect(Url::link($path) . ($query !== '' ? '?' . $query : ''), 301);
        }

        if (!$clean) {
            return $isApi ? self::apiNotFound(ltrim(substr($path, 4), '/')) : self::dispatchPage($request, null);
        }

        // 4) SEO endpoints, generated on the fly from config.
        if ($path === '/sitemap.xml') {
            $xml = Sitemap::xml(Sitemap::routes());
            return new Response(200, $xml, ['Content-Type' => 'application/xml; charset=utf-8']);
        }
        if ($path === '/robots.txt') {
            return new Response(200, Robots::text(), ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === '/llms.txt' && LlmsTxt::enabled()) {
            return new Response(200, LlmsTxt::text(), ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($path === IndexNow::keyFile()) {
            return new Response(200, IndexNow::key(), ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // 5) API vs page.
        if ($isApi) {
            return self::dispatchApi($request, ltrim(substr($path, 4), '/'));
        }

        return self::dispatchPage($request, trim($path, '/'));
    }

    /** @param string|null $route route relative to pages/, or null for a request that is a 404 as is */
    private static function dispatchPage(Request $request, ?string $route): Response
    {
        $path = $route === null ? null : '/' . $route;
        $route = $route === '' ? (string) Config::get('app.default_route', 'home') : $route;
        $match = $route === null ? null : self::matchPage(self::root() . '/pages', $route);
        if ($match === null) {
            return self::notFoundPage($request);
        }
        [$file, $params, $i18nRoute] = $match;

        // Canonical, og:url and hreflang are built from the matched route (Seo::render()).
        Url::setRoute($path);
        // Translations for this route (no-op when the lang files don't exist).
        I18n::boot(Locale::current(), $i18nRoute);

        try {
            return self::renderPage($request, $file, $route, $params, 200);
        } catch (Abort $abort) {
            if ($abort->location !== null) {
                return Response::redirect($abort->location, $abort->status);
            }
            // Abort::notFound(): the same answer as an unknown URL, without what the page had
            // already set (title, canonical, JSON-LD).
            Seo::reset();
            return self::notFoundPage($request);
        }
    }

    /**
     * The page template for a route, in order: the exact file, the directory index, a [param]
     * template next to the last segment, then the nearest catch-all [...param] above it.
     *
     * @return array{0: string, 1: array<string, string>, 2: string}|null [file, params, i18nRoute]
     */
    private static function matchPage(string $pagesDir, string $route): ?array
    {
        // 1) Exact file: /about -> pages/about.php
        if (is_file($pagesDir . '/' . $route . '.php')) {
            return [$pagesDir . '/' . $route . '.php', [], $route];
        }
        // 2) Directory index: /blog -> pages/blog/index.php
        if (is_file($pagesDir . '/' . $route . '/index.php')) {
            return [$pagesDir . '/' . $route . '/index.php', [], $route];
        }
        // 3) Dynamic segment, 4) catch-all.
        return self::matchDynamicPage($pagesDir, $route) ?? self::matchCatchAll($pagesDir, $route);
    }

    /**
     * Look for a dynamic page template for the last path segment:
     *   /docs/installation  ->  pages/docs/[slug].php   with params ['slug' => 'installation']
     *   /hello              ->  pages/[slug].php        with params ['slug' => 'hello']
     *
     * Translations for such pages use the parent directory as the route name
     * (pages/docs/[slug].php -> lang/<locale>/docs.php), or the param name at the top level.
     *
     * @return array{0: string, 1: array<string, string>, 2: string}|null [file, params, i18nRoute]
     */
    private static function matchDynamicPage(string $pagesDir, string $route): ?array
    {
        $pos = strrpos($route, '/');
        $dir = $pos === false ? '' : substr($route, 0, $pos);
        $last = $pos === false ? $route : substr($route, $pos + 1);

        $found = self::findTemplate($pagesDir, $dir, '/^\[([A-Za-z_][A-Za-z0-9_]*)\]\.php$/');
        return $found === null ? null : [$found[0], [$found[1] => $last], $dir !== '' ? $dir : $found[1]];
    }

    /**
     * Look for a catch-all template, from the deepest directory of the path up to pages/ itself:
     *   /docs/guide/install  ->  pages/docs/[...path].php   with params ['path' => 'guide/install']
     *
     * A catch-all takes one or more segments below its directory, never the directory itself
     * (/docs is pages/docs/index.php or a 404). Translations as for [param].
     *
     * @return array{0: string, 1: array<string, string>, 2: string}|null [file, params, i18nRoute]
     */
    private static function matchCatchAll(string $pagesDir, string $route): ?array
    {
        $segments = explode('/', $route);
        for ($depth = count($segments) - 1; $depth >= 0; $depth--) {
            $dir = implode('/', array_slice($segments, 0, $depth));
            $found = self::findTemplate($pagesDir, $dir, '/^\[\.\.\.([A-Za-z_][A-Za-z0-9_]*)\]\.php$/');
            if ($found !== null) {
                $rest = implode('/', array_slice($segments, $depth));
                return [$found[0], [$found[1] => $rest], $dir !== '' ? $dir : $found[1]];
            }
        }
        return null;
    }

    /** @return array{0: string, 1: string}|null [file, param name] of the first template in pages/$dir matching $pattern */
    private static function findTemplate(string $pagesDir, string $dir, string $pattern): ?array
    {
        $searchDir = $dir === '' ? $pagesDir : $pagesDir . '/' . $dir;
        if (!is_dir($searchDir)) {
            return null;
        }
        foreach (scandir($searchDir) ?: [] as $entry) {
            if (preg_match($pattern, $entry, $m) === 1) {
                return [$searchDir . '/' . $entry, $m[1]];
            }
        }
        return null;
    }

    /**
     * The site's pages/404.php (or a built-in page) with status 404, and no canonical or hreflang.
     * An Abort thrown while the 404 page or its layout renders is not followed: the answer is the
     * built-in 404, not an error.
     */
    private static function notFoundPage(Request $request): Response
    {
        Url::setRoute(null);
        I18n::boot(Locale::current(), '404');

        $builtIn = Response::html('<!doctype html><meta charset="utf-8"><title>404</title><h1>404 Not Found</h1>', 404);
        $file = self::root() . '/pages/404.php';
        if (!is_file($file)) {
            return $builtIn;
        }
        try {
            return self::renderPage($request, $file, '404', [], 404);
        } catch (Abort) {
            return $builtIn;
        }
    }

    /** @param array<string, string> $params */
    private static function renderPage(Request $request, string $file, string $route, array $params, int $status): Response
    {
        // Route params are exposed both individually ($slug) and as $params; engine-provided
        // variables always win over a param of the same name.
        $data = array_merge($params, [
            'request' => $request,
            'route' => $route,
            'locale' => Locale::current(),
            'params' => $params,
        ]);
        $html = View::page($file, self::layoutFile(), $data);

        // A page may set its own status while rendering — typically a dynamic page whose content
        // does not exist (http_response_code(404)). Honour it instead of overwriting with 200.
        $pageStatus = http_response_code();
        if (is_int($pageStatus) && $pageStatus !== 200) {
            $status = $pageStatus;
        }

        return Response::html($html, $status);
    }

    private static function dispatchApi(Request $request, string $route): Response
    {
        $apiDir = self::root() . '/api';
        $file = $apiDir . '/' . Sanitize::path($route) . '.php';
        if (!is_file($file)) {
            $indexFile = $apiDir . '/' . Sanitize::path($route) . '/index.php';
            $file = is_file($indexFile) ? $indexFile : null;
        }

        if ($file === null) {
            return self::apiNotFound($route);
        }

        // The endpoint prints its JSON and may set the status (http_response_code() or a
        // Response it sends); its return value is ignored. The output is captured so the kernel
        // can still record the trace end and emit a single Response, always as JSON.
        $run = static function (string $__file, Request $request): void {
            require $__file;
        };

        $level = ob_get_level();
        ob_start();
        try {
            $run($file, $request);
            $body = (string) ob_get_clean();
        } catch (Abort $abort) {
            // Abort::notFound() is the standard JSON 404, Abort::redirect() the same 3xx as for a page.
            return $abort->location !== null ? Response::redirect($abort->location, $abort->status) : self::apiNotFound($route);
        } finally {
            // An endpoint that threw leaves its half-written output (and any buffer it opened)
            // behind; drop it so only the error response is sent.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        $status = http_response_code();

        return new Response(is_int($status) ? $status : 200, $body, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    private static function apiNotFound(string $route): Response
    {
        return Response::json(['success' => false, 'error' => 'Not Found', 'route' => $route], 404);
    }

    private static function errorResponse(Request $request, \Throwable $e): Response
    {
        $debug = ErrorHandler::debug();
        if (self::$api || $request->wantsJson()) {
            return Response::json([
                'success' => false,
                'error' => $debug ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }

        $errorPage = self::root() . '/pages/500.php';
        if (is_file($errorPage)) {
            Url::setRoute(null);
            try {
                return Response::html(View::page($errorPage, self::layoutFile(), ['error' => $e]), 500);
            } catch (\Throwable $pageError) {
                // The error page or the layout failed too: log it and answer with the built-in page.
                ErrorHandler::logException($pageError, 'kernel');
            }
        }

        $message = $debug ? '<pre>' . Sanitize::output((string) $e) . '</pre>' : '<p>Something went wrong.</p>';
        return Response::html('<!doctype html><meta charset="utf-8"><title>Error</title><h1>500</h1>' . $message, 500);
    }

    private static function layoutFile(): ?string
    {
        $layout = (string) Config::get('app.layout', 'layout');
        $file = self::root() . '/components/' . $layout . '.php';
        return is_file($file) ? $file : null;
    }

    private static function stripBaseUri(string $path): string
    {
        $base = Url::baseUri();
        if ($base !== '' && str_starts_with($path, $base)) {
            return substr($path, strlen($base)) ?: '/';
        }
        return $path;
    }

    private static function root(): string
    {
        return Config::root();
    }
}
