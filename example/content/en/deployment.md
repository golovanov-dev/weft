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
#    DEBUG=false, DEV_MODE=false, BASE_URI='', APP_TIMEZONE,
#    app.url.base = your https domain, plus any keys you use

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

Only `public/` is exposed. `config/` with your secrets, `src/`, `app/` and `storage/` with logs stay
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

Secrets are redacted automatically; files rotate by date and size. On a busy site add OS-level log
rotation for `storage/logs`.

## Checklist

- [ ] `DEBUG=false`, `DEV_MODE=false`
- [ ] `app.url.base` is the real `https://` domain
- [ ] document root points at `public/`
- [ ] `composer install --no-dev --optimize-autoloader` done
- [ ] `npm run build` done, `public/dist/` present
- [ ] `storage/` writable by the web server
- [ ] HTTPS on; consider `app.security.hsts`
- [ ] keys set for whichever of captcha / alerts / mail you use
- [ ] `php scripts/health.php` → `ok: true`

That is the whole engine. If something is missing from these docs, please
[open an issue](https://github.com/golovanov-dev/weft/issues).
