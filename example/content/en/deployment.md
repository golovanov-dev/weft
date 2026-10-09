---
title: Deployment
description: Ship to production — document root, build, permissions, logs and the checklist.
order: 6
---

# Deployment

## What ships

Everything except local artefacts: `app/`, `config/` (with a real `config.php`), `pages/`, `api/`,
`components/`, `lang/`, `content/`, `public/` (**including the built `public/dist/`**), `scripts/`,
`bootstrap.php`, `composer.json` + `composer.lock`, `vendor/`.

Not shipped: `node_modules/`, the local `config/config.php` (created on the server), `storage/`
(step 5 sets it up on the server). `frontend/` is only needed to build.

## Steps

```bash
# 1. Code on the server (git pull / rsync)
#    rsync runs on your machine and must skip what is not shipped: storage/ (or it undoes step 5)
#    and config/config.php (or it replaces the server's config with your local one):
#    rsync -a --exclude=/storage/ --exclude=/config/config.php --exclude=/node_modules/ \
#      --exclude=/frontend/ ./ deploy@example.com:/var/www/site/

# 2. PHP dependencies, production flavour
composer install --no-dev --optimize-autoloader

# 3. Real environment
cp config/config.example.php config/config.php
#    DEBUG=false, DEV_MODE=false, BASE_URI='', APP_TIMEZONE, plus any keys you use;
#    MAIL_TO (your inbox) and MAIL_FROM (an address of your domain) for the contact form;
#    in app/Configs/app.php set app.url.base to your https domain

# 4. Frontend (here or in CI)
npm ci && npm run build

# 5. storage/: one group for PHP and every other writer (www-data below; see "Who writes storage/")
sudo usermod -aG www-data "$USER"      # you join PHP's group
sudo mkdir -p storage/logs storage/cache
sudo chgrp -R www-data storage
sudo chmod -R g+rwX,o-rwx storage
sudo find storage -type d -exec chmod g+s {} +
echo 'umask 002' >> ~/.profile         # what you create stays group-writable
#    LOGS_PATH outside storage/ (config/config.php)? Create it the same way, before PHP writes there:
#    sudo mkdir -p /var/log/site && sudo chgrp -R www-data /var/log/site
#    sudo chmod -R g+rwX,o-rwx /var/log/site && sudo find /var/log/site -type d -exec chmod g+s {} +
#    now log out and back in: the group and the umask apply from your next login

# 6. PHP-FPM creates group-writable files too (every pool of the service: see "Who writes storage/")
sudo mkdir -p /etc/systemd/system/php8.4-fpm.service.d
printf '[Service]\nUMask=0002\n' | sudo tee /etc/systemd/system/php8.4-fpm.service.d/umask.conf
sudo systemctl daemon-reload && sudo systemctl restart php8.4-fpm
systemctl show -p UMask php8.4-fpm     # must print UMask=0002

# 7. Optional: a static sitemap for a CDN
php scripts/sitemap.php

# 8. Verify, as the user PHP runs as (sudo resets the umask, so set it for the check too)
sudo -u www-data sh -c 'umask 002; vendor/bin/weft-health'
#    then send one request through the contact form (/contact in the example) and see it arrive:
#    weft-health checks the form's settings, not that its email reaches you (see "Monitoring")
```

Run all of this on the server, in the site root (the directory holding `bootstrap.php`), as the
user who deploys the code; steps 5, 6 and 8 need `sudo`. The `rsync` line of step 1 is the
exception: run it on your machine, in the built site, with your deploy user, host and site root.
Substitute your PHP user and its group for `www-data`: they are `user` and `group` of the PHP-FPM
pool (for example `/etc/php/8.4/fpm/pool.d/www.conf`) or the user Apache runs as. Substitute your PHP-FPM service for
`php8.4-fpm` (`systemctl list-units '*fpm*'` shows it); with Apache's mod_php, use `apache2` (or
`httpd`) in step 6 instead. In step 5, substitute your `LOGS_PATH` for `/var/log/site`.

> **Non-negotiable in production:** `DEBUG=false` and `DEV_MODE=false`. Otherwise assets are fetched
> from a Vite dev server that is not running, and errors are shown to visitors.

### Who writes `storage/`

