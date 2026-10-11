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

Not shipped: `node_modules/`, the local `config/config.php` and `config/config.dev.php` (the
server has its own), `storage/` (step 5 sets it up on the server). `frontend/` is only needed to
build. Without SSH or Node on the server, see "Shared hosting without SSH or Node" below.

## Steps

```bash
# 1. Code on the server (git pull / rsync)
#    rsync runs on your machine, after step 4 there: it ships public/dist/, not frontend/.
#    --delete removes what you deleted locally (a deleted page stops answering); the excludes keep
#    what the server owns: storage/, its config files and the static sitemap of step 7. Any other
#    file put straight into public/ on the server (a search engine's verification file) is deleted:
#    keep such files in the site's repository.
#    rsync -a --delete --exclude=/storage/ --exclude=/config/config.php --exclude=/config/config.dev.php \
#      --exclude=/public/sitemap.xml --exclude=/.git/ --exclude=/node_modules/ --exclude=/frontend/ \
#      ./ deploy@example.com:/var/www/site/

# 2. PHP dependencies, production flavour
composer install --no-dev --optimize-autoloader

# 3. Real environment: secrets, readable only by you and PHP's group (www-data, see below)
cp config/config.example.php config/config.php
sudo chgrp www-data config/config.php && chmod 640 config/config.php
#    DEBUG=false, DEV_MODE=false, BASE_URI='', APP_TIMEZONE, plus any keys you use;
#    MAIL_TO (your inbox) and MAIL_FROM (an address of your domain) for the contact form;
#    MAIL_DRIVER='smtp' and SMTP_* where PHP mail() does not work (no sendmail, shared hosting);
#    for alerts, any of ALERTLOOP_*, TELEGRAM_* or ALERT_MAIL_TO; INDEXNOW_KEY for IndexNow.
#    Your https domain (app.url.base) is not set here: it is $origin at the top of
#    app/Configs/app.php, which you change on your machine and commit, so every deploy brings it

# 4. Frontend: with git pull, here (needs Node); with rsync, on your machine or in CI before step 1
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
user who deploys the code; steps 5, 6 and 8 need `sudo`. The `rsync` line of step 1 and, with
rsync, step 4 are the exception: run them on your machine, in the site's directory, with your deploy
user, host and site root in the `rsync` line.
Keep a copy of the finished `config/config.php` off the server (a password manager): it is in no
repository, and a rebuilt server needs it. Substitute your PHP user and its group for `www-data`: they are `user` and `group` of the PHP-FPM
pool (for example `/etc/php/8.4/fpm/pool.d/www.conf`) or the user Apache runs as. Substitute your PHP-FPM service for
`php8.4-fpm` (`systemctl list-units '*fpm*'` shows it); with Apache's mod_php, use `apache2` (or
`httpd`) in step 6 instead. With `opcache.validate_timestamps=0` in PHP-FPM's `php.ini`, PHP keeps
running the old code until you reload it after every deploy: `sudo systemctl reload php8.4-fpm`. In step 5, substitute your `LOGS_PATH` for `/var/log/site`.

> **Non-negotiable in production:** `DEBUG=false` and `DEV_MODE=false`. Otherwise assets are fetched
> from a Vite dev server that is not running, and errors are shown to visitors.

### Who writes `storage/`

PHP writes the error log, traces, the file cache and rate-limit counters under `storage/` (counters
live in `storage/cache/ratelimit/` even with caching off, unless `app.rate_limit.path` points
elsewhere; `cache-clear.php` leaves them unless that path is the cache directory itself: compare
`rate_limit_path` in `vendor/bin/weft-health` with `app.cache.path` in `app/Configs/app.php`).
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

`weft-health` checks only the directories (`errors/` and `requests/` under `LOGS_PATH`, the counter
directory), not the files in them. A file another user created without `umask 002`, such as today's
`errors/<date>.log` or a rate-limit counter, passes it while PHP cannot write it: errors and traces of
that day are lost, or the counter stops limiting with `Rate limit failed open: counter not writable`
in `errors/`. Find such files with `find storage ! -perm -g+w` (and your `LOGS_PATH`, if you moved
it) and fix them with the lines of step 5.

### What `weft-health` checks

Run it in the site root. It prints a JSON report and exits with 1 when `ok` is false, 0 otherwise.
`config` names the environment config the site loaded (`config/config.php` on a server). It runs on
PHP's command-line `php.ini`; PHP-FPM reads its own (`/etc/php/8.4/fpm/php.ini`), so only a test
request through the form proves it can send (step 8). `ok` is false when any of these is bad:

| Field | Bad value | What to do |
|---|---|---|
| `production` | not empty | it names settings for a development machine: `DEBUG` or `DEV_MODE` on, `config/config.example.php` loaded (create `config/config.php`), `app.url.base` on example.com, or, with `app.vite.entry` set, a `public/dist/.vite/manifest.json` without that entry (run `npm run build`). Fix each one it names |
| `checks.<name>.ok` | `false` | a check your site added in `app/Bootstrap.php` failed; its other fields say why. This example delivers a request only by email, and its `contact_form` lists in `problems`: `MAIL_TO is empty`; `MAIL_TO is on example.com`, the template's address: set your inbox; `MAIL_TO is not an email address`, an entry is not a plain address, such as `Name <a@b>`: write bare addresses, several separated by commas. Set `MAIL_FROM` to an address of your domain too: mail sent from example.com is refused. `contact_form` sends nothing, so `ok: true` does not mean an email arrives: see "Monitoring" |
| `logs_writable` | `false` | `logs_error` names what PHP cannot write: `errors/` or `requests/` under `LOGS_PATH`. Run the `chgrp`, `chmod` and `find` lines of step 5 again, then find who created it: `ls -l` shows the owner. In `errors/`, a writer without `umask 002`: PHP-FPM (check step 6) or another user's cron lines (`sudo crontab -l -u <user>`), scripts, commands over ssh. In `requests/`, anyone but PHP, such as `php -S` started as another user. Files inside are not checked: see "Who writes `storage/`" |
| `rate_limit_writable` | `false` | `rate_limit_error` names `rate_limit_path`: the same as for `logs_writable`. Counter files inside are not checked: a line `Rate limit failed open` in `errors/` names one |
| `rate_limit_store` | `file` while `cache_driver` is `redis` | Redis is not used, the cache and counters are per server: see below |
| `proxy_ips_errors` | not empty | `PROXY_IPS` is not an array (no proxy is trusted: write `define('PROXY_IPS', [...])`), an entry is ignored (not an IP address or CIDR range; for `localhost` write `'127.0.0.1'` and/or `'::1'`), or an entry is too wide for a proxy (`0.0.0.0/0`, `::/0`, an IPv4 range wider than `/8`, an IPv6 one wider than `/16`): list only your proxies (below) |
| `captcha_missing_keys` | not empty | every form submission is rejected: set the keys or disable the captcha (below) |
| `alert_errors` | not empty | a channel in `alert_channels` lacks a setting and is skipped: set it or remove the channel (below) |
| `mail_errors` | not empty | `app.mail` cannot send: set what it names (below) |
| `seo_errors` | not empty | a `robots.txt` / `llms.txt` file in `public/` (in `WEB_ROOT`, when `config/config.php` sets it) that the web server serves instead of `app.seo.robots_groups` / `app.llms`: delete the file |

On a development machine, `vendor/bin/weft-health --dev` lists `production` without failing on it;
every other field counts as on a server.

`captcha_missing_keys` names keys of `app.captcha`: `site_key`, `secret_key`, and `driver` when it is
not `recaptcha` or `smartcaptcha`. In this example the keys come from `CAPTCHA_SITE_KEY` and
`CAPTCHA_SECRET_KEY` in `config/config.php`, and the captcha is on whenever `CAPTCHA_SECRET_KEY` is
not empty. To disable it, set `CAPTCHA_SECRET_KEY` to `''`; on a site with its own config, set
`app.captcha.enabled` to `false`.

`alert_channels` is `app.alerts.channels`; `alert_errors` names what a listed channel lacks:
`app.alertloop.base_url` or `api_key`, `app.alerts.telegram.bot_token` or `chat_id`,
`app.alerts.mail_to` and, for `mail`, the `app.mail` settings. In this example a channel is listed
when `ALERTLOOP_API_KEY`, `TELEGRAM_BOT_TOKEN` or `ALERT_MAIL_TO` is set, and the rest comes from
`ALERTLOOP_BASE_URL` and `TELEGRAM_CHAT_ID`. To turn a channel off, set its constant to `''`.

`mail_errors` names `app.mail.from` (empty or not a plain address), `app.mail.driver` (not `mail`
or `smtp`), and, with `smtp`, `app.mail.smtp.host` or `encryption`. In this example they come from
`MAIL_FROM`, `MAIL_DRIVER` and `SMTP_*`. `mail_driver` shows the driver in use.
`rate_limit_path` is `app.rate_limit.path`, else `ratelimit/` in `app.cache.path`
(`storage/cache/ratelimit` by default); a relative one is taken from the site root (`APP_ROOT`), so
`weft-health` and the site use the same directory whatever their working directory.

With `app.cache.driver` set to `redis`, the engine sends `PING` after connecting; when Redis fails,
the request goes on with files.

| `rate_limit_store` | `rate_limit_error` contains | Cause |
|---|---|---|
| `file` | `Class "Redis" not found` | ext-redis is not installed |
| `file` | a connection error, such as `Connection refused` | Redis is not reachable at `REDIS_HOST`:`REDIS_PORT` |
| `file` | `NOAUTH` | Redis requires a password and `REDIS_PASSWORD` is empty |
| `file` | `WRONGPASS` | `REDIS_PASSWORD` is wrong |
| `file` | `called without any password configured` | `REDIS_PASSWORD` is set, but Redis has no password: set it to `''` |
| `file` | `Redis AUTH failed` (Redis 5 and older) | `REDIS_PASSWORD` is wrong, or Redis has no password: then set it to `''` |
| `file` | `socket error on read socket` or `read error on connection` | Redis accepted the connection and did not answer within a second: overloaded, or a firewall drops the traffic |
| `file` | `DB index is out of range` | `REDIS_DB` is higher than Redis allows (16 databases by default) |

When Redis is not used, the cache falls back to files as well. The cache and the counters share one
connection per request. If Redis does not answer, every request that uses the cache, a rate limit or
an alert with a dedupe key waits for it once, up to 2 seconds to connect and 1 second for a reply,
until Redis is back; so does `weft-health`. A Redis that takes the connection but rejects commands
(a read-only replica, `maxmemory` under `noeviction`) still serves pages: a cache read or write it
rejects is skipped, and a form submission whose counter it rejects is allowed. `weft-health` does not
see that (`rate_limit_store: redis`); only the `Rate limit failed open` lines below do.

The site logs the reason to `errors/<date>.log` under `LOGS_PATH`, in lines like these (time in
`APP_TIMEZONE`):

```text
[2026-10-08 12:00:00] RATE_LIMIT ERRNO:500 | Redis unavailable, rate limits fall back to files: <reason> | …
[2026-10-08 12:00:00] CACHE ERRNO:500 | Cache init failed, falling back to file: <reason> | …
[2026-10-08 12:00:00] CACHE ERRNO:500 | Cache store failed, continuing without cache: <reason> | …
[2026-10-08 12:00:00] RATE_LIMIT ERRNO:500 | Rate limit failed open: <reason> | …
```

The first line comes from form submissions and `weft-health`, the second from page views; both mean
Redis is not used. The last two mean Redis rejects commands, as described above.

## Document root is `public/`

Only `public/` is exposed. `config/` with your secrets, `app/`, `vendor/` and `storage/` with logs stay
**above** the document root and are unreachable from the web.

### Apache

The bundled `public/.htaccess` sends everything to `index.php` and serves existing files as-is. It
also carries commented-out rules for forcing HTTPS and dropping `www.` (see "Redirects: HTTPS and
www" below). Make sure `AllowOverride All` is enabled.

### Nginx

```nginx
server {
    listen 443 ssl;
    server_name example.com;
    root /var/www/site/public;      # public/ only
    index index.php;

    # Your certificate and its key, for example certbot's for example.com:
    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ^~ /.well-known/acme-challenge/ { try_files $uri =404; }   # certificate renewal (certbot --webroot)
    location ~ /\. { deny all; }    # .htaccess, .git and other dotfiles
}
```

**The first certificate.** nginx refuses this block until the certificate files exist. Get them
first: on the server, put this block alone in the site's nginx config (your domain and site root),
then run as a user with `sudo`:

```nginx
server {
    listen 80;
    server_name example.com www.example.com;
    root /var/www/site/public;
    location ^~ /.well-known/acme-challenge/ { try_files $uri =404; }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/site/public -d example.com -d www.example.com \
  --deploy-hook 'systemctl reload nginx'
```

`-w` is the site's `public/`, the same `root` as above. Then replace the block with the one above and
those of "Redirects: HTTPS and www", and run `sudo nginx -t && sudo systemctl reload nginx` again.
certbot's timer renews the certificate the same way, through `/.well-known/acme-challenge/`, and
the deploy hook reloads nginx after each renewal: without it nginx keeps serving the old certificate
until it expires. certbot saves the hook for renewals; check both:

```bash
sudo certbot renew --dry-run      # renewal works through the webroot (a dry run skips the hook)
sudo grep renew_hook /etc/letsencrypt/renewal/example.com.conf   # renew_hook = systemctl reload nginx
```

## Shared hosting without SSH or Node

Build on your machine, upload the result with the hosting panel's file manager or SFTP, and let
the panel's cron run the checks.

1. On your machine, in the site's directory:

   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   ```

2. Upload what "What ships" lists, `vendor/` and `public/dist/` included, to a directory of your
   account (`/home/<user>/site` below; the file manager shows the full path). Create
   `storage/logs/` and `storage/cache/` there: PHP runs as your account, so they need no further
   permissions.
3. Create `config/config.php` from `config/config.example.php` on your machine (step 3 of "Steps"),
   upload it once, and give it permissions `600` in the file manager.
4. Point the domain's document root at `/home/<user>/site/public` in the panel. If the panel does not
   let you change it (a fixed `public_html/`): copy the contents of `public/` into `public_html/`, in
   `public_html/index.php` change `dirname(__DIR__) . '/bootstrap.php'` to
   `dirname(__DIR__) . '/site/bootstrap.php'`, and add
   `define('WEB_ROOT', '/home/<user>/public_html');` to `config/config.php`, so that `weft-health`
   looks for the Vite manifest and a static `robots.txt` there. Copy `public/dist/` again after every
   build.
5. Add a cron task in the panel, every 15 minutes, with the panel's PHP 8.4 binary:

   ```text
   cd /home/<user>/site && out=$(/usr/bin/php8.4 vendor/bin/weft-health 2>&1) || echo "$out"
   ```

   It prints only when the check fails, and the panel mails (or logs) what a task prints. For the
   first check, add a one-off task without the `out=…` wrapper and read its output.

To update, build again and upload what changed. A file you deleted locally stays on the server (a
deleted page keeps answering) until you delete it there. Run the SEO audit from your machine.

## Redirects: HTTPS and www

Weft does not redirect between `http://` and `https://` or `www.` and the bare domain: the web
server does, before PHP. Pick one address, the one in `app.url.base`, and send the other three
there with a single 301.

Apache, in `public/.htaccess` instead of its two commented-out rules, for `https://example.com`:

```apache
RewriteCond %{HTTPS} off [OR]
RewriteCond %{HTTP_HOST} ^www\. [NC]
RewriteRule ^ https://example.com%{REQUEST_URI} [L,R=301]
```

nginx, next to the `server` block of "Nginx" above, with the same certificate, which must cover
both names:

```nginx
server {
    listen 80;
    server_name example.com www.example.com;
    return 301 https://example.com$request_uri;
}
server {
    listen 443 ssl;
    server_name www.example.com;
    ssl_certificate     /etc/letsencrypt/live/example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/example.com/privkey.pem;
    return 301 https://example.com$request_uri;
}
```

**Behind a proxy or CDN that ends HTTPS** (Cloudflare "Flexible", a hosting's front server), Apache
sees every request as plain HTTP, so `%{HTTPS} off` is always true and the rule above redirects
forever (the browser shows "too many redirects"). Redirect at the CDN instead (Cloudflare: "Always
Use HTTPS"), or give the server a certificate and let the CDN connect over HTTPS (Cloudflare:
"Full (strict)"), or test what the proxy says: replace `RewriteCond %{HTTPS} off` with
`RewriteCond %{HTTP:X-Forwarded-Proto} !https`.

**Old addresses.** When a site moves to Weft and its paths change, send each old path to its new
one with a 301, so links and search results keep working. Apache, before the rules above:

```apache
RewriteRule ^about-us\.html$ https://example.com/about [L,R=301]
RewriteRule ^blog/\d+-([a-z0-9-]+)\.html$ https://example.com/blog/$1 [L,R=301]
```

nginx, in the `server` block of the site:

```nginx
location = /about-us.html { return 301 /about; }
rewrite ^/blog/\d+-([a-z0-9-]+)\.html$ /blog/$1 permanent;
```

**Check it** from any machine, with your domain:

```bash
curl -sI http://example.com/about | grep -i '^location'           # https://example.com/about
curl -sI https://www.example.com/about | grep -i '^location'      # https://example.com/about
curl -sI https://example.com/about | head -n 1                    # 200, no Location
curl -sI https://example.com/about-us.html | grep -i '^location'  # an old address: its new one
```

Each must take one step; a `Location` that leads back to the same address is the loop above.

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
(below). `weft-health` lists such entries under `proxy_ips_errors`. It also fails on `0.0.0.0/0` and
`::/0`: every address then counts as a proxy, the client IP is always the
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
- **A CDN in front of your own nginx** (Cloudflare → nginx → Apache or PHP) — in that nginx,
  `$scheme` is how the CDN reached it (`http` with Cloudflare "Flexible") and replaces the CDN's
  `https`: pass the CDN's value on instead, `proxy_set_header X-Forwarded-Proto
  $http_x_forwarded_proto;`, and list both the CDN ranges and the nginx in `PROXY_IPS`.
- **nginx + PHP-FPM on one machine, the certificate in this nginx** — nothing to do:
  `include fastcgi_params;` passes `fastcgi_param HTTPS $https if_not_empty;`, so PHP gets `on` for
  every HTTPS request. If your config does not include that file, add that line to the
  `location ~ \.php$` block.
- **HTTPS ends earlier, and `PROXY_IPS` is empty** — set `HTTPS=on` for PHP yourself. nginx +
  PHP-FPM: add `fastcgi_param HTTPS on;` to the `location ~ \.php$` block, after
  `include fastcgi_params;`, only when the server in front redirects plain HTTP to HTTPS, so every
  request PHP gets came over HTTPS. Apache: in the virtual host or in `public/.htaccess`, with
  `mod_setenvif` on, add `SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on`. Apache believes this
  header from anyone, so use it only when Apache cannot be reached except through that proxy.
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
server needs its own working mail for that (cron does not use `MAIL_DRIVER`). Without it, use a heartbeat check of your
uptime service instead, which alerts when the pings stop:

```text
*/15 * * * * umask 002; cd /var/www/site && vendor/bin/weft-health > /dev/null && curl -fsS -m 10 https://<your heartbeat URL>
```

When it fails, fix what the report names ("What `weft-health` checks" above), then run step 8 again
until it exits with 0.

Neither of them sees whether the contact form's email arrives. `weft-health` checks the addresses and
the mail settings only; `mail()` is only tried and an SMTP server only reached when a message is
sent, and the mail server can still refuse the email or it can bounce later. So after each deploy and
after any change to `MAIL_TO`, `MAIL_FROM`, `MAIL_DRIVER`, `SMTP_*` or the server's mail setup, send
one request through the form yourself and check that it reached your inbox. An alert comes either way
(`Contact request not delivered` when the email was not accepted), so it proves nothing. If the form
answered 503, or the email did not come, look in the error log:

```bash
grep -rh 'not delivered\|not sent' storage/logs/errors/   # your LOGS_PATH/errors/ if you moved it
```

No output means no such line.

A line there is explained in "The contact form answers 503" below; no line and no email means the
mail server took the email and did not deliver it: see its own log (for example
`/var/log/mail.log`, or your mail provider's sent folder).

## IndexNow

With `INDEXNOW_KEY` set and your domain in `app.url.base` deployed (on example.com the command
refuses to run), open `https://<your domain>/<key>.txt`: it must show the key. Then, on the server,
in the site root, as the deploy user, like every script but `weft-health` (see "Who writes
`storage/`"):

```bash
vendor/bin/weft-indexnow --dry-run   # lists what the first run submits
vendor/bin/weft-indexnow             # submits; exit 1 if refused
```

Then run it hourly from cron, in the deploy user's crontab (`crontab -e`):

```text
MAILTO=you@example.com
0 * * * * umask 002; cd /var/www/site && out=$(vendor/bin/weft-indexnow 2>&1) || echo "$out"
```

On shared hosting without SSH, add an hourly cron task in the hosting panel instead. Substitute the
panel's PHP 8.4 binary and the full path of your site root (the panel's file manager shows it):

```text
cd /home/<user>/site && out=$(/usr/bin/php8.4 vendor/bin/weft-indexnow 2>&1) || echo "$out"
```

A panel mails (or logs) whatever a task prints; this line prints only when the command fails, so
a message from the task means IndexNow refused or could not run. For the first check, add a
one-off task with `--dry-run` and without the `out=…` wrapper, and read its output where the panel
shows it (the mail to the account, or the task's log). What was submitted is kept in
`storage/indexnow.json` (`app.indexnow.state`); delete it and the next run submits every address.

An address is submitted again only when its `lastmod` in the sitemap changes. Without `lastmod` it
is submitted once, when it is new, and never after an edit; the command prints how many such
addresses there are. To have edits submitted, give the routes a `lastmod`
([Configuration](/docs/configuration), "Sitemap").

## SEO audit

On your machine (or in CI), in your site's directory after `composer install`, check the live site:

```bash
vendor/bin/weft-seo-audit https://example.com    # your domain: the pages in its sitemap.xml
```

It exits with 0 when every page passes; each other line names a page and its problems
([Features](/docs/features), "SEO audit"). It needs PHP's `dom` extension on that machine. For a
site behind Basic auth, set `WEFT_AUDIT_AUTH='user:pass'` in the command's environment.

## Updating

### The site

Deploy as in step 1 (with rsync, build first as in step 4), then on the server, in the site root,
as the deploy user:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build      # with git pull, if the frontend changed
```

With `opcache.validate_timestamps=0`, reload PHP-FPM afterwards (see "Steps").

### The engine

On your machine, in the site's directory:

1. Prepare the rollback. If `/composer.lock` is in your `.gitignore`, delete that line and commit
   `.gitignore` and `composer.lock`: rolling back reinstalls the versions in the committed lock. Save
   a copy of each `config/config.php` as it is now, the server's and yours, outside the site
   directory (`--delete` in step 1 of "Steps" removes a copy kept in `config/`): rolling back restores these
   copies; `app/Configs/app.php` and the rest of the code come back with `git revert`.
2. Read the new version's section of the
   [CHANGELOG](https://github.com/golovanov-dev/weft/blob/main/CHANGELOG.md) and do what each
   entry marked **Action.** says, in every section: most say which sites they apply to. A setting
   under an old name is not an error: its feature just turns off. Commit the changes.
3. Compare your config's constants with the example's template of the new version (here `v0.2.0`);
   config files are in no repository, so nothing updates them. Use the config the site runs with on
   your machine (`config/config.php`, or `config/config.dev.php` if there is no `config.php`):

   ```bash
   diff <(grep -o "^define('[A-Z_]*'" config/config.php | sort)      <(curl -s https://raw.githubusercontent.com/golovanov-dev/weft/v0.2.0/example/config/config.example.php | grep -o "^define('[A-Z_]*'" | sort)
   ```

   A `>` line is a constant the template sets and your config lacks: add it. A new constant works
   only through the block of `app/Configs/app.php` that reads it; the CHANGELOG entry naming the
   constant says which block to take. Constants the template only shows commented out
   (`// define(...)`) are optional and not listed; add one only when its comment says you need it.
   A `<` line is a constant of your own or one the CHANGELOG renames: rename that one in place,
   keeping its value (the template's value is empty), and the `>` line of its new name goes away.
4. Update and check:

   ```bash
   composer require golovanov/weft:^0.2 -W   # -W also updates traceloom, which 0.2 needs at ^0.5
   vendor/bin/weft-health --dev
   ```

   `composer update` stays within the minor version you have: for its patch release,
   `composer update golovanov/weft` is enough. Commit `composer.json` and `composer.lock`.
5. Make the config changes of steps 2 and 3 in the server's `config/config.php`, in the site root
   there: first rename the constants the CHANGELOG renames, keeping the server's values
   (`CAPTCHA_SECRET_KEY` takes the value of `RECAPTCHA_SECRETKEY`, `CAPTCHA_SITE_KEY` that of
   `RECAPTCHA_SITEKEY`), then run the same `diff` and add each `>` constant. Deploy, then run step 8
   of "Steps" on the server: exit code 0, `captcha_enabled: true` if your site uses the captcha (false
   means a captcha key was added empty instead of renamed), and one request through the contact form
   that reaches you.

To roll back, revert the commits of steps 4 and 2, newest first, in one command:
`git revert --no-edit <commit of step 4> <commit of step 2>` (both change `app/Configs/app.php`;
the other order stops on a conflict there). Deploy again with
steps 1 and 2 of "Steps" (`composer install` puts back the versions in the reverted
`composer.lock`, traceloom included), and put back the copies of `config/config.php` saved in
step 1, on the server and on your machine. Then run the health check of the version you returned
to on the server (from 0.1.x, `php scripts/health.php`): exit code 0, and `captcha_enabled: true`
if your site uses the captcha.

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
`contact_submit` records the email; their keys are masked the same way, by the default names and
`sensitive_keys`. A body that neither decodes
as JSON nor is a form is logged as a raw string, and so is a form string your code builds
itself for `HttpClient`: pass the fields as an array so their sensitive keys are masked. The `referer` header is logged as sent:
within your site it is the full previous URL, query string included, and masking does not reach
it. Trace files rotate by date and
size and are deleted after `app.trace.retention_days` (30 by default; `0` keeps them forever), in
both directories: the site cleans `requests/`, a script cleans `cli/` when it writes a trace. The
first request after an engine update already deletes older files: set the key before updating if you
need them.
`errors/` is not cleaned up by Weft: delete old files from cron, in the deploy user's crontab
(30 days here; substitute your `LOGS_PATH` and site root):

```text
0 4 * * * find /var/www/site/storage/logs/errors -name '*.log' -mtime +30 -delete
```

### The contact form answers 503

`503` means the email was not accepted: an alert does not deliver a request. Each such request leaves
`Contact request not delivered` in `storage/logs/errors/`, ending with `trace:<id>`. A line with the
same trace id, `Mail not sent (<driver>): <reason>`, says why the email did not go: `mail() failed`
(PHP could not hand it to the server's mail transport: `sendmail_path` in `php.ini`, or your host's
mail setup), `mail() is not available` (with `MAIL_DRIVER` `mail`, PHP-FPM's `php.ini` disables
`mail()` or names no existing `sendmail_path` program: install a mail transport or switch to `smtp`),
`SMTP … refused: <the server's reply>` (for example `AUTH` with a wrong
`SMTP_USERNAME` or `SMTP_PASSWORD`), `SMTP connection to … failed` or `SMTP timed out` (wrong
`SMTP_HOST` or `SMTP_PORT`, the port is blocked, or `SMTP_ENCRYPTION` does not match the port:
465 takes `ssl`, 587 `starttls`; `wrong version number` in the reason is that too),
`certificate verify failed` after `SMTP connection to ssl://…` or `SMTP STARTTLS handshake failed`
(the certificate is for another name than `SMTP_HOST`, or PHP has no CA bundle to check it: set
`openssl.cafile` in `php.ini`), or a mail setting that is missing. With
`MAIL_TO` empty there is no such line.

Alert channels have nothing to do with the 503: fixing one does not deliver a request. If the alert
itself did not come, why a channel did not take it is in the `alert.failed`
events of that trace, one per channel (`channel`): for `alertloop`, `status` `401` is a key AlertLoop
refuses, `403` a key it accepts but that may not send this alert (check the key's scope and sources
in AlertLoop), `0` with `transport_error: true` is AlertLoop not reached; for `telegram`, `error` is
Telegram's description (`Unauthorized`: a wrong bot token; `Bad Request: chat not found`: a wrong
chat id, or the bot was never started in that chat). `eventtrace show` lists the event without these
fields, so read the raw line:

```bash
grep -h '<trace_id>' storage/logs/requests/*.jsonl | grep alert.failed
```

To fix a 503, make the email work: `MAIL_TO` and, where `mail()` does not work, `MAIL_DRIVER`
`smtp` with `SMTP_*` (see [Configuration](/docs/configuration)). The visitor's message stays in the `request_start` event of
the same trace, among the form fields, for `app.trace.retention_days`, and only while the request
body is at most 8 KB. A message not in Latin script goes over that within the form's
4,000-character limit: from about 3,600 Cyrillic characters, or about 1,300 when the form is sent
without JavaScript (URL-encoded, six bytes a character). Then, as with `app.trace.log_body` set to
`false`, it is kept nowhere.

## Checklist

- [ ] `DEBUG=false`, `DEV_MODE=false`
- [ ] `app.url.base` is the real `https://` domain
- [ ] document root points at `public/`
- [ ] `config/config.php` is readable only by you and PHP's group (`640`), and a copy is kept off
  the server
- [ ] `http://` and `www.` answer with one 301 to the address in `app.url.base` (see "Redirects:
  HTTPS and www")
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
  rejects every submission (it passes them only while `DEBUG` is on), an alert channel without
  its settings is skipped — `weft-health` lists them under `captcha_missing_keys`, `alert_errors`
  and `mail_errors`
- [ ] `app.captcha.allowed_hosts` lists your real domain, if the captcha is on
- [ ] the contact form can email: `MAIL_TO` (your inbox, not example.com) with working mail
  (`mail()`, or `MAIL_DRIVER` `smtp` with `SMTP_*`); otherwise every submission answers 503 and leaves `Contact request not delivered` in
  `storage/logs/errors/` (see "The contact form answers 503" above)
- [ ] a test request sent through the contact form reached you, after this deploy and after every
  change of the mail settings: `weft-health` does not check delivery (see "Monitoring")
- [ ] `sudo -u www-data sh -c 'umask 002; vendor/bin/weft-health'` (your PHP user) → `ok: true`,
  exit code 0
- [ ] an uptime service watches the home page, and `weft-health` runs from cron (see "Monitoring")
- [ ] a cron line deletes old files in `errors/` (see "Logs")
- [ ] with IndexNow: `/<key>.txt` shows the key and `weft-indexnow` runs from cron (see "IndexNow")
- [ ] `vendor/bin/weft-seo-audit https://<your domain>` on your machine exits with 0 (see "SEO audit")

That is the whole engine. If something is missing from these docs, please
[open an issue](https://github.com/golovanov-dev/weft/issues).
