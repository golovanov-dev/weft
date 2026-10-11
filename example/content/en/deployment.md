---
title: Deployment
description: Ship to production — document root, build, permissions, HTTPS, monitoring, updates and the checklist.
order: 6
---

# Deployment

## What ships

Everything except local artefacts: `app/`, `config/` (with a real `config.php`), `pages/`, `api/`,
`components/`, `lang/`, `content/`, `public/` (**including the built `public/dist/`**), `scripts/`,
`bootstrap.php`, `composer.json` + `composer.lock`, `vendor/`.

Not shipped: `node_modules/`, the local `config/config.php` and `config/config.dev.php` (the
server has its own), `storage/` (step 5 sets it up on the server). `frontend/` is only needed to
build. Without SSH or Node on the server, see
[Shared hosting without SSH or Node](#shared-hosting-without-ssh-or-node).

## Steps

On the server, as the user who deploys the code, in the site root from step 2 on. Substitute your
PHP user and group for `www-data`, your PHP-FPM service for `php8.4-fpm`, and your site root (the
directory holding `bootstrap.php`) for `/var/www/site`.

```bash
# 1. Code. The first time: create the site root, then clone your site's repository
sudo mkdir -p /var/www/site && sudo chown "$USER" /var/www/site
git clone <your repository URL> /var/www/site && cd /var/www/site
#    Later: git pull in the site root, or rsync from your machine (below)
# 2. PHP dependencies
composer install --no-dev --optimize-autoloader
# 3. Environment config
cp config/config.example.php config/config.php
sudo chgrp www-data config/config.php && chmod 640 config/config.php
# 4. Frontend (deploying with rsync: on your machine, not here)
npm ci && npm run build
# 5. storage/
sudo usermod -aG www-data "$USER"
sudo mkdir -p storage/logs storage/cache
sudo chgrp -R www-data storage && sudo chmod -R g+rwX,o-rwx storage
sudo find storage -type d -exec chmod g+s {} +
echo 'umask 002' >> ~/.profile
```

Now log out and back in: the group and the umask of step 5 apply from your next login, and step 7
already writes to `storage/`. A login shell reads `~/.profile` only when there is no `~/.bash_profile`
(RHEL and its kin have one) and never under zsh: then put `umask 002` in `~/.bash_profile` or
`~/.zprofile` instead. After logging in, `umask` prints `0002`. Then, in the site root:

```bash
# 6. PHP-FPM umask
sudo mkdir -p /etc/systemd/system/php8.4-fpm.service.d
printf '[Service]\nUMask=0002\n' | sudo tee /etc/systemd/system/php8.4-fpm.service.d/umask.conf
sudo systemctl daemon-reload && sudo systemctl restart php8.4-fpm
# 7. Optional: a static sitemap for a CDN
php scripts/sitemap.php
# 8. Check, as PHP's user
sudo -u www-data sh -c 'umask 002; vendor/bin/weft-health'
```

- **`www-data`** is `user` and `group` of the PHP-FPM pool (for example
  `/etc/php/8.4/fpm/pool.d/www.conf`) or the user Apache runs as. **`php8.4-fpm`**:
  `systemctl list-units '*fpm*'` shows yours; with Apache's mod_php, use `apache2` (or `httpd`) in
  step 6.
- **Step 1**: a private repository needs read access from the server: generate an SSH key there
  (`ssh-keygen -t ed25519`) and add `~/.ssh/id_ed25519.pub` to the repository's deploy keys. With a deploy key, clone by the
  repository's SSH address (`git@github.com:<owner>/<repo>.git`), not HTTPS: that is what goes
  into `<your repository URL>` of this step.
- **Step 3**: set `DEBUG=false` and `DEV_MODE=false` (otherwise errors are shown to visitors and
  assets are fetched from a Vite dev server that is not running), `MAIL_TO` (your inbox), `MAIL_FROM`
  (an address of your domain), `MAIL_DRIVER='smtp'` and `SMTP_*` where PHP `mail()` does not work (no
  sendmail on the server), and the constants of the features you use: `ALERTLOOP_*`, `TELEGRAM_*` or
  `ALERT_MAIL_TO` for alerts, `INDEXNOW_KEY`, `CAPTCHA_*`, `DB_*`, `REDIS_*`, `PROXY_IPS` — and any other key
  your site uses: `config/config.example.php` lists them all ([Configuration](/docs/configuration#environment--configconfigphp)).
  Your `https://` domain is `$origin` at the top of `app/Configs/app.php`: change it on your machine
  and commit it. Keep a copy of the finished `config/config.php` off the server (a password
  manager): a rebuilt server needs it.
- **Step 6**: `weft-health` cannot see PHP-FPM's umask; `systemctl show -p UMask php8.4-fpm` must
  print `UMask=0002`.
- **Step 8**: exit code 0. Then send one request through the contact form (`/contact` in the
  example) and see it arrive: `weft-health` checks the form's settings, not delivery. When it fails,
  see [Troubleshooting](/docs/troubleshooting#weft-health-fails).

With `opcache.validate_timestamps=0` in PHP-FPM's `php.ini`, PHP keeps running the old code until you
reload it after every deploy: `sudo systemctl reload php8.4-fpm`.

### Deploying with rsync

Instead of `git clone` or `git pull` in step 1 (the first time, after its `mkdir` line), on your
machine, in the site's directory, after step 4 there (rsync ships `public/dist/`, not `frontend/`).
Substitute your deploy user, host and site root:

```bash
rsync -a --delete --exclude=/storage/ --exclude=/config/config.php --exclude=/config/config.dev.php   --exclude=/public/sitemap.xml --exclude=/.git/ --exclude=/node_modules/ --exclude=/frontend/   ./ deploy@example.com:/var/www/site/
```

`--delete` removes what you deleted locally, so a deleted page stops answering; the excludes keep
what the server owns: `storage/`, its config files and the static sitemap of step 7. Any other file
put straight into `public/` on the server (a search engine's verification file) is deleted: keep such
files in the site's repository.

### Who writes `storage/`

PHP writes the error log, traces, the file cache and rate-limit counters under `storage/`; scripts
(`php scripts/sitemap.php`, your cron jobs) write there too, and you read the logs. Rate-limit
counters live in `storage/cache/ratelimit/` even with caching off, unless `app.rate_limit.path`
points elsewhere (`rate_limit_path` in the `weft-health` report shows where); `cache-clear.php`
leaves them unless that path is the cache directory itself (`app.cache.path`).

Each trace directory has one writer: `storage/logs/requests/` only PHP serving the site (PHP's
built-in server `php -S` counts as the site), `storage/logs/cli/` only scripts. Whoever writes a
trace first creates its directory, and traceloom gives it `0750`, which drops setgid, and its files
`0640`. You read `requests/` through PHP's group and `cli/` as its owner, the deploy user.

The error log in `storage/logs/errors/`, the cache and the counters are shared: PHP and scripts write
the same files. Weft sets no permissions on them: they get the mode the umask of the process gives.
Steps 5 and 6 give everyone who writes there one group; who owns a file does not matter:

- `storage/` and everything in it but the trace directories has PHP's group, with group write;
  `o-rwx` keeps other users on the server out of the logs.
- Its directories, the trace ones aside, are setgid (`g+s`): what is created in them gets that
  group, whoever creates it.
- Every writer runs with `umask 002`, so what it creates stays group-writable: PHP-FPM through
  `UMask=` (step 6), your interactive shell through `~/.profile` (step 5), and each cron job and
  each command a deploy script or CI runs over ssh in its own line:

  ```text
  0 3 * * * umask 002; cd /var/www/site && php scripts/sitemap.php
  ```

  ```bash
  ssh deploy@example.com 'umask 002; cd /var/www/site && php scripts/sitemap.php'
  ```

- The deploy user is in PHP's group (step 5), so it can write and read there.

`LOGS_PATH` outside `storage/` (here `/var/log/site`) is set up the same way, before PHP writes there:

```bash
sudo mkdir -p /var/log/site && sudo chgrp -R www-data /var/log/site
sudo chmod -R g+rwX,o-rwx /var/log/site && sudo find /var/log/site -type d -exec chmod g+s {} +
```

`~/.profile` is read only by a login shell, and bash skips it when `~/.bash_profile` exists (then put
the line there); a command run over ssh reads neither. `UMask=` in step 6 applies to the whole PHP-FPM
service, every pool and site on the server. Without systemd (the Docker `php:fpm` image,
supervisord), set the umask in the command that starts FPM, for example the container entrypoint:
`umask 0002 && exec php-fpm`.

Run every script and cron job as the deploy user (its own `crontab -e`), not as `www-data` or root;
`sudo` also runs a command with umask `022`. Run only `weft-health` as PHP's user (step 8): it writes
no trace, and it checks the permissions of whoever runs it, so from your own shell it can report
`ok: true` for a `storage/` PHP cannot write. When something does break:
[PHP cannot write storage/](/docs/troubleshooting#php-cannot-write-storage).

## Shared hosting without SSH or Node

1. On your machine, in the site's directory: `composer install --no-dev --optimize-autoloader`,
   `npm ci && npm run build`, and `config/config.php` (the `cp` line and settings of [step 3](#steps)).
2. With the panel's file manager or SFTP, upload what [What ships](#what-ships) lists, `vendor/`,
   `public/dist/` and `config.php` (once; permissions `600`) included, to `/home/<user>/site` (your
   account's directory, the file manager shows it). Create `storage/logs/` and `storage/cache/`
   there: PHP runs as your account, so they need no further permissions.
3. Point the domain's document root at `/home/<user>/site/public`. If it is fixed (`public_html/`),
   copy the contents of `public/` there (`public/dist/` again after every build), in its `index.php`
   change `dirname(__DIR__) . '/bootstrap.php'` to `dirname(__DIR__) . '/site/bootstrap.php'`, and
   add `define('WEB_ROOT', '/home/<user>/public_html');` to `config/config.php`.
4. Add a panel cron task, every 15 minutes (your panel's PHP 8.4 binary for `/usr/bin/php8.4`):
   `cd /home/<user>/site && out=$(/usr/bin/php8.4 vendor/bin/weft-health 2>&1) || echo "$out"`. It
   prints, and the panel mails or logs, only a failed check; for the first check, set the task
   up without `out=…`, read the output in the panel's email or log, then put `out=…` back.

To update, upload what changed after a new build, and delete on the server what you deleted locally
(or a deleted page keeps answering).

## Document root is `public/`

Only `public/` is exposed. `config/` with your secrets, `app/`, `vendor/` and `storage/` with logs stay
**above** the document root and are unreachable from the web.

### Apache

The bundled `public/.htaccess` sends everything to `index.php` and serves existing files as-is. It
also carries commented-out rules for forcing HTTPS and dropping `www.`
([Redirects: HTTPS and www](#redirects-https-and-www)). Make sure `AllowOverride All` is enabled.

### Nginx

```nginx
server {
    listen 443 ssl;
    server_name example.com;
    root /var/www/site/public;      # public/ only
    index index.php;

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

Substitute your domain, site root and certificate (here certbot's).

### The first certificate

nginx refuses the block above until the certificate files exist. On the server, as a user with
`sudo`, with certbot installed (your distribution's `certbot` package): put this block alone in the
site's nginx config, with your domain and site root, then get the certificate.

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

`-w` is the site's `public/`, the same `root`. Then replace the block with the one in
[Nginx](#nginx) and those of [Redirects: HTTPS and www](#redirects-https-and-www), and run
`sudo nginx -t && sudo systemctl reload nginx` again. certbot's timer renews the certificate the same
way, and the deploy hook, which certbot saves for renewals, reloads nginx after each: without it
nginx keeps serving the old certificate until it expires. Check both:

```bash
sudo certbot renew --dry-run      # renewal works through the webroot (a dry run skips the hook)
sudo grep renew_hook /etc/letsencrypt/renewal/example.com.conf   # renew_hook = systemctl reload nginx
```

## Redirects: HTTPS and www

Weft does not redirect between `http://` and `https://` or `www.` and the bare domain: the web
server does, before PHP. Pick one address, the one in `app.url.base`, and send the other three there
with a single 301. Apache, in `public/.htaccess` instead of its two commented-out rules, for
`https://example.com`:

```apache
RewriteCond %{HTTPS} off [OR]
RewriteCond %{HTTP_HOST} ^www\. [NC]
RewriteRule ^ https://example.com%{REQUEST_URI} [L,R=301]
```

nginx, next to the `server` block of [Nginx](#nginx), with the same certificate, which must cover
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
sees every request as plain HTTP, so `%{HTTPS} off` is always true and the rule redirects forever
("too many redirects"). Redirect at the CDN instead (Cloudflare: "Always Use HTTPS"), or give the
server a certificate and let the CDN connect over HTTPS (Cloudflare: "Full (strict)"), or test what
the proxy says: replace `RewriteCond %{HTTPS} off` with `RewriteCond %{HTTP:X-Forwarded-Proto} !https`.

**Old addresses.** When a site moves to Weft and its paths change, send each old path to its new one
with a 301, so links and search results keep working. Apache, before the rules above; nginx, in the
`server` block of the site:

```apache
RewriteRule ^about-us\.html$ https://example.com/about [L,R=301]
RewriteRule ^blog/\d+-([a-z0-9-]+)\.html$ https://example.com/blog/$1 [L,R=301]
```

```nginx
location = /about-us.html { return 301 /about; }
rewrite ^/blog/\d+-([a-z0-9-]+)\.html$ /blog/$1 permanent;
```

**Check it** from any machine, with your domain. Each must take one step; a `Location` that leads
back to the same address is the loop above.

```bash
curl -sI http://example.com/about | grep -i '^location'           # https://example.com/about
curl -sI https://www.example.com/about | grep -i '^location'      # https://example.com/about
curl -sI https://example.com/about | head -n 1                    # 200, no Location
curl -sI https://example.com/about-us.html | grep -i '^location'  # an old address: its new one
```

## HTTPS and HSTS

HSTS (`app.security.hsts`) is off by default. Once on, every response tells the browser to open the
domain only over HTTPS for `hsts_max_age` seconds (default `31536000`, a year). A browser that has
seen the header refuses plain HTTP and certificate warnings for the domain until that time runs out;
turning HSTS off does not shorten it.

- Turn it on only when every page of the domain answers over HTTPS with a certificate that renews
  itself. Start with `'hsts_max_age' => 300`, check the site in a browser, then raise it to
  `31536000`.
- `'hsts_subdomains' => true` adds `includeSubDomains`: then every subdomain (`crm.`, `mail.`, a
  hosting panel) has to serve HTTPS too, or browsers that visited the site stop opening it. Leave it
  `false` unless all of them do.
- To take HSTS back, keep `'hsts' => true` and set `'hsts_max_age' => 0` in `app/Configs/app.php`:
  Weft then sends `max-age=0`, and a browser that opens the site over HTTPS forgets the rule. Leave
  it so until the old `hsts_max_age` has passed, then turn `hsts` off. A browser that does not come
  back in that time keeps the rule until its own time runs out.

## Behind a proxy or CDN

Rate limits count per visitor IP, so PHP has to see the visitor's address:

- **Shared hosting, Apache, or nginx + php-fpm on one machine (the config above)** — `REMOTE_ADDR`
  is usually the visitor already. Leave `PROXY_IPS` empty and check it as below.
- **Another server forwards requests to the one running PHP** — a CDN such as Cloudflare, a load
  balancer, nginx proxying to Apache or to a container. `REMOTE_ADDR` is that server, and every
  visitor shares one limit until you list it in `PROXY_IPS` in `config/config.php`:

```php
define('PROXY_IPS', ['203.0.113.10', '173.245.48.0/20', '2400:cb00::/32']);
```

Each entry is an IP address or a CIDR range. A host name is ignored: write the address (for
`localhost`, `'127.0.0.1'` and/or `'::1'`; for a Docker service name such as `nginx`, its address).
`weft-health` fails on such an entry, and on `0.0.0.0/0`, `::/0` and ranges wider than `/8` (IPv4) or
`/16` (IPv6): every visitor from them would share the proxy's limit or, with a forged
`X-Forwarded-For`, pick their own IP. Cloudflare's ranges and a Docker network (`/16`) pass.

- **Cloudflare** — every range from [cloudflare.com/ips](https://www.cloudflare.com/ips/), IPv4 and
  IPv6. The list changes now and then; compare it when you update the site.
- **A chain** (Cloudflare → your nginx → PHP) — every link: the Cloudflare ranges and the address
  your nginx connects to PHP from.
- **Docker** — the proxy container's address, or the narrowest range it gets addresses from, not the
  whole Docker network without need. With a port of the PHP container published directly, visitors
  arrive from the network's gateway, and if that address is trusted, a visitor's own
  `X-Forwarded-For` picks the IP their limit counts.

Every listed proxy must append the address it got the request from to `X-Forwarded-For`. Cloudflare
does; in your own nginx, set `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` in the
`location` that proxies to PHP. Weft reads no other header: a proxy that passes the address only in
`X-Real-IP` or `X-Client-Ip` leaves every visitor counted as the proxy.

**Check it.** Open any page of the site from your browser, then on the server, in the site root:

```bash
grep -h '"request_start"' storage/logs/requests/*.jsonl | tail -n 1 | grep -o '"client_ip":"[^"]*"'
```

`client_ip` must be your own public address (any "what is my IP" page shows it), not the proxy's,
`127.0.0.1` or a Docker address.

### HTTPS behind a proxy

The session cookie gets `Secure` when the request came over HTTPS (`Request::isHttps()`): for a
request from a proxy in `PROXY_IPS`, the last value of its `X-Forwarded-Proto` says so, the one the
nearest proxy wrote (the header from any other address is ignored); otherwise PHP does (`$_SERVER['HTTPS']` set and not `off`). When
neither says HTTPS, the site works and the cookie goes without `Secure`, with no error anywhere.

- **A proxy or CDN listed in `PROXY_IPS`** — nothing more to do while it sends `X-Forwarded-Proto`.
  Cloudflare does; in your own nginx that ends HTTPS, set `proxy_set_header X-Forwarded-Proto
  $scheme;` in the `location` that proxies to PHP.
- **A CDN in front of your own nginx** (Cloudflare → nginx → Apache or PHP) — in that nginx,
  `$scheme` is how the CDN reached it (`http` with Cloudflare "Flexible") and replaces the CDN's
  `https`: pass the CDN's value on instead, `proxy_set_header X-Forwarded-Proto
  $http_x_forwarded_proto;`, and list both the CDN ranges and the nginx in `PROXY_IPS`.
- **nginx + PHP-FPM on one machine, the certificate in this nginx** — nothing to do:
  `include fastcgi_params;` passes `fastcgi_param HTTPS $https if_not_empty;`. If your config does
  not include that file, add that line to the `location ~ \.php$` block.
- **HTTPS ends earlier, and `PROXY_IPS` is empty** — set `HTTPS=on` for PHP yourself. nginx +
  PHP-FPM: `fastcgi_param HTTPS on;` in the `location ~ \.php$` block, after
  `include fastcgi_params;`, only when the server in front redirects plain HTTP to HTTPS. Apache, in
  the virtual host or `public/.htaccess`, with `mod_setenvif`:
  `SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on` — Apache believes this header from anyone, so only
  when Apache cannot be reached except through that proxy.
- **Cloudflare or another CDN** — best, give the server a certificate and let the CDN connect over
  HTTPS (Cloudflare: "Full (strict)"); then the server sees HTTPS itself.

**Check it** on a page with a form (in the example, `/contact`), from any machine; the `Set-Cookie`
line must contain `secure`:

```bash
curl -s -D - -o /dev/null https://example.com/contact | grep -i '^set-cookie'
```

## Monitoring

Step 8 checks the site once. Two things tell you when it breaks later:

- **Availability** — any uptime monitoring service requesting your home page every few minutes and
  alerting you when the answer is not `200`. It sees what visitors see: DNS, the certificate, the
  web server and PHP.
- **`weft-health` by cron** — in the crontab of PHP's user (`sudo crontab -u www-data -e`; the one
  script run as that user), with your address and site root. cron mails what a job prints, so the
  report reaches you only when `weft-health` exits with 1; the server needs working mail of its own
  for that (cron does not use `MAIL_DRIVER`). Without it, use a heartbeat check of your uptime
  service, which alerts when the pings stop (the second line instead of the first):

```text
MAILTO=you@example.com
*/15 * * * * umask 002; cd /var/www/site && out=$(vendor/bin/weft-health) || echo "$out"
*/15 * * * * umask 002; cd /var/www/site && vendor/bin/weft-health > /dev/null && curl -fsS -m 10 https://<your heartbeat URL>
```

When it fails, fix what the report names ([Troubleshooting](/docs/troubleshooting#weft-health-fails)),
then run step 8 again until it exits with 0.

Neither sees whether the contact form's email arrives: the mail server can refuse it or it can bounce
later. After each deploy and after any change to `MAIL_TO`, `MAIL_FROM`, `MAIL_DRIVER`, `SMTP_*` or
the server's mail setup, send one request through the form and check that it reached your inbox. The
alert the form sends proves nothing: it is sent either way, and with `mail` as its only channel it
fails with the email. If the form answered 503 or the email did not come:
[The contact form answers 503](/docs/troubleshooting#the-contact-form-answers-503).

## IndexNow

With `INDEXNOW_KEY` set and your domain in `app.url.base` deployed (on example.com the command
refuses to run), open `https://<your domain>/<key>.txt`: it must show the key (a 404 means the
`'indexnow'` block of `app/Configs/app.php` is missing). Then, on the server, in the site root, as
the deploy user:

```bash
vendor/bin/weft-indexnow --dry-run   # lists what the first run submits
vendor/bin/weft-indexnow             # submits; exit 1 if refused
```

Then run it hourly from the deploy user's crontab (`crontab -e`); on shared hosting, as an hourly
task in the panel (the second line, with the panel's PHP binary and your site root):

```text
MAILTO=you@example.com
0 * * * * umask 002; cd /var/www/site && out=$(vendor/bin/weft-indexnow 2>&1) || echo "$out"
cd /home/<user>/site && out=$(/usr/bin/php8.4 vendor/bin/weft-indexnow 2>&1) || echo "$out"
```

Either line prints only when the command fails, so a message from the task means IndexNow refused or
could not run. For the first check in a panel, add a one-off task with `--dry-run` and without the
`out=…` wrapper, and read its output where the panel shows it. What was submitted is kept in
`storage/indexnow.json` (`app.indexnow.state`); delete it and the next run submits every address.
An address is submitted again only when its `lastmod` in the sitemap changes; without `lastmod` it is
submitted once, when it is new, and the command prints how many such addresses there are. To have
edits submitted, give the routes a `lastmod` ([Configuration](/docs/configuration#sitemap)).

## SEO audit

On your machine (or in CI), in your site's directory after `composer install`, with PHP's `dom`
extension:

```bash
vendor/bin/weft-seo-audit https://example.com    # your domain: the pages in its sitemap.xml
```

It exits with 0 when every page passes; each other line names a page and its problems
([Features](/docs/features#seo-audit)). For a site behind Basic auth, set
`WEFT_AUDIT_AUTH='user:pass'` in the command's environment.

## Updating

### The site

Deploy as in step 1 (with rsync, build first as in step 4), then on the server, in the site root, as
the deploy user:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build      # with git pull, if the frontend changed
```

With `opcache.validate_timestamps=0`, reload PHP-FPM afterwards (see [Steps](#steps)).

### The engine

On your machine, in the site's directory. For a new minor version, its page in
[Upgrading](/docs/upgrading) adds what that version needs at each step.

1. Prepare the rollback. If `/composer.lock` is in your `.gitignore`, delete that line and commit
   `.gitignore` and `composer.lock`: rolling back reinstalls the versions in the committed lock. Save
   a copy of each environment config as it is now, the server's `config/config.php` and yours
   (`config/config.php` or `config/config.dev.php`), outside the site directory (`--delete` in step 1
   removes a copy kept in `config/`).
2. Read the new version's section of the
   [CHANGELOG](https://github.com/golovanov-dev/weft/blob/main/CHANGELOG.md) and do what each entry
   marked **Action.** says, in every section. A setting under an old name is not an error: its
   feature just turns off. Commit the changes.
3. Compare your config's constants with the example's template of the new version (substitute it
   for `vX.Y.Z`), using the config the site runs with on your machine:

   ```bash
   diff <(grep -o "^define('[A-Z_]*'" config/config.php | sort)      <(curl -s https://raw.githubusercontent.com/golovanov-dev/weft/vX.Y.Z/example/config/config.example.php | grep -o "^define('[A-Z_]*'" | sort)
   ```

   Config files are in no repository, so nothing updates them. A `>` line is a constant the template
   sets and your config lacks: add it, with the block of `app/Configs/app.php` that reads it (the
   CHANGELOG entry naming the constant says which). Constants the template only shows commented out
   (`// define(...)`) are optional and not listed; add one only when its comment says you need it. A
   `<` line is a constant of your own or one the CHANGELOG renames: rename that one in place, keeping
   its value (the template's value is empty), and the `>` line of its new name goes away.
4. Update and check:

   ```bash
   composer require golovanov/weft:^X.Y -W   # a new minor version; -W updates its dependencies too
   composer update golovanov/weft             # a patch release of the minor you have
   vendor/bin/weft-health --dev
   ```

   `composer update` stays within the minor version you have. Commit `composer.json` and
   `composer.lock`.
5. Make the config changes of steps 2 and 3 in the server's `config/config.php`, in the site root
   there. Deploy, then run step 8 of [Steps](#steps) on the server: exit code 0, and one request
   through the contact form that reaches you.

To roll back, revert the commits of steps 4 and 2, newest first, in one command:
`git revert --no-edit <commit of step 4> <commit of step 2>` (both may change
`app/Configs/app.php`; the other order stops on a conflict there). Run `composer install` on your
machine, deploy again with steps 1 and 2 of [Steps](#steps) (`composer install` puts back the
versions in the reverted `composer.lock`), and put back the configs saved in step 1, on the server
and on your machine. Then run the health check of the version you returned to on the server.

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
grep -rl --include='*.jsonl' '<trace_id>' storage/logs              # which one
```

Request bodies are in the trace: by default whatever a form collects, such as a visitor's email,
phone and message, is kept for `app.trace.retention_days` (30 days by default; `0` keeps files
forever). Values under sensitive key names (password, token, api_key, … plus
`app.trace.sensitive_keys`) are masked; anything else is logged unless you list its key or set
`app.trace.log_body` to `false`. `log_body` covers bodies only: fields your code records with
`Trace::event()` stay (the example's `contact_submit` records the email), and only
`sensitive_keys` masks them ([Configuration](/docs/configuration#trace)). Trace files rotate by
date and size and are deleted after `retention_days` in both directories: the site cleans
`requests/`, a script cleans `cli/` when it writes a trace.

`errors/` is not cleaned up by Weft: delete old files from cron, in the deploy user's crontab (30
days here; substitute your `LOGS_PATH` and site root):

```text
0 4 * * * find /var/www/site/storage/logs/errors -name '*.log' -mtime +30 -delete
```

## Checklist

- [ ] `DEBUG=false`, `DEV_MODE=false`
- [ ] `app.url.base` is the real `https://` domain
- [ ] document root points at `public/`
- [ ] `config/config.php` is readable only by you and PHP's group (`640`), and a copy is kept off
  the server
- [ ] `http://` and `www.` answer with one 301 to the address in `app.url.base`
  ([Redirects: HTTPS and www](#redirects-https-and-www))
- [ ] `composer install --no-dev --optimize-autoloader` done
- [ ] `npm run build` done, `public/dist/` present
- [ ] `storage/` has PHP's group and setgid directories; PHP-FPM (`UMask=0002`), cron jobs, deploy
  commands and your shell run with `umask 002` (steps 5 and 6)
- [ ] `client_ip` in the newest `request_start` trace is your own address, not a proxy's
  ([Behind a proxy or CDN](#behind-a-proxy-or-cdn)); otherwise all visitors share one rate limit
- [ ] over HTTPS, `Set-Cookie` of a page with a form contains `secure`
  ([HTTPS behind a proxy](#https-behind-a-proxy))
- [ ] HTTPS on for every page; HSTS (`app.security.hsts`) only after reading
  [HTTPS and HSTS](#https-and-hsts), and `hsts_subdomains` only if every subdomain serves HTTPS
- [ ] keys set for whichever of captcha, alerts and mail you use: a captcha enabled with a key
  missing rejects every submission (it passes them only while `DEBUG` is on), an alert channel
  without its settings is skipped; `weft-health` lists them under `captcha_missing_keys`,
  `alert_errors` and `mail_errors`
- [ ] `app.captcha.allowed_hosts` lists your real domain, if the captcha is on (`weft-health` does
  not check it)
- [ ] `MAIL_FROM` is an address of your domain (`weft-health` does not check it)
- [ ] the contact form can email: `MAIL_TO` (your inbox, not example.com) with working mail
  (`mail()`, or `MAIL_DRIVER` `smtp` with `SMTP_*`); otherwise every submission answers 503
  ([Troubleshooting](/docs/troubleshooting#the-contact-form-answers-503))
- [ ] a test request sent through the contact form reached you, after this deploy and after every
  change of the mail settings ([Monitoring](#monitoring))
- [ ] you know what the trace keeps: visitors' emails, phones and messages for `retention_days`
  days, unless listed in `sensitive_keys`; `log_body` off drops bodies, not the fields of
  `Trace::event()`, such as the email of the example's `contact_submit` ([Logs](#logs))
- [ ] `sudo -u www-data sh -c 'umask 002; vendor/bin/weft-health'` (your PHP user) → `ok: true`,
  exit code 0
- [ ] an uptime service watches the home page, and `weft-health` runs from cron
  ([Monitoring](#monitoring))
- [ ] a cron line deletes old files in `errors/` ([Logs](#logs))
- [ ] with IndexNow: `/<key>.txt` shows the key and `weft-indexnow` runs from cron
  ([IndexNow](#indexnow))
- [ ] `vendor/bin/weft-seo-audit https://<your domain>` on your machine exits with 0
  ([SEO audit](#seo-audit))

Next: [Troubleshooting](/docs/troubleshooting).
