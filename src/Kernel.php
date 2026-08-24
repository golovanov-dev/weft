<?php

declare(strict_types=1);

namespace Golovanov\Weft;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\Request;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\Http\Response;
use Golovanov\Weft\I18n\I18n;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Observability\ErrorHandler;
use Golovanov\Weft\Observability\Trace;
use Golovanov\Weft\Security\Headers;
use Golovanov\Weft\Seo\Robots;
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
 *   /api/<route>.php          a JSON endpoint (manages its own output)
 *   /components/layout.php     the page layout (receives $content)
 *
 * The default locale is served without a URL prefix; other locales use /<locale>/...
 */
final class Kernel
{
    public static function handle(): void
    {
        $request = Request::fromGlobals();
        RequestContext::setRequest($request);
        Trace::boot($request);
        Trace::httpStart($request);
        Headers::send();

        try {
            $response = self::route($request);
        } catch (\Throwable $e) {
            ErrorHandler::log('kernel', (int) $e->getCode(), $e->getMessage(), $e->getFile(), $e->getLine());
            $response = self::errorResponse($request, $e);
        }

        Trace::httpEnd($response->status, RequestContext::elapsed());
        Trace::flush();
        $response->send();
    }

    private static function route(Request $request): Response
    {
        // 1) Path relative to the mount point, sanitised.
        $path = '/' . Sanitize::path(self::stripBaseUri($request->path));

        // 2) Locale from the first segment (default locale has no prefix).
        if (Locale::enabled() && $path !== '/') {
            $parts = explode('/', ltrim($path, '/'), 2);
            $seg = strtolower($parts[0] ?? '');

            if ($seg === Locale::default() && $request->method === 'GET') {
                // Canonicalise /en/about -> /about (avoid duplicate content).
                $target = Url::baseUri() . '/' . ($parts[1] ?? '');
                return Response::redirect(rtrim($target, '/') ?: '/', 301);
            }
            if ($seg !== '' && Locale::isSupported($seg)) {
                Locale::set($seg);
                $path = '/' . ($parts[1] ?? '');
            } else {
                Locale::set(Locale::default());
            }
        } else {
            Locale::set(Locale::default());
        }

        if (!defined('LOCALE')) {
            define('LOCALE', Locale::current());
        }

        // 3) SEO endpoints, generated on the fly from config.
        if ($path === '/sitemap.xml') {
            $xml = Sitemap::xml(Sitemap::routes());
            return new Response(200, $xml, ['Content-Type' => 'application/xml; charset=utf-8']);
        }
        if ($path === '/robots.txt') {
            return new Response(200, Robots::text(), ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // 4) API vs page.
        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return self::dispatchApi($request, ltrim(substr($path, 4), '/'));
        }

        return self::dispatchPage($request, trim($path, '/'));
    }

    private static function dispatchPage(Request $request, string $route): Response
    {
        $route = $route === '' ? (string) Config::get('app.default_route', 'home') : $route;
        $pagesDir = self::root() . '/pages';

        /** @var array<string, string> $params */
        $params = [];
        $i18nRoute = $route;

        // 1) Exact file: /about -> pages/about.php
        $file = $pagesDir . '/' . $route . '.php';

        // 2) Directory index: /blog -> pages/blog/index.php
        if (!is_file($file)) {
            $indexFile = $pagesDir . '/' . $route . '/index.php';
            $file = is_file($indexFile) ? $indexFile : null;
        }

        // 3) Dynamic segment: /docs/installation -> pages/docs/[slug].php with $params['slug'].
        if ($file === null) {
            $dynamic = self::matchDynamicPage($pagesDir, $route);
            if ($dynamic !== null) {
                [$file, $params, $i18nRoute] = $dynamic;
            }
        }

        $status = 200;
        if ($file === null) {
            $status = 404;
            $i18nRoute = $route = '404';
            $file = is_file($pagesDir . '/404.php') ? $pagesDir . '/404.php' : null;
        }

        // Translations for this route (no-op when the lang files don't exist).
        I18n::boot(Locale::current(), $i18nRoute);

        if ($file === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><title>404</title><h1>404 Not Found</h1>', 404);
        }

        $layoutFile = self::layoutFile();
        // Route params are exposed both individually ($slug) and as $params; engine-provided
        // variables always win over a param of the same name.
        $data = array_merge($params, [
            'request' => $request,
            'route' => $route,
            'locale' => Locale::current(),
            'params' => $params,
        ]);
        $html = View::page($file, $layoutFile, $data);

        // A page may set its own status while rendering — typically a dynamic page whose content
        // does not exist (http_response_code(404)). Honour it instead of overwriting with 200.
        $pageStatus = http_response_code();
        if (is_int($pageStatus) && $pageStatus !== 200) {
            $status = $pageStatus;
        }

        return Response::html($html, $status);
    }

    /**
     * Look for a dynamic page template for the last path segment:
     *   /docs/installation  ->  pages/docs/[slug].php   with params ['slug' => 'installation']
     *   /hello              ->  pages/[slug].php        with params ['slug' => 'hello']
     *
     * Only the final segment is dynamic — enough for /notes/{slug}-style content routes without
     * introducing a route table. Translations for such pages use the parent directory as the
     * route name (pages/docs/[slug].php -> lang/<locale>/docs.php).
     *
     * @return array{0: string, 1: array<string, string>, 2: string}|null [file, params, i18nRoute]
     */
    private static function matchDynamicPage(string $pagesDir, string $route): ?array
    {
        $pos = strrpos($route, '/');
        $dir = $pos === false ? '' : substr($route, 0, $pos);
        $last = $pos === false ? $route : substr($route, $pos + 1);

        if ($last === '') {
            return null;
        }

        $searchDir = $dir === '' ? $pagesDir : $pagesDir . '/' . $dir;
        if (!is_dir($searchDir)) {
            return null;
        }

        foreach (scandir($searchDir) ?: [] as $entry) {
            if (preg_match('/^\[([A-Za-z_][A-Za-z0-9_]*)\]\.php$/', $entry, $m) !== 1) {
                continue;
            }
            $i18nRoute = $dir !== '' ? $dir : $m[1];
            return [$searchDir . '/' . $entry, [$m[1] => $last], $i18nRoute];
        }

        return null;
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
            return Response::json(['success' => false, 'error' => 'Not Found', 'route' => $route], 404);
        }

        // The endpoint manages its own JSON output (echo / http_response_code); we capture it so
        // the kernel can still record the trace end and emit a single Response.
        $run = static function (string $__file, Request $request): void {
            require $__file;
        };

        ob_start();
        $run($file, $request);
        $body = (string) ob_get_clean();
        $status = http_response_code();

        return new Response(is_int($status) ? $status : 200, $body, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    private static function errorResponse(Request $request, \Throwable $e): Response
    {
        $debug = ErrorHandler::debug();
        if ($request->wantsJson() || str_starts_with($request->path, (Url::baseUri() . '/api'))) {
            return Response::json([
                'success' => false,
                'error' => $debug ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }

        $errorPage = self::root() . '/pages/500.php';
        if (is_file($errorPage)) {
            return Response::html(View::page($errorPage, self::layoutFile(), ['error' => $e]), 500);
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
        return rtrim((string) Config::env('APP_ROOT', getcwd() ?: '.'), '/\\');
    }
}
