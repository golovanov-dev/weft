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
| PHP | 8.4+ | the engine uses 8.4 syntax |
| PHP extensions | `curl`, `json` required; `pdo_mysql` for the database; `redis` for the Redis cache | HTTP client, JSON, storage |
| Composer | 2.x | dependencies and autoloading |
| Node.js | 18+ | building the frontend with Vite |

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

This installs three things: the engine itself, [traceloom](https://github.com/golovanov-dev/traceloom)
for request tracing, and `firebase/php-jwt` for token verification.

## Configure the environment

Configuration comes in two layers. Environment values — secrets and per-server switches — are PHP
constants in `config/`:

- `config.example.php` — the template with every constant documented;
- `config.dev.php` — local development (`DEBUG=true`, `DEV_MODE=true`);
- `config.php` — the real server. **You create it; it is gitignored.**

The engine picks the first that exists: `config.php` → `config.dev.php` → `config.example.php`. For
local work you need to do nothing — `config.dev.php` is used automatically.

## Build the frontend

Sources live in `frontend/src/` and are built by Vite into `public/dist/`.

```bash
npm install
npm run dev      # dev server with HMR (pairs with DEV_MODE=true)
# or
npm run build    # hashed production assets
```

The layout switches between the two automatically based on `DEV_MODE` — you never edit templates
for this.

## Run it

The document root is the `public/` directory. Locally:

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
  "logs_writable": true,
  "traceloom": true,
  "multilingual": false,
  "cache_enabled": true
}
```

If `ok` is false, the usual cause is that `storage/` is not writable.

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
