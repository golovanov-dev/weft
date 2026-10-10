---
title: Project Structure
description: The directory layout, the routing conventions and what happens on every request.
order: 2
---

# Project Structure

## Layout

```text
my-site/
├── app/
│   ├── Configs/app.php     business config, read with config('app.*')
│   ├── Bootstrap.php       site wiring (resolvers, services, health checks, …)
│   └── Services/           your business logic
├── config/
│   ├── config.example.php  documented template + fallback (DEV_MODE=false)
│   └── config.php          your real / local environment — secrets, gitignored
├── pages/                  views → routes
├── api/                    JSON endpoints → /api/*
├── components/             layout.php, header.php, footer.php
├── lang/<locale>/          translation arrays
├── content/                your content (Markdown, JSON, …) — optional
├── frontend/src/           Vite sources
├── public/                 document root: index.php, .htaccess, dist/, img/
├── scripts/                CLI: sitemap, cache-clear
├── storage/                logs/ and cache/ (rate-limit counters in cache/ratelimit/)
└── bootstrap.php           shared init (web + CLI)
```

You edit everything except `vendor/`, `public/dist/` and `storage/`.

## Routing is convention, not configuration

There is no route file. The URL path maps straight onto a file:

| URL | Handled by |
|---|---|
| `/` | `pages/home.php` (from `app.default_route`) |
| `/about` | `pages/about.php` |
| `/blog` | `pages/blog/index.php` |
| `/projects/alertloop` | `pages/projects/alertloop.php` |
| `/docs/getting-started` | `pages/docs/[slug].php` with `$slug = 'getting-started'` |
| `/wiki/guides/install` | `pages/wiki/[...path].php` with `$path = 'guides/install'` |
| `/api/contact` | `api/contact.php` |
| `/ru/about` | `pages/about.php` rendered in the `ru` locale |
| `/sitemap.xml`, `/robots.txt` | generated from config |
| anything else | `pages/404.php` |
| an uncaught exception in a page | `pages/500.php`, if present (receives `$error`); the engine's own 500 if it or the layout throws too |

Each page has one address. A GET or HEAD request for `/about/` gets a 301 to `/about`, `/home` (the
`app.default_route` page) to `/`, `/ru/home` and `/ru/` to `/ru`, `/en/about` (default locale) to
`/about`, `/RU/about` to `/ru/about`; the query string is kept. Other methods are served without a
redirect, so a form posting to `/api/contact/` still works. A path with any character other than `A-Z a-z 0-9 / _ - .` (so
also non-ASCII and any percent-encoding), or with an empty, `.` or `..` segment (`//about` too), is
a 404 — a JSON one under `/api` — and is never redirected. So are `/404`, `/500` and `/blog/index`:
`pages/404.php` and `pages/500.php` are what a 404 and an error show, and `pages/blog/index.php` is
served at `/blog` only.

### Dynamic segments

When no exact file and no `index.php` matches, the engine looks for a `[name].php` template in the
directory of the last URL segment and passes that segment into it:

```text
pages/notes/[slug].php   →   /notes/anything   ($slug === 'anything')
```

Next comes a catch-all `[...name].php`: it takes every segment below its directory, as one string.
The nearest one wins, and a top-level `pages/[...path].php` catches every page URL nothing else
serves (not `/api/...`, `/sitemap.xml` or `/robots.txt`):

```text
pages/wiki/[...path].php   →   /wiki/a, /wiki/a/b/c   ($path === 'a', 'a/b/c')
```

A catch-all never serves its own directory: `/wiki` is `pages/wiki/index.php` or a 404.

Inside the template the value is available both as `$slug` (`$path`) and as `$params['slug']`.
Translations for such pages come from the parent directory name (`pages/docs/[slug].php` →
`lang/<locale>/docs.php`), or the param name at the top level.

The template decides whether its content exists. To answer with the site's 404 page — no canonical,
nothing the template had set — or with a redirect, it throws:

```php
use Golovanov\Weft\Http\Abort;

throw Abort::notFound();
throw Abort::redirect(url('/new-place'));   // 301; a second argument picks another 3xx
```

