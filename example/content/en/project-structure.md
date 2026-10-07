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
│   ├── Bootstrap.php       site wiring (alert notifier, resolvers, …)
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
├── scripts/                CLI: health, sitemap, cache-clear
├── storage/                logs/ and cache/
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
| `/api/contact` | `api/contact.php` |
| `/ru/about` | `pages/about.php` rendered in the `ru` locale |
| `/sitemap.xml`, `/robots.txt` | generated from config |
| anything else | `pages/404.php` |
| an uncaught exception in a page | `pages/500.php`, if present (receives `$error`) |

### Dynamic segments

When no exact file matches, the engine looks for a `[name].php` template in the same directory and
passes the last URL segment into it:

```text
pages/notes/[slug].php   →   /notes/anything   ($slug === 'anything')
```

Inside the template the value is available both as `$slug` and as `$params['slug']`. Only the final
segment is dynamic — that covers content routes without a route table. Translations for such pages
come from the parent directory name (`pages/docs/[slug].php` → `lang/<locale>/docs.php`).

## Two entry points

**`public/index.php`** — the web. It sets error handling and the session cookie parameters,
requires `bootstrap.php` and calls `Golovanov\Weft\Kernel::handle()`. That is all it does. The
session itself starts only when a form needs it (`Csrf`, `RateLimiter`), so a visitor who only
reads pages gets no `PHPSESSID` cookie.

**`scripts/*.php`** — the CLI. Each requires `bootstrap.php` and does its job.

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
2. Trace::boot() + httpStart() assign a trace id, record request_start
3. Headers::send()             security headers + X-Trace-Id
4. Resolve the path
   ├─ strip BASE_URI, sanitise
   ├─ extract the locale (if i18n is on); /en/x redirects to /x
   ├─ /sitemap.xml, /robots.txt → generated response
   ├─ /api/… → run the endpoint, capture its JSON
   └─ otherwise → find the page, load translations, wrap in the layout
5. Trace::httpEnd()            record request_end (status, elapsed)
6. Response->send()            status, headers, body
```

Any uncaught `Throwable` is logged, alerted (if AlertLoop is on) and turned into a 500 — JSON when
the client wanted JSON, HTML otherwise, with a stack trace only when `DEBUG` is on.

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
api/contact.php                  thin: honeypot → CSRF → rate limit → validation → captcha
        │                              → call the service → return JSON
        ▼
app/Services/ContactService.php  the actual work: trace, alert, deliver
```

Keep business logic out of templates and endpoints. Endpoints validate and delegate; services do the
work; templates print.

Next: [Configuration](/docs/configuration).
