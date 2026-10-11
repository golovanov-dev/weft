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

In `my-site/.gitignore`, delete the line `/composer.lock`, then make `my-site` a repository
(`git init`) and commit the lock file with your site:
deploys install the versions you tested, and rolling back an update relies on it
([Deployment](/docs/deployment), "Updating").

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

Open <http://localhost:8000>. The contact form answers 503 here unless PHP `mail()` works on your
machine (on Windows or without sendmail it usually does not) or SMTP is set up: see
[Deployment](/docs/deployment), "The contact form answers 503". Then check the environment:

```bash
vendor/bin/weft-health --dev
```

```json
{
  "ok": false,
  "php": "8.4.13",
  "timezone": "UTC",
  "config": "config/config.example.php",
  "debug": false,
  "dev_mode": false,
  "production": [
    "config/config.example.php is loaded",
    "app.url.base is on example.com"
  ],
  "logs_writable": true,
  "logs_error": null,
  "cache_driver": "file",
  "rate_limit_store": "file",
  "rate_limit_path": "/path/to/site/storage/cache/ratelimit",
  "rate_limit_writable": true,
  "rate_limit_error": null,
  "proxy_ips_errors": [],
  "multilingual": false,
  "cache_enabled": true,
  "captcha_enabled": false,
  "captcha_missing_keys": [],
  "alert_channels": [],
  "alert_errors": [],
  "mail_driver": "mail",
  "mail_errors": [],
  "checks": {
    "contact_form": {
      "ok": false,
      "mail_to": "hello@example.com",
      "problems": [
        "MAIL_TO is on example.com"
      ]
    }
  }
}
```

`--dev` is for your machine: `production` lists what a server must not have, without failing the
check. On the server you run it without `--dev`. On a fresh copy `ok` is false and the exit code is
1 because of `contact_form`: `MAIL_TO` is still the template's example.com address, so a request
would go to example.com. `contact_form` checks the address, not that an email goes out: without a
mail transport the form answers 503, and a test request through the form is the proof. Set your
own `MAIL_TO` and `MAIL_FROM` in a `config/config.php`
(see [Configuration](/docs/configuration)) or leave them until you deploy; anything else that makes
`ok` false, most often that `storage/` is not writable, needs fixing now. What each field means and how to fix it:
[Deployment](/docs/deployment), "What `weft-health` checks".

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