`http_response_code(404)` in the template works too, but then the template renders the not-found
content itself. In an `api/` endpoint `Abort::notFound()` is the standard JSON 404 and
`Abort::redirect()` the same redirect. `pages/404.php` (and the layout while it renders the 404
page) cannot use `Abort`: the visitor gets the engine's built-in 404 page instead and a line goes
to `errors/`, so redirect old addresses from a page template (a catch-all, for example), not from
the 404 page. Headers a template or endpoint set with `header()` before `Abort::notFound()` are
not sent with the 404; a redirect keeps them, so a cookie set before `Abort::redirect()` goes out.

## Two entry points

**`public/index.php`** — the web. It sets error handling and the session cookie parameters (see
[Configuration](/docs/configuration)),
requires `bootstrap.php` and calls `Golovanov\Weft\Kernel::handle()`. Under PHP's built-in server
(`php -S`) it first hands an existing file in `public/` (CSS, JS, images) back to the server. The
session itself starts only when a form needs a CSRF token (`Csrf`), so a visitor who only
reads pages gets no `PHPSESSID` cookie.

**`scripts/*.php`** — the CLI. Each requires `bootstrap.php` and does its job. Its trace events go
to `storage/logs/cli/`, not to the site's `storage/logs/requests/`.

**`vendor/bin/weft-health`** — the health check, shipped with the engine. Run it in the site root:
it requires the site's `bootstrap.php`, so the checks `app/Bootstrap.php` adds with `Health::check()`
are in its report.

`bootstrap.php` is the single place where initialisation happens:

```php
define('APP_ROOT', __DIR__);
require config/config(.php|.dev|.example);   // 1) environment constants
require vendor/autoload.php;                  // 2) autoloading
Clock::applyDefaultTimezone();                // 3) app timezone, before any dates or logs
Config::load(APP_ROOT.'/app/Configs');        // 4) business config → config('app.*')
ErrorHandler::run();                          // 5) error handlers
App\Bootstrap::init();                        // 6) site wiring
```

## The request lifecycle

```text
1. Request::fromGlobals()      method, path, headers, client IP, body
2. Trace::boot() + httpStart() assign a trace id, record request_start (query, body as fields)
3. Headers::send()             security headers + X-Trace-Id
4. Resolve the path
   ├─ strip BASE_URI; a path that is not clean → 404
   ├─ extract the locale (if i18n is on)
   ├─ GET/HEAD: /x/, /en/x, /home → 301 to the one address
   ├─ /sitemap.xml, /robots.txt → generated response
   ├─ /api/… → run the endpoint, capture its JSON
   └─ otherwise → find the page, load translations, wrap in the layout
5. Trace::httpEnd()            record request_end (status, elapsed)
6. Response->send()            status, headers, body
```

Any uncaught `Throwable` is logged, alerted (to the channels in `app.alerts.channels`) and turned into a 500 — JSON for
a request routed to `/api/` or one that asked for JSON, HTML otherwise, with a stack trace only
when `DEBUG` is on. Whatever the page or endpoint printed before it threw is dropped. The API
contract is in [Features](/docs/features).

## How a page becomes HTML

```text
pages/about.php ──► View::render() ──► $content
                                          │
components/layout.php ◄───────────────────┘  (receives $content)
        └──► final HTML ──► client
```

Templates receive `$request`, `$route`, `$locale` (plus route params on dynamic pages) and have the
global helpers available: `e()`, `t()`, `url()`, `asset()`, `seo()`, `config()`, `csrf_field()`.

## Where logic goes

```text
pages/contact.php                markup only
        │  POST
        ▼
api/contact.php                  thin: honeypot → CSRF → validation → rate limit → captcha
        │                              → call the service → JSON, or a redirect without JS
        ▼
app/Services/ContactService.php  the actual work: trace, alert, deliver
```

Keep business logic out of templates and endpoints. Endpoints validate and delegate; services do the
work; templates print.

Next: [Configuration](/docs/configuration).