PHP writes the error log, traces, the file cache and rate-limit counters under `storage/` (counters
live in `storage/cache/ratelimit/` even with caching off, unless `app.rate_limit.path` points
elsewhere; `cache-clear.php` leaves them unless that path is the cache directory itself — check
`rate_limit_path` in `vendor/bin/weft-health`).
Scripts write there too (`php scripts/sitemap.php`, your cron jobs), and you read the logs and
traces.

Each trace directory has one writer: `storage/logs/requests/` is written only by PHP serving the
site (PHP's built-in server `php -S` counts as the site), `storage/logs/cli/` only by scripts.
The rules below do not apply to them: whoever writes a trace first creates the directory, and
traceloom gives it `0750`, which drops setgid, and its files `0640`. You read `requests/` through
PHP's group and `cli/` as its owner, the deploy user.

The error log in `storage/logs/errors/`, the cache and the counters are shared: PHP and scripts write
the same files. Weft sets no permissions on them: they get the mode the umask of the process gives.
Steps 5 and 6 give everyone who writes there one group; who owns a file does not matter:

- `storage/` and everything in it but the trace directories has PHP's group, with group write;
  `o-rwx` keeps other users on the server out of the logs.
- Its directories, the trace ones aside, are setgid (`g+s`): a file or directory created in them gets that group, whoever
  creates it.
- Every writer runs with `umask 002`, so what it creates stays group-writable: PHP-FPM through
  `UMask=` (step 6), your interactive shell through `~/.profile` (step 5), each cron job and each
  command a deploy script or CI runs over ssh in its own line (below).
- The deploy user is in PHP's group (step 5), so it can write and read there.

A cron line, for example the static sitemap every night:

```text
0 3 * * * umask 002; cd /var/www/site && php scripts/sitemap.php
```

The same from a deploy script or CI:

```bash
ssh deploy@example.com 'umask 002; cd /var/www/site && php scripts/sitemap.php'
```

`~/.profile` is read only by a login shell, and bash skips it when `~/.bash_profile` exists (then
put the line there); a command run over ssh does not read either, so it needs its own `umask 002;`.

`UMask=` in step 6 applies to the whole PHP-FPM service: every pool and every site it runs on the
server creates group-writable files. Without systemd (the Docker `php:fpm` image, supervisord),
set the umask in the command that starts FPM, for example the container entrypoint:
`umask 0002 && exec php-fpm`.
`weft-health` runs from the command line and cannot see PHP-FPM's umask: check it with the
`systemctl show` line of step 6 (in a container, `grep Umask /proc/$(pgrep -o php-fpm)/status`).

Run every script and cron job as one user, the deploy user (its own `crontab -e`), not as
`www-data` or root. A script run as another user takes `cli/`, and the deploy user's scripts then
lose their traces: only stderr and `errors/` say `fopen(.../cli/.traceloom.lock)`, `weft-health` does
not check `cli/`. `sudo` also runs a command with umask `022`, so what it creates is not
group-writable. To recover, run the `chgrp`, `chmod` and `find` lines of step 5 again.

Run only `weft-health` as the PHP user (step 8); it writes no trace. It checks the permissions of the
user who runs it, so from your own shell it can report `ok: true` for a `storage/` that PHP cannot
write.

If PHP cannot write `storage/`, rate limits stop limiting: the limiter fails open so a broken disk
never locks visitors out, and the line it writes about that goes to `storage/logs/errors/`, which is
not writable either — nothing records it but `weft-health` (`rate_limit_writable: false`).

### What `weft-health` checks

Run it in the site root. It prints a JSON report and exits with 1 when `ok` is false, 0 otherwise.
`config` names the environment config the site loaded (`config/config.php` on a server). `ok` is
false when any of these is bad:

| Field | Bad value | What to do |
|---|---|---|
| `production` | not empty | it names settings for a development machine: `DEBUG` or `DEV_MODE` on, `config/config.example.php` loaded (create `config/config.php`), `app.url.base` on example.com, `app.captcha.allowed_hosts` with example.com while the captcha is on, or, with `app.vite.entry` set, a `public/dist/.vite/manifest.json` without that entry (run `npm run build`). Fix each one it names |
| `checks.<name>.ok` | `false` | a check your site added in `app/Bootstrap.php` failed; its other fields say why. This example's `contact_form` lists in `problems`: `MAIL_TO` or `MAIL_FROM` still on example.com, the template's addresses (requests would go to example.com while the visitor sees "Thanks"): set your inbox and an address of your domain; no channel, `MAIL_TO` empty and AlertLoop off: set either; `mail()` not available while it is the only channel. `mail_available` is `false` when `mail()` is disabled (`disable_functions`) or, outside Windows, the program in `sendmail_path` does not exist: install the server's mail transport (sendmail, postfix, msmtp) or set AlertLoop. With either of the last two, every form submission answers 503 (see "The contact form answers 503" below). `contact_form` sends nothing, so `ok: true` does not mean an email arrives: see "Monitoring" |
| `logs_writable` | `false` | `logs_error` names what PHP cannot write: `errors/` or `requests/` under `LOGS_PATH`, today's file in it, or `requests/.traceloom.lock`. Run the `chgrp`, `chmod` and `find` lines of step 5 again, then find who created it: `ls -l` shows the owner. In `errors/`, a writer without `umask 002`: PHP-FPM (check step 6) or another user's cron lines (`sudo crontab -l -u <user>`), scripts, commands over ssh. In `requests/`, anyone but PHP, such as `php -S` started as another user |
| `rate_limit_writable` | `false` | `rate_limit_error` names `rate_limit_path`, or how many files in it PHP cannot write and one of them: the same as for `logs_writable`; with Redis, see below |
| `rate_limit_store` | `file` while `cache_driver` is `redis` | Redis is not used, the cache and counters are per server: see below |
| `proxy_ips_error` | not `null` | `PROXY_IPS` is not an array, so no proxy is trusted: write `define('PROXY_IPS', [...])` |
| `proxy_ips_invalid` | not empty | these `PROXY_IPS` entries are ignored: replace them with IP addresses or CIDR ranges; for `localhost` write `'127.0.0.1'` and/or `'::1'` |
| `proxy_ips_trust_all` | not empty | these entries are too wide for a proxy (`0.0.0.0/0`, `::/0`, an IPv4 range wider than `/8`, an IPv6 one wider than `/16`): list only your proxies (below) |
| `captcha_missing_keys` | not empty, with `DEBUG` off | every form submission is rejected: set the keys or disable the captcha (below) |
| `alertloop_missing_keys` | not empty, with `DEBUG` off | alerts are on but nothing is sent: set the keys or turn alerts off (below) |

With `DEBUG` on, `captcha_missing_keys` and `alertloop_missing_keys` are still listed but do not fail
the check. On a development machine, `vendor/bin/weft-health --dev` lists `production` without
failing on it; every other field counts as on a server.

`captcha_missing_keys` names keys of `app.captcha`: `site_key`, `secret_key`. In this example they
come from `RECAPTCHA_SITEKEY` and `RECAPTCHA_SECRETKEY` in `config/config.php`, and the captcha is
on whenever `RECAPTCHA_SECRETKEY` is not empty. To disable it, set `RECAPTCHA_SECRETKEY` to `''`; on
a site with its own config, set `app.captcha.enabled` to `false`.

`alertloop_missing_keys` names keys of `app.alertloop`: `base_url`, `api_key`. They come from
`ALERTLOOP_BASE_URL` and `ALERTLOOP_API_KEY` in `config/config.php`, and alerts are on whenever
`ALERTLOOP_API_KEY` is not empty. To turn them off, set `ALERTLOOP_API_KEY` to `''`.

`rate_limit_path` is `app.rate_limit.path`, else `ratelimit/` in `app.cache.path`
(`storage/cache/ratelimit` by default); a relative one is taken from the site root (`APP_ROOT`), so
`weft-health` and the site use the same directory whatever their working directory. A counter file in
it that PHP cannot overwrite (created by a script without `umask 002`) also makes
`rate_limit_writable` false; `rate_limit_error` names one of them.

With `app.cache.driver` set to `redis`, the engine sends `PING` after connecting, and `weft-health`
writes a probe counter to Redis (ten-second TTL, deleted at once).

| `rate_limit_store` | `rate_limit_error` contains | Cause |
|---|---|---|
| `file` | `Class "Redis" not found` | ext-redis is not installed |
| `file` | a connection error, such as `Connection refused` | Redis is not reachable at `REDIS_HOST`:`REDIS_PORT` |
| `file` | `NOAUTH` | Redis requires a password and `REDIS_PASSWORD` is empty |
| `file` | `WRONGPASS` | `REDIS_PASSWORD` is wrong |
| `file` | `called without any password configured` | `REDIS_PASSWORD` is set, but Redis has no password: set it to `''` |
| `file` | `Redis AUTH failed` (Redis 5 and older) | `REDIS_PASSWORD` is wrong, or Redis has no password: then set it to `''` |
| `file` | `DB index is out of range` | `REDIS_DB` is higher than Redis allows (16 databases by default) |
| `redis` | `Redis rejected the probe write` | a read-only replica, `maxmemory` reached under the `noeviction` policy, or a Redis user not allowed to run `EVAL` |

When Redis is not used, the cache falls back to files as well. The cache and the counters share one
connection per request. If Redis does not answer, every request that uses the cache, a rate limit or
an alert with a dedupe key waits for it once, up to 2 seconds to connect and 1 second for a reply,
until Redis is back; so does `weft-health`. In the `redis` row pages are still served: a cache read or
write Redis rejects is skipped, and a form submission whose counter it rejects is allowed.

The site logs the reason to `errors/<date>.log` under `LOGS_PATH`, in lines like these (time in
`APP_TIMEZONE`):

```text
[2026-10-08 12:00:00] RATE_LIMIT ERRNO:500 | Redis unavailable, rate limits fall back to files: <reason> | …
[2026-10-08 12:00:00] CACHE ERRNO:500 | Cache init failed, falling back to file: <reason> | …
[2026-10-08 12:00:00] CACHE ERRNO:500 | Cache store failed, continuing without cache: <reason> | …
[2026-10-08 12:00:00] RATE_LIMIT ERRNO:500 | Rate limit failed open: <reason> | …
```

The first line comes from form submissions and `weft-health`, the second from page views; both mean
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

## HTTPS and HSTS

HSTS (`app.security.hsts`) is off by default. Once on, every response tells the browser to open the
domain only over HTTPS for `hsts_max_age` seconds (default `31536000`, a year). A browser that has
seen the header refuses plain HTTP and certificate warnings for the domain until that time runs out.
Turning HSTS off does not shorten it: the header stops coming, and browsers keep the time they got.

- Turn it on only when every page of the domain answers over HTTPS with a certificate that renews
  itself.
- Start with `'hsts_max_age' => 300`, check the site in a browser, then raise it to `31536000`.
- `'hsts_subdomains' => true` adds `includeSubDomains`: then every subdomain (`crm.`, `mail.`, a
  hosting panel) has to serve HTTPS too, or browsers that visited the site stop opening it. Leave it
  `false` unless all of them do.
- To take HSTS back, keep `'hsts' => true` and set `'hsts_max_age' => 0` in `app/Configs/app.php`:
  Weft then sends `max-age=0`, and a browser that opens the site over HTTPS forgets the rule. Leave
  it so until the old `hsts_max_age` has passed since the change, then turn `hsts` off. A browser
  that does not come back in that time keeps the rule until its own time runs out.

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
(below). `weft-health` lists such entries under `proxy_ips_invalid`. It also fails on `0.0.0.0/0` and
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

### HTTPS behind a proxy

The session cookie gets `Secure` when the request came over HTTPS (`Request::isHttps()`): for a
request from a proxy in `PROXY_IPS`, its `X-Forwarded-Proto` header says so; otherwise PHP does
(`$_SERVER['HTTPS']` set and not `off`). When neither says HTTPS, the site and its forms work, and
the cookie goes without `Secure`, with no error anywhere.

- **A proxy or CDN listed in `PROXY_IPS`** (above) — nothing more to do, as long as it sends
  `X-Forwarded-Proto`. Cloudflare does; in your own nginx that ends HTTPS, set
  `proxy_set_header X-Forwarded-Proto $scheme;` in the `location` that proxies to PHP. Of several
  values (`https, http`) Weft takes the last one, written by the nearest proxy. The header from
  any other address is ignored.
- **nginx + PHP-FPM on one machine, the certificate in this nginx** — nothing to do:
  `include fastcgi_params;` passes `fastcgi_param HTTPS $https if_not_empty;`, so PHP gets `on` for
  every HTTPS request. If your config does not include that file, add that line to the
  `location ~ \.php$` block.
- **HTTPS ends earlier, and `PROXY_IPS` is empty** — set `HTTPS=on` for PHP yourself. nginx +
  PHP-FPM: add `fastcgi_param HTTPS on;` to the `location ~ \.php$` block, after
  `include fastcgi_params;`, only when the server in front redirects plain HTTP to HTTPS, so every
  request PHP gets came over HTTPS. Apache: in the virtual host or in `public/.htaccess`, with
  `mod_setenvif` on, add `SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on`.
- **Cloudflare or another CDN** — best, give the server a certificate and let the CDN connect to it
  over HTTPS (Cloudflare: SSL mode "Full (strict)"); then the server sees HTTPS itself.

**Check it** on a page with a form (in the example, `/contact`), from any machine:

```bash
curl -s -D - -o /dev/null https://example.com/contact | grep -i '^set-cookie'
```

The `Set-Cookie` line must contain `secure`.

## Monitoring

Step 8 checks the site once. Two things tell you when it breaks later:

- **Availability** — any uptime monitoring service requesting your home page every few minutes and
  alerting you when the answer is not `200`. It sees what visitors see: DNS, the certificate, the web
  server and PHP.
- **`weft-health` by cron** — in the crontab of the PHP user (`sudo crontab -u www-data -e`; like
  step 8, the one script run as that user), with your address and site root:

```text
MAILTO=you@example.com
*/15 * * * * umask 002; cd /var/www/site && out=$(vendor/bin/weft-health) || echo "$out"
```

cron mails what a job prints, so the report reaches you only when `weft-health` exits with 1; the
server needs working mail for that, as `mail()` does. Without it, use a heartbeat check of your
uptime service instead, which alerts when the pings stop:

```text
*/15 * * * * umask 002; cd /var/www/site && vendor/bin/weft-health > /dev/null && curl -fsS -m 10 https://<your heartbeat URL>
```

When it fails, fix what the report names ("What `weft-health` checks" above), then run step 8 again
until it exits with 0.

Neither of them sees whether the contact form's email arrives. `weft-health` checks the addresses and
that PHP can hand `mail()` a message; the mail server can still refuse it or the email can bounce
later (on Windows, `mail()` is only tried when a message is sent). So after each deploy and after any
change to `MAIL_TO`, `MAIL_FROM` or the server's mail setup, send one request through the form
yourself and check that it reached your inbox (or AlertLoop). If the form answered 503, or the email
did not come, look in the error log:

```bash
grep -h 'not delivered\|not sent' storage/logs/errors/*
```

A line there is explained in "The contact form answers 503" below; no line and no email means the
server's mail transport took the email and did not deliver it: see its own log (for example
`/var/log/mail.log`).

