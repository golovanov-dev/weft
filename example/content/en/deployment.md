---
title: Deployment
description: Ship to production — document root, build, permissions, logs and the checklist.
order: 6
---

# Deployment

## What ships

Everything except local artefacts: `app/`, `config/` (with a real `config.php`), `pages/`, `api/`,
`components/`, `lang/`, `content/`, `public/` (**including the built `public/dist/`**), `scripts/`,
`storage/`, `bootstrap.php`, `composer.json` + `composer.lock`, `vendor/`.

Not shipped as-is: `node_modules/`, the local `config/config.php` (created on the server),
`storage/logs/*`, `storage/cache/*`. `frontend/` is only needed to build.

## Steps

```bash
# 1. Code on the server (git pull / rsync)

# 2. PHP dependencies, production flavour
composer install --no-dev --optimize-autoloader

# 3. Real environment
cp config/config.example.php config/config.php
#    DEBUG=false, DEV_MODE=false, BASE_URI='', APP_TIMEZONE, plus any keys you use;
#    in app/Configs/app.php set app.url.base to your https domain

# 4. Frontend (here or in CI)
npm ci && npm run build

# 5. Writable runtime
mkdir -p storage/logs storage/cache
chmod -R 770 storage

# 6. Optional: a static sitemap for a CDN
php scripts/sitemap.php

# 7. Verify
php scripts/health.php
```

> **Non-negotiable in production:** `DEBUG=false` and `DEV_MODE=false`. Otherwise assets are fetched
> from a Vite dev server that is not running, and errors are shown to visitors.

## Document root is `public/`

Only `public/` is exposed. `config/` with your secrets, `app/`, `vendor/` and `storage/` with logs stay
**above** the document root and are unreachable from the web.

### Apache

The bundled `public/.htaccess` sends everything to `index.php` and serves existing files as-is. It
also carries commented-out rules for forcing HTTPS and dropping `www.`. Make sure `AllowOverride All`
is enabled.

### Nginx

```nginx
server {
    listen 443 ssl;
    server_name example.com;
    root /var/www/site/public;      # public/ only
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\. { deny all; }
}
```

## Updating

- **The site** — `git pull`, `composer install --no-dev`, `npm run build` if the frontend changed.
- **The engine** — `composer update golovanov/weft`, commit the new `composer.lock`, deploy. External
  versions stay pinned by the lock file.

> No Node on the server (common on shared hosting)? Build in CI and deploy `public/dist/` as an
> artefact.

## Logs

```text
storage/logs/
├── requests/<date>.jsonl   request traces (traceloom): request_start/end, http_out_*, your events
└── errors/<date>.log       errors, exceptions and fatals
```

Traces are line-delimited JSON grouped by `trace_id`. Every response carries `X-Trace-Id`, so a user
report points straight at a timeline:

```bash
vendor/bin/eventtrace show <trace_id> --dir=storage/logs/requests
```

Request and outgoing bodies and query strings are logged as fields; values under sensitive key
names (password, token, api_key, … plus `app.trace.sensitive_keys`) are redacted. Anything else
your forms collect — an email, a phone number — is logged unless you add its key to
`sensitive_keys`. Setting `app.trace.log_body` to `false` logs a body as its size and content type
only, but not the fields your code writes to its own events with `Trace::event()` — the example's
`contact_submit` records the email; only `sensitive_keys` masks those. A body that neither decodes
as JSON nor is a form is logged as a raw string, and so is a form string your code builds
itself for `HttpClient`: pass the fields as an array so their sensitive keys are masked. The `referer` header is logged as sent:
within your site it is the full previous URL, query string included, and masking does not reach
it. Trace files rotate by date and
size and are deleted after `app.trace.retention_days` (30 by default; `0` keeps them forever). The first
request after an engine update already deletes older files: set the key before updating if you need
them.
`errors/` is not cleaned up: rotate it at the OS level.

## Checklist

- [ ] `DEBUG=false`, `DEV_MODE=false`
- [ ] `app.url.base` is the real `https://` domain
- [ ] document root points at `public/`
- [ ] `composer install --no-dev --optimize-autoloader` done
- [ ] `npm run build` done, `public/dist/` present
- [ ] `storage/` writable by the web server
- [ ] HTTPS on; consider `app.security.hsts`
- [ ] keys set for whichever of captcha / alerts / mail you use
- [ ] `app.captcha.allowed_hosts` lists your real domain, if the captcha is on
- [ ] `php scripts/health.php` → `ok: true`

That is the whole engine. If something is missing from these docs, please
[open an issue](https://github.com/golovanov-dev/weft/issues).
