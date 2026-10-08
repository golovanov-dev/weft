# Weft

A lightweight PHP 8.4 engine for **quickly launching simple websites** — SEO, optional
multilingual routing, caching, request tracing and form protection out of the box. You write
pages and a little business logic; the engine handles the rest.

Weft is for content-first sites: landing pages, portfolios, product and documentation sites.
It is deliberately not a full-stack framework — no ORM, no DI container, no admin panel.

```php
<?php // pages/about.php  ->  /about
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
  [traceloom](https://github.com/golovanov-dev/traceloom): JSON, form bodies and query strings logged
  as fields so redaction by key reaches them (other bodies as raw strings it cannot look inside),
  trace files deleted after 30 days.
- **Form protection by default** — rate limiting, CSRF, honeypot and Google reCAPTCHA v3.
- **Alerting for sensitive spots** — push events to
  [AlertLoop](https://github.com/golovanov-dev/alertloop); best-effort, never blocks a request,
  and a failed delivery is recorded on the request trace instead of vanishing.
- **Batteries in the engine** — HTTP client, PDO wrapper, JWT (firebase/php-jwt), security headers.
- **Vite** — dev server with HMR, hashed production assets from the manifest, with
  preload hints for the entry and its statically imported chunks.

## Requirements

- PHP **8.4+** with `ext-curl` and `ext-json`
- `ext-pdo` with `pdo_mysql` only if you use the database (MySQL), `ext-redis` only for the
  Redis cache
- Node.js 20.19+ or 22.12+ (required by Vite 7) only to rebuild the frontend — not needed to run the example, whose built assets
  are committed

## Install

```bash
composer require golovanov/weft
```

Weft expects a small site skeleton around it. The fastest way to get one is to **clone** the repo
and copy the [`example/`](example) directory — it is a complete, working site:

```bash
git clone https://github.com/golovanov-dev/weft.git
cp -r weft/example my-site
```

> **Get the example by cloning, not by "Download ZIP".** The package archive is kept lean:
> `example/`, `tests/` and CI config are `export-ignore`d, so `composer require` — and GitHub's
> ZIP / release "Source code" downloads — contain only the engine. `git clone` gives you
> everything.

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

No build step: the example's built assets are committed, so it runs straight after
`composer install`.

```bash
cd example
composer install
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000>. The example **is** the Weft documentation site — it documents the
engine while being built with it. With no `config/config.php` present, it falls back to
`config/config.example.php` (`DEV_MODE=false`) and serves the committed assets.

### Working on the frontend (optional)

Node is needed only to edit styles/JS:

```bash
cp config/config.example.php config/config.php   # then set DEV_MODE=true in it
npm install
npm run dev                                       # Vite dev server on :5173
```

`npm run build` refreshes the committed `public/dist/`.

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
'captcha'   => ['enabled' => true, 'site_key' => RECAPTCHA_SITEKEY, 'secret_key' => RECAPTCHA_SECRETKEY],
'alertloop' => ['enabled' => true],
```

The captcha needs both keys: enabled without them, it rejects every form submission unless `DEBUG` is on.

## Documentation

Full documentation lives in [`example/content/en`](example/content/en) and is served by the
example site itself — read it there: links between chapters resolve on the running site, not on
GitHub. Start with [Getting Started](example/content/en/getting-started.md).

## Versioning

Weft follows semantic versioning. Pre-1.0 the API may still change between minor versions.

## Security

Please report vulnerabilities privately — see [SECURITY.md](SECURITY.md).

## License

MIT © Roman Golovanov. See [LICENSE](LICENSE).