## Updating

- **The site** — `git pull`, `composer install --no-dev`, `npm run build` if the frontend changed.
- **The engine** — `composer update golovanov/weft`, commit the new `composer.lock`, deploy. External
  versions stay pinned by the lock file.

> No Node on the server (common on shared hosting)? Build in CI and deploy `public/dist/` as an
> artefact.

## Logs

```text
storage/logs/
├── requests/<date>.jsonl   web request traces (traceloom): request_start/end, http_out_*, your events
├── cli/<date>.jsonl        traces of console scripts (scripts/*.php, cron jobs): http_out_*, your events
└── errors/<date>.log       errors, exceptions and fatals, of the site and of scripts
```

Traces are line-delimited JSON grouped by `trace_id`; a web request and a script never share one.
Every response carries `X-Trace-Id`, so a user report points straight at a timeline in `requests/`;
a line in `errors/` ends with `trace:<id>`, in `requests/` for the site or `cli/` for a script:

```bash
vendor/bin/eventtrace show <trace_id> --dir=storage/logs/requests   # the site
vendor/bin/eventtrace show <trace_id> --dir=storage/logs/cli        # a script
grep -rl --include='*.jsonl' '<trace_id>' storage/logs                      # which one
```

Request and outgoing bodies and query strings are logged as fields; values under sensitive key
names (password, token, api_key, … plus `app.trace.sensitive_keys`) are redacted. Anything else
your forms collect — an email, a phone number — is logged unless you add its key to
`sensitive_keys`. Setting `app.trace.log_body` to `false` logs a body as its size and content type
only (a body over 8 KB is always logged so), but not the fields your code writes to its own events with `Trace::event()` — the example's
`contact_submit` records the email; only `sensitive_keys` masks those. A body that neither decodes
as JSON nor is a form is logged as a raw string, and so is a form string your code builds
itself for `HttpClient`: pass the fields as an array so their sensitive keys are masked. The `referer` header is logged as sent:
within your site it is the full previous URL, query string included, and masking does not reach
it. Trace files rotate by date and
size and are deleted after `app.trace.retention_days` (30 by default; `0` keeps them forever), in
both directories: the site cleans `requests/`, a script cleans `cli/` when it writes a trace. The
first request after an engine update already deletes older files: set the key before updating if you
need them.
`errors/` is not cleaned up: rotate it at the OS level.

