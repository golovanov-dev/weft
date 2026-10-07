# Weft example — the documentation site

This directory is a complete, working Weft site. It is also **the Weft documentation**: the docs are
served by the engine they describe, which keeps the example honest — if Weft breaks, its own docs go
down.

Copy this directory to start a new site.

## Run it

No Node required — the built assets in `public/dist/` are committed, so the site runs straight
after `composer install`:

```bash
composer install
php -S localhost:8000 -t public public/index.php
php scripts/health.php
```

Open <http://localhost:8000>. With no `config/config.php` present, `bootstrap.php` falls back to
`config/config.example.php` (`DEV_MODE=false`), which serves the committed assets.

### Working on the frontend (optional)

To edit styles/JS with hot reload, opt into dev mode:

```bash
cp config/config.example.php config/config.php   # then set DEV_MODE=true in it
npm install
npm run dev                                       # Vite dev server on :5173
```

Run `npm run build` before committing to refresh `public/dist/`.

> Working on the engine itself? The example installs `golovanov/weft` from Packagist like any other
> consumer. To point it at your local checkout instead, see [CONTRIBUTING](../CONTRIBUTING.md).

## What it demonstrates

| Feature | Where to look |
|---|---|
| Convention routing | `pages/home.php` → `/`, `pages/docs/index.php` → `/docs` |
| **Dynamic routes** | `pages/docs/[slug].php` → `/docs/getting-started`, with `$slug` |
| SEO per page | `Seo::set()` / `Seo::jsonLd()` in every page; `seo()` in `components/layout.php` |
| Sitemap from content | `app/Configs/app.php` → `'sitemap' => fn() => (new DocsService())->sitemapRoutes()` |
| Caching | `app/Services/DocsService.php` — `Cache::remember()` around Markdown parsing, with `mtime` in the key |
| Logic vs presentation | `DocsService` does the work; `pages/` only print |
| Form protection | `pages/contact.php` + `api/contact.php` — honeypot → CSRF → rate limit → validation → captcha → service |
| Config in two layers | `config/config.example.php` (constants) vs `app/Configs/app.php` (business) |
| Vite | `frontend/src/` → `public/dist/`, switched by `DEV_MODE` |

## Content

Chapters are Markdown files in `content/en/`, each with a small front matter block:

```markdown
---
title: Getting Started
description: Install Weft and get a site running in five minutes.
order: 1
---
```

Add a file named in lowercase letters, digits and hyphens (`my-chapter.md`), and it appears in
the sidebar, on the index, in the sitemap and at `/docs/<filename>` — no code changes.

## Making it multilingual

The docs are English-only, but the plumbing is ready. Set
`'locales' => ['enabled' => true, 'supported' => ['en', 'ru']]` in `app/Configs/app.php`, add
`lang/ru/*.php` and `content/ru/*.md`, and `/ru/docs/...` starts working with automatic `hreflang`.
