---
title: Getting Started
description: Install Weft and get a site running in five minutes.
order: 1
---

# Getting Started

Weft is a lightweight PHP 8.4 engine for content-first websites: landings, portfolios, product and
documentation sites. It gives you routing, SEO, optional i18n, caching, tracing and form protection,
and stays out of the way for everything else.

It is deliberately **not** a full-stack framework — no ORM, no DI container, no admin panel. If your
site is mostly content plus a couple of forms, Weft fits. If it is an application with user accounts
and billing, use a full framework.

## Requirements

| Tool | Version | Why |
|---|---|---|
| PHP | 8.4+ | required by the package (`php >=8.4` in `composer.json`) |
| PHP extensions | `curl`, `json` required; `pdo_mysql` for the database; `redis` for the Redis cache | HTTP client, JSON, storage |
| Composer | 2.x | dependencies and autoloading |
| Node.js | 20.19+ or 22.12+ | *optional* — only to rebuild the frontend with Vite 7; not needed to run the site |

## Install

```bash
composer require golovanov/weft
```

Weft expects a small site skeleton around it. The fastest start is to copy the `example/` directory
from the repository — it is a complete, working site (in fact, it is this documentation site).

```bash
git clone https://github.com/golovanov-dev/weft.git
cp -r weft/example my-site
cd my-site
composer install
```

This installs the engine itself, [traceloom](https://github.com/golovanov-dev/traceloom) for
request tracing, `firebase/php-jwt` for token verification, and `league/commonmark`, which this
documentation site uses to render its Markdown chapters.

## Configure the environment

Configuration comes in two layers. Environment values — secrets and per-server switches — are PHP
constants in `config/`:

- `config.example.php` — the documented template (`DEV_MODE=false`). Used as the fallback when no
  `config.php` exists, so the site works out of the box;
- `config.php` — the real server, or your local dev config. **You create it; it is gitignored.**

The site's `bootstrap.php` picks the first that exists: `config.php` → `config.dev.php` →
`config.example.php`.

## Run it

This example ships its built frontend in `public/dist/`, so **no Node is needed to see it** — the
committed `config.example.php` runs in production mode and serves those assets. The document root
is the `public/` directory:

```bash
php -S localhost:8000 -t public public/index.php
```

Open <http://localhost:8000>. Then check the environment:

```bash
php scripts/health.php
```

```json
{
  "ok": true,
  "php": "8.4.13",
  "timezone": "UTC",
  "debug": false,
  "dev_mode": false,
  "logs_writable": true,
  "cache_driver": "file",
  "rate_limit_store": "file",
  "rate_limit_path": "/path/to/site/storage/cache",
  "rate_limit_writable": true,
  "rate_limit_error": null,
  "proxy_ips_error": null,
  "proxy_ips_invalid": [],
  "proxy_ips_trust_all": [],
  "traceloom": true,
  "multilingual": false,
  "cache_enabled": true,
  "captcha_enabled": false,
  "captcha_missing_keys": [],
  "alertloop_enabled": false
}
```

If `ok` is false (exit code 1), the usual cause is that `storage/` is not writable. What each field
means and how to fix it: [Deployment](/docs/deployment), "What `health.php` checks".

## Working on the frontend (optional)

Sources live in `frontend/src/` and are built by Vite into `public/dist/`. To edit styles/JS with
hot reload, opt into dev mode by creating a `config.php` with `DEV_MODE=true`:

```bash
cp config/config.example.php config/config.php   # set DEV_MODE=true in it
npm install
npm run dev       # Vite dev server with HMR
```

With `DEV_MODE=true` the layout points at the dev server; with it off, at the built files in
`public/dist/`. Run `npm run build` to refresh those. You never edit templates for this.

## Your first page

Create `pages/hello.php`:

```php
<?php
use Golovanov\Weft\Seo\Seo;

Seo::set(['title' => 'Hello', 'description' => 'My first Weft page.']);
?>
<section class="wrap">
  <h1>Hello</h1>
  <p><a href="<?= e(url('/')) ?>">Back home</a></p>
</section>
```

Open `/hello`. That is the whole process: **a file is a route**. Nothing to register.

Next: [Project Structure](/docs/project-structure) explains what happens between the URL and that
file.