### The contact form answers 503

`503` means neither AlertLoop nor `mail()` took the request. Each one leaves `Contact request not
delivered` in `storage/logs/errors/`, ending with `trace:<id>`. A line with the same trace id,
`Contact email to … not sent: mail() failed`, means PHP could not hand the email to the server's
mail transport (`sendmail_path` in `php.ini`, or your host's mail setup); with `MAIL_TO` empty there
is no such line. Why AlertLoop did not take it is in the `alert.failed` event of that trace:
`status` `401` is a key AlertLoop refuses, `0` with `transport_error: true` is AlertLoop not reached.
`eventtrace show` lists the event without these fields, so read the raw line:

```bash
grep -h '<trace_id>' storage/logs/requests/*.jsonl | grep alert.failed
```

No `alert.failed` event means AlertLoop is off or lacks a key: see `alertloop_enabled` and
`alertloop_missing_keys` in `vendor/bin/weft-health`. To fix it, set `MAIL_TO` on a server where
`mail()` works, or `ALERTLOOP_BASE_URL` and `ALERTLOOP_API_KEY` (see
[Configuration](/docs/configuration)). The visitor's message stays in the `request_start` event of
the same trace, among the form fields, only while the request body is at most 8 KB. A long message
goes over that within the form's 4,000-character limit, especially one not in Latin script (about
3,600 Cyrillic characters do): then, as with `app.trace.log_body` set to `false`, it is kept nowhere.

