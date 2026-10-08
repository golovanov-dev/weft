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

# 5. Writable runtime: storage/ gets the group PHP runs as (www-data below; see "Who writes storage/")
mkdir -p storage/logs storage/cache
sudo chgrp -R www-data storage
sudo chmod -R g+rwX,o-rwx storage
sudo find storage -type d -exec chmod 2770 {} +

# 6. Optional: a static sitemap for a CDN
php scripts/sitemap.php

# 7. Verify, as the user PHP runs as
sudo -u www-data php scripts/health.php
```

Run all of this on the server, in the site root (the directory holding `bootstrap.php`), as the
user who deploys the code; steps 5 and 7 need `sudo`. Substitute your PHP user and its group for
`www-data`: they are `user` and `group` of the PHP-FPM pool (for example
`/etc/php/8.4/fpm/pool.d/www.conf`) or the user Apache runs as.

> **Non-negotiable in production:** `DEBUG=false` and `DEV_MODE=false`. Otherwise assets are fetched
> from a Vite dev server that is not running, and errors are shown to visitors.

### Who writes `storage/`

PHP writes logs, traces, the file cache and rate-limit counters under `storage/` (counters live in
`storage/cache` even with caching off, unless `app.rate_limit.path` points elsewhere). The deploy
user writes cache files there too, from `php scripts/sitemap.php` and cron jobs.

Step 5 lets both write: `storage/` stays the deploy user's, its group is the PHP user's group, and the
setgid bit (the `2` in `2770`) gives every file and directory created in it that group. The engine
creates cache entries and counters with mode `660` whatever the umask, so PHP can overwrite an entry a
script of the deploy user created.

Run scripts and cron jobs as the deploy user (its own `crontab -e`). Run `health.php` as the PHP user
(step 7): from the deploy user's shell it checks the deploy user's permissions and can report
`ok: true` for a `storage/` that PHP cannot write.

If PHP cannot write `storage/`, rate limits stop limiting: the limiter fails open so a broken disk
never locks visitors out, and the line it writes about that goes to `storage/logs/errors/`, which is
not writable either — nothing records it but `health.php` (`rate_limit_writable: false`).

### What `health.php` checks

It prints a JSON report and exits with 1 when `ok` is false. `ok` is false when any of these is bad:

| Field | Bad value | What to do |
|---|---|---|
| `logs_writable` | `false` | give the PHP user write access to `LOGS_PATH` (see above) |
| `rate_limit_writable` | `false` | `rate_limit_error` says what PHP cannot write: give the PHP user write access to `rate_limit_path` and the files in it (see above); with Redis, see below |
| `rate_limit_store` | `file` while `cache_driver` is `redis` | Redis is not used, the cache and counters are per server: see below |
| `proxy_ips_error` | not `null` | `PROXY_IPS` is not an array, so no proxy is trusted: write `define('PROXY_IPS', [...])` |
| `proxy_ips_invalid` | not empty | these `PROXY_IPS` entries are ignored: replace them with IP addresses or CIDR ranges; for `localhost` write `'127.0.0.1'` and/or `'::1'` |
| `proxy_ips_trust_all` | not empty | these entries are too wide for a proxy (`0.0.0.0/0`, `::/0`, an IPv4 range wider than `/8`, an IPv6 one wider than `/16`): list only your proxies (below) |
| `captcha_missing_keys` | not empty, with `DEBUG` off | every form submission is rejected: set the keys or disable the captcha (below) |
| `alertloop_missing_keys` | not empty, with `DEBUG` off | alerts are on but nothing is sent: set the keys or turn alerts off (below) |

With `DEBUG` on, `captcha_missing_keys` and `alertloop_missing_keys` are still listed but do not fail
the check.

`captcha_missing_keys` names keys of `app.captcha`: `site_key`, `secret_key`. In this example they
come from `RECAPTCHA_SITEKEY` and `RECAPTCHA_SECRETKEY` in `config/config.php`, and the captcha is
on whenever `RECAPTCHA_SECRETKEY` is not empty. To disable it, set `RECAPTCHA_SECRETKEY` to `''`; on
a site with its own config, set `app.captcha.enabled` to `false`.

`alertloop_missing_keys` names keys of `app.alertloop`: `base_url`, `api_key`. They come from
`ALERTLOOP_BASE_URL` and `ALERTLOOP_API_KEY` in `config/config.php`, and alerts are on whenever
`ALERTLOOP_API_KEY` is not empty. To turn them off, set `ALERTLOOP_API_KEY` to `''`.

`rate_limit_path` is `app.rate_limit.path`, else `app.cache.path`; a relative one is taken from the
site root (`APP_ROOT`), so `health.php` and the site use the same directory whatever their working
directory. By default it is also the cache directory, so a file in it that PHP cannot overwrite (a
cache entry or a counter, created by a script run as another user) also makes `rate_limit_writable`
false; `rate_limit_error` names one of them.

With `app.cache.driver` set to `redis`, the engine sends `PING` after connecting, and `health.php`
writes a probe counter to Redis (ten-second TTL, deleted at once).

| `rate_limit_store` | `rate_limit_error` contains | Cause |
|---|---|---|
| `file` | `Class "Redis" not found` | ext-redis is not installed |
| `file` | a connection error, such as `Connection refused` | Redis is not reachable at `REDIS_HOST`:`REDIS_PORT` |
| `file` | `NOAUTH` | Redis requires a password and `REDIS_PASSWORD` is empty |
| `file` | `WRONGPASS`, or `Redis AUTH failed` (Redis 5 and older) | `REDIS_PASSWORD` is wrong |
| `file` | `called without any password configured` | `REDIS_PASSWORD` is set, but Redis has no password: set it to `''` |
| `file` | `DB index is out of range` | `REDIS_DB` is higher than Redis allows (16 databases by default) |
| `redis` | `Redis rejected the probe write` | a read-only replica, `maxmemory` reached under the `noeviction` policy, or a Redis user not allowed to run `EVAL` |

When Redis is not used, the cache falls back to files as well. If Redis does not answer at all, every
page that uses the cache waits about 2 seconds for the connection timeout, on every request, until
Redis is back; so does `health.php`. In the `redis` row pages are still served: a cache read or
write Redis rejects is skipped, and a form submission whose counter it rejects is allowed.

The site logs the reason to `errors/<date>.log` under `LOGS_PATH`, in lines like these (time in
`APP_TIMEZONE`):

```text
[2026-10-08 12:00:00] RATE_LIMIT ERRNO:500 | Redis unavailable, rate limits fall back to files: <reason> | …
[2026-10-08 12:00:00] CACHE ERRNO:500 | Cache init failed, falling back to file: <reason> | …
[2026-10-08 12:00:00] CACHE ERRNO:500 | Cache store failed, continuing without cache: <reason> | …
[2026-10-08 12:00:00] RATE_LIMIT ERRNO:500 | Rate limit failed open: <reason> | …
```

The first line comes from form submissions and `health.php`, the second from page views; both mean
Redis is not used. The last two mean Redis rejects commands, as in the `redis` row.

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

## Behind a proxy or CDN

Rate limits count per visitor IP, so PHP has to see the visitor's address. Which one it sees depends
on what stands in front of it:

- **Shared hosting, Apache, or nginx + php-fpm on one machine (the config above)** — `REMOTE_ADDR`
  is usually the visitor already. Leave `PROXY_IPS` empty and check it as below.
- **Another server forwards requests to the one running PHP** — a CDN such as Cloudflare, a load
  balancer, nginx proxying to Apache or to a container. `REMOTE_ADDR` is that server, and every
  visitor shares one limit until you list it in `PROXY_IPS` in `config/config.php`:

```php
define('PROXY_IPS', ['203.0.113.10', '173.245.48.0/20', '2400:cb00::/32']);
```

Each entry is an IP address or a CIDR range. A host name is ignored: write the address — for
`localhost`, `'127.0.0.1'` and/or `'::1'`; for a Docker service name such as `nginx`, its address
(below). `health.php` lists such entries under `proxy_ips_invalid`. It also fails on `0.0.0.0/0` and
`::/0` (`proxy_ips_trust_all`): every address then counts as a proxy, the client IP is always the
proxy's, and the whole site shares one rate limit. Ranges wider than `/8` (IPv4) or `/16` (IPv6)
fail too: visitors from them share that limit or, with a forged `X-Forwarded-For`, pick their own
IP. Cloudflare's ranges and a Docker network (`/16`) pass.

- **Cloudflare** — every range from [cloudflare.com/ips](https://www.cloudflare.com/ips/), IPv4 and
  IPv6. The list changes now and then; compare it when you update the site.
- **A chain** (Cloudflare → your nginx → PHP) — every link: the Cloudflare ranges and the address
  your nginx connects to PHP from.
- **Docker** — the proxy container's address, or the narrowest range it gets addresses from, not the
  whole Docker network without need. Everything listed is believed about the client's address: with a
  port of the PHP container published directly, visitors arrive from the network's gateway, and if
  that address is trusted, a visitor's own `X-Forwarded-For` header picks the IP their limit counts.

Every listed proxy must append the address it got the request from to `X-Forwarded-For`. Cloudflare
does it; in your own nginx, set `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` in the
`location` that proxies to PHP. Weft reads no other header: a proxy that passes the address only in
`X-Real-IP` or `X-Client-Ip` leaves every visitor counted as the proxy, one limit for the whole site.

**Check it.** Open any page of the site from your browser, then on the server, in the site directory:

```bash
grep -h '"request_start"' storage/logs/requests/*.jsonl | tail -n 1 | grep -o '"client_ip":"[^"]*"'
```

`client_ip` must be your own public address (any "what is my IP" page shows it), not the proxy's,
`127.0.0.1` or a Docker address.

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
- [ ] `storage/` writable by the user PHP runs as (logs, cache, rate-limit counters)
- [ ] `client_ip` in the newest `request_start` trace is your own address, not a proxy's (see
  "Behind a proxy or CDN" above); otherwise all visitors share one rate limit
- [ ] HTTPS on; consider `app.security.hsts`
- [ ] keys set for whichever of captcha / alerts / mail you use; a captcha enabled with a key missing
  passes forms while `DEBUG` is on and rejects every submission with it off, alerts without the
  address send nothing — `health.php` lists the missing keys under `captcha_missing_keys` and
  `alertloop_missing_keys`
- [ ] `app.captcha.allowed_hosts` lists your real domain, if the captcha is on
- [ ] `sudo -u www-data php scripts/health.php` (your PHP user) → `ok: true`

That is the whole engine. If something is missing from these docs, please
[open an issue](https://github.com/golovanov-dev/weft/issues).
