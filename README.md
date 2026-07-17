# Weft

A lightweight PHP 8.4 engine for **quickly launching simple websites** — SEO, optional
multilingual routing, caching, request tracing and form protection out of the box. You write
pages and a little business logic; the engine handles the rest.

Weft is for content-first sites: landing pages, portfolios, product and documentation sites.
It is deliberately not a full-stack framework — no ORM, no DI container, no admin panel.

```php
// pages/about.php  ->  /about
use Golovanov\Weft\Seo\Seo;

Seo::set(['title' => 'About', 'description' => 'What I do and how I work.']);
?>
<h1><?= t('about.title') ?></h1>
<a href="<?= e(url('/contact')) ?>"><?= t('nav.contact') ?></a>
```

That file is the whole route. No route table, no controller, no registration.

---

## Why

Every small site re-solves the same problems: clean URLs, locale prefixes, a correct `<head>`,
sitemap, spam-resistant forms, tracing, asset hashing. Weft puts all of it behind a small,
convention-driven core so a new site is mostly content.

## Features

- **Convention routing** — `pages/about.php` → `/about`, `api/contact.php` → `/api/contact`,
  `pages/notes/[slug].php` → `/notes/anything`.
- **SEO under the hood** — title templates, canonical, Open Graph, Twitter cards, hreflang and
  JSON-LD rendered from config + per-page overrides. Generated `/sitemap.xml` and `/robots.txt`.
- **Optional i18n** — off by default. Turn it on and the default locale stays prefix-free
  (`/about`), others get `/ru/about`, with automatic hreflang and canonical redirects.
- **Optional caching** — file or Redis. `Cache::remember()` is safe even when caching is off.
- **Request tracing** — one JSONL timeline per request via
  [traceloom](https://github.com/golovanov-dev/traceloom), with automatic redaction.
- **Form protection by default** — rate limiting, CSRF, honeypot and Google reCAPTCHA v3.
- **Alerting for sensitive spots** — push events to
  [AlertLoop](https://github.com/golovanov-dev/alertloop); best-effort, never blocks a request.
- **Batteries in the engine** — HTTP client, PDO wrapper, JWT (firebase/php-jwt), security headers.
- **Vite** — dev server with HMR, hashed production assets from the manifest.

## Requirements

- PHP **8.4+** with `ext-curl` and `ext-json`
- `ext-pdo` only if you use the database, `ext-redis` only for the Redis cache
- Node.js 18+ to build the frontend

## Install

```bash
composer require golovanov/weft
```

Weft expects a small site skeleton around it. The fastest way to get one is to copy the
[`example/`](example) directory — it is a complete, working site.

## Project layout

```text
your-site/
├── app/
│   ├── Configs/app.php     business config, read via config('app.*')
│   ├── Bootstrap.php       site wiring
│   └── Services/           your business logic
├── config/config.php       environment constants (secrets) — not in git
├── pages/                  views → routes
├── api/                    JSON endpoints → /api/*
├── components/layout.php   layout (receives $content)
├── lang/<locale>/          translation arrays
├── frontend/src/           Vite sources
├── public/index.php        the only entry point (document root)
├── storage/                logs + cache
└── bootstrap.php           shared init (web + CLI)
```

## Quick start

```bash
cd example
composer install
npm install && npm run build
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000>. The example **is** the Weft documentation site — it documents the
engine while being built with it.

## Configuration in two layers

- **Environment** — `config/config.php`, plain `define()` constants: secrets, DB/Redis credentials,
  `DEBUG`, `DEV_MODE`, `APP_TIMEZONE`. Per server, gitignored.
- **Business** — `app/Configs/app.php`, an array read with dot keys: SEO, locales, feature flags,
  rate limits. Committed.

```php
config('app.seo.site_name');
config('app.cache.enabled', false);
```

Everything optional is a flag:

```php
'locales'   => ['enabled' => true, 'supported' => ['en', 'ru']],
'cache'     => ['enabled' => true, 'driver' => 'redis'],
'captcha'   => ['enabled' => true],
'alertloop' => ['enabled' => true],
```

## Documentation

Full documentation lives in [`example/content/en`](example/content/en) and is served by the
example site itself. Start with [Getting Started](example/content/en/getting-started.md).

## Versioning

Weft follows semantic versioning. Pre-1.0 the API may still change between minor versions.

## License

MIT © Roman Golovanov. See [LICENSE](LICENSE).