## Checklist

- [ ] `DEBUG=false`, `DEV_MODE=false`
- [ ] `app.url.base` is the real `https://` domain
- [ ] document root points at `public/`
- [ ] `composer install --no-dev --optimize-autoloader` done
- [ ] `npm run build` done, `public/dist/` present
- [ ] `storage/` has PHP's group and setgid directories; PHP-FPM (`UMask=0002`), cron jobs, deploy
  commands and your shell run with `umask 002` (steps 5 and 6)
- [ ] `client_ip` in the newest `request_start` trace is your own address, not a proxy's (see
  "Behind a proxy or CDN" above); otherwise all visitors share one rate limit
- [ ] over HTTPS the session cookie has `Secure`: `Set-Cookie` of a page with a form contains
  `secure` (see "HTTPS behind a proxy" above)
- [ ] HTTPS on for every page; HSTS (`app.security.hsts`) only after reading "HTTPS and HSTS" above,
  and `hsts_subdomains` only if every subdomain serves HTTPS
- [ ] keys set for whichever of captcha / alerts / mail you use; a captcha enabled with a key missing
  passes forms while `DEBUG` is on and rejects every submission with it off, alerts without the
  address send nothing — `weft-health` lists the missing keys under `captcha_missing_keys` and
  `alertloop_missing_keys`
- [ ] `app.captcha.allowed_hosts` lists your real domain, if the captcha is on
- [ ] the contact form has a channel: `MAIL_TO` (your inbox, not example.com) with a working
  `mail()`, or AlertLoop; otherwise every submission answers 503 and leaves `Contact request not
  delivered` in `storage/logs/errors/` (see "The contact form answers 503" above)
- [ ] a test request sent through the contact form reached you, after this deploy and after every
  change of the mail settings: `weft-health` does not check delivery (see "Monitoring")
- [ ] `sudo -u www-data sh -c 'umask 002; vendor/bin/weft-health'` (your PHP user) → `ok: true`,
  exit code 0
- [ ] an uptime service watches the home page, and `weft-health` runs from cron (see "Monitoring")

That is the whole engine. If something is missing from these docs, please
[open an issue](https://github.com/golovanov-dev/weft/issues).
