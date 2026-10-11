---
title: Troubleshooting
description: What weft-health reports and how to fix it, files PHP cannot write, Redis failures and a contact form that answers 503.
order: 7
---

# Troubleshooting

Commands here run on the server, in the site root (the directory holding `bootstrap.php`), as the
deploy user, unless a line says otherwise. `www-data` stands for PHP's user and group, and
`storage/logs` for your `LOGS_PATH`, as in [Deployment](/docs/deployment#steps).

## weft-health fails

`vendor/bin/weft-health` prints a JSON report and exits with 1 when `ok` is false, 0 otherwise.
`config` names the environment config the site loaded (`config/config.php` on a server). With
`--dev`, on a development machine, `production` is listed without failing the report; every other
field counts as on a server. `ok` is false when any of these is bad:

| Field | Bad value | What to do |
|---|---|---|
| `production` | not empty | Fix each setting it names: `DEBUG` or `DEV_MODE` on; `config/config.example.php` loaded (create `config/config.php`); `app.url.base` on example.com (set your domain); with `app.vite.entry` set, a `public/dist/.vite/manifest.json` that is missing or lacks that entry (run `npm run build`; with `WEB_ROOT` set, the manifest is looked for there) |
| `logs_writable` | `false` | `logs_error` names what PHP cannot write: `errors/` or `requests/` under `LOGS_PATH`, or says that `LOGS_PATH` is not set. See [PHP cannot write storage/](#php-cannot-write-storage) |
| `rate_limit_writable` | `false` | `rate_limit_error` names `rate_limit_path`, the counter directory. The same fix as for `logs_writable` |
| `rate_limit_store` | `file` while `cache_driver` is `redis` | Redis is not used: see [Redis fails](#redis-fails) |
| `proxy_ips_errors` | not empty | `PROXY_IPS` is not an array (no proxy is trusted: write `define('PROXY_IPS', [...])`), an entry is not an IP address or CIDR range (for `localhost` write `'127.0.0.1'` and/or `'::1'`), or an entry trusts too many addresses (`0.0.0.0/0`, `::/0`, an IPv4 range wider than `/8`, an IPv6 one wider than `/16`): list only your proxies ([Deployment](/docs/deployment#behind-a-proxy-or-cdn)) |
| `captcha_missing_keys` | not empty | Every form submission is rejected: see [Captcha, alert and mail settings](#captcha-alert-and-mail-settings) |
| `alert_errors` | not empty | A channel in `alert_channels` lacks a setting and is skipped: the same section |
| `mail_errors` | not empty | `app.mail` cannot send: the same section |
| `seo_errors` | not empty | A `robots.txt` or `llms.txt` file in `public/` (in `WEB_ROOT`, when `config/config.php` sets it) that the web server serves instead of `app.seo.robots_groups` or `app.llms`: delete the file |
| `checks.<name>.ok` | `false` | A check your site added in `app/Bootstrap.php` failed; its other fields say why. This example's `contact_form` lists in `problems`: `MAIL_TO is empty`; `MAIL_TO is on example.com` (the template's address: set your inbox); `MAIL_TO is not an email address` (an entry is not a plain address, such as `Name <a@b>`: write bare addresses, several separated by commas) |

`rate_limit_path` is `app.rate_limit.path`, else `ratelimit/` in `app.cache.path`
(`storage/cache/ratelimit` by default; `null` while counters are in Redis). A relative path is taken
from the site root (`APP_ROOT`), so `weft-health` and the site use the same directory whatever their
working directory.

Some settings `weft-health` does not check, and a green report says nothing about them:

- **Delivery.** `contact_form` checks `MAIL_TO`, not that an email goes out: `mail()` is only tried
  and an SMTP server only reached when a message is sent. `weft-health` also runs on PHP's
  command-line `php.ini`, while PHP-FPM reads its own (`/etc/php/8.4/fpm/php.ini`). Only a test
  request through the form proves the site can send ([Deployment](/docs/deployment#monitoring)).
- **`MAIL_FROM`.** Set it to an address of your own domain: mail sent from example.com is refused.
- **`app.captcha.allowed_hosts`.** The template lists `example.com`; with the captcha on, list your
  real domain, or every token is rejected as coming from another host.
- **IndexNow.** With `INDEXNOW_KEY` set but no `'indexnow'` block in `app/Configs/app.php`, the key
  does nothing: open `https://<your domain>/<key>.txt`, it must show the key.

### Captcha, alert and mail settings

`captcha_missing_keys` names keys of `app.captcha`: `site_key`, `secret_key`, and `driver` when it
is not `recaptcha` or `smartcaptcha`. In this example the keys come from `CAPTCHA_SITE_KEY` and
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

## PHP cannot write `storage/`

PHP writes the error log, traces, the file cache and the rate-limit counters under `storage/`. If it
cannot, rate limits stop limiting: the limiter fails open so a broken disk never locks visitors out,
and the line it writes about that goes to `errors/`, which is not writable either. Only `weft-health`
then says so, and only when run as PHP's user, as in step 8 of
[Deployment](/docs/deployment#steps): it checks the permissions of whoever runs it.

`weft-health` checks the directories (`errors/` and `requests/` under `LOGS_PATH`, the counter
directory), not the files in them. A file another user created without `umask 002`, such as today's
`errors/<date>.log` or a rate-limit counter, passes the check while PHP cannot write it: the errors
and traces of that day are lost, or the counter stops limiting and `errors/` gets
`Rate limit failed open: counter not writable`, a line that names no file. (With Redis, the same
line also means Redis answered the counter with an error: see [Redis fails](#redis-fails).) List
what PHP cannot write, then fix it. The `find` skips `cli/`: the scripts' traces belong to the
deploy user, and PHP does not write there ([Who writes storage/](/docs/deployment#who-writes-storage)).

```bash
sudo -u www-data find storage -name cli -prune -o ! -writable -print   # also your LOGS_PATH, if outside storage/
sudo chgrp -R www-data storage && sudo chmod -R g+rwX,o-rwx storage
sudo find storage -type d -exec chmod g+s {} +
```

Then find who created it: `ls -l` shows the owner. In `errors/`, the cache or the counters, a writer
without `umask 002`: PHP-FPM (`systemctl show -p UMask php8.4-fpm` must print `UMask=0002`; in a
container, `grep Umask /proc/$(pgrep -o php-fpm)/status`), or another user's cron lines
(`sudo crontab -l -u <user>`), scripts, commands over ssh or `sudo`, which runs with umask `022`.
In `requests/`, anyone but PHP, such as `php -S` started as another user.

A script run as a user other than the deploy user takes `storage/logs/cli/`, and the deploy user's
scripts then lose their traces: only their stderr and `errors/` say
`fopen(.../cli/.traceloom.lock)`, and `weft-health` does not check `cli/`. Fix it with the same
lines and run every script as the deploy user.

## Redis fails

With `app.cache.driver` set to `redis`, the engine sends `PING` after connecting; when Redis fails,
the cache and the rate-limit counters fall back to files for the rest of the request, and
`weft-health` reports `rate_limit_store: file` with the reason in `rate_limit_error`:

| `rate_limit_error` contains | Cause |
|---|---|
| `Class "Redis" not found` | ext-redis is not installed |
| a connection error, such as `Connection refused` | Redis is not reachable at `REDIS_HOST`:`REDIS_PORT` |
| `NOAUTH` | Redis requires a password and `REDIS_PASSWORD` is empty |
| `WRONGPASS` | `REDIS_PASSWORD` is wrong |
| `called without any password configured` | `REDIS_PASSWORD` is set, but Redis has no password: set it to `''` |
| `Redis AUTH failed` (Redis 5 and older) | `REDIS_PASSWORD` is wrong, or Redis has no password: then set it to `''` |
| `socket error on read socket` or `read error on connection` | Redis accepted the connection and did not answer within a second: overloaded, or a firewall drops the traffic |
| `DB index is out of range` | `REDIS_DB` is higher than Redis allows (16 databases by default) |

The cache and the counters share one connection per request. While Redis does not answer, every
request that uses the cache, a rate limit or an alert with a dedupe key waits for it, up to
2 seconds to connect and 1 second for a reply, and so does `weft-health`. A request waits once,
except when it already used both the cache and a counter and Redis stopped answering in its middle:
then it may wait once more.

A Redis that takes the connection but rejects commands (a read-only replica, `maxmemory` under
`noeviction`) still serves pages: a rejected cache read is a miss, a rejected write is skipped, and a
form submission whose counter it rejects is allowed. `weft-health` does not see that
(`rate_limit_store: redis`); only the `Cache store failed` and `Rate limit failed open` lines below
do. The site logs the reason to `errors/<date>.log` under `LOGS_PATH`, time in `APP_TIMEZONE`:

```text
[2026-10-08 12:00:00] RATE_LIMIT ERRNO:500 | Redis unavailable, rate limits fall back to files: <reason> | …
[2026-10-08 12:00:00] CACHE ERRNO:500 | Cache init failed, falling back to file: <reason> | …
[2026-10-08 12:00:00] CACHE ERRNO:500 | Cache store failed, continuing without cache: <reason> | …
[2026-10-08 12:00:00] RATE_LIMIT ERRNO:500 | Rate limit failed open: <reason> | …
```

- The first line comes from form submissions and `weft-health`, the second from page views: Redis
  was not used for that request. When the reason is `a Redis command failed earlier in this
  request`, the real one is on an earlier line with the same `trace:<id>`.
- The last two mean Redis rejects commands. `Cache store failed` comes on page views, once per
  request; on a site without forms it is often the only trace. A cache write phpredis answers with
  `false` instead of an exception is skipped without a line.
- `Rate limit failed open` (`Claim failed open` for an alert's dedupe window) comes when a counter
  was not counted; `counter not writable` as the reason means Redis answered with an error, or, with
  files, a counter file PHP cannot write ([above](#php-cannot-write-storage)).

## The contact form answers 503

`503` means the email to `MAIL_TO` was not accepted. Each such request leaves
`Contact request not delivered` in `storage/logs/errors/`, ending with `trace:<id>`. Find it:

```bash
grep -rh 'not delivered\|not sent' storage/logs/errors/
```

No output: with `LOGS_PATH` outside `storage/`, grep there; otherwise PHP could not write the line,
see [PHP cannot write `storage/`](#php-cannot-write-storage). A line with the same trace id, `Mail not sent (<driver>): <reason>`,
says why the email did not go:

| Reason | Cause |
|---|---|
| `mail() failed` | PHP could not hand the email to the server's mail transport: `sendmail_path` in `php.ini`, or your host's mail setup |
| `mail() is not available` | with `MAIL_DRIVER` `mail`, PHP-FPM's `php.ini` disables `mail()` or names no existing `sendmail_path` program: install a mail transport or switch to `smtp` |
| `SMTP … refused: <the server's reply>` | the server refused a command, for example `AUTH` with a wrong `SMTP_USERNAME` or `SMTP_PASSWORD` |
| `SMTP connection to … failed`, `SMTP timed out`, `wrong version number` | wrong `SMTP_HOST` or `SMTP_PORT`, the port is blocked, or `SMTP_ENCRYPTION` does not match the port: 465 takes `ssl`, 587 `starttls` |
| `certificate verify failed` after `SMTP connection to ssl://…`, or `SMTP STARTTLS handshake failed` | the certificate is for another name than `SMTP_HOST`, or PHP has no CA bundle to check it: set `openssl.cafile` in `php.ini` |
| a setting that is missing | set it ([Configuration](/docs/configuration#mail)) |

With `MAIL_TO` empty there is no `Mail not sent` line. No line and no email means the mail server
took the email and did not deliver it: see its own log (for example `/var/log/mail.log`, or your mail
provider's sent folder).

To fix a 503, make the email work: `MAIL_TO` and, where `mail()` does not work, `MAIL_DRIVER`
`smtp` with `SMTP_*`. Alert channels have nothing to do with it: an alert does not deliver a request,
and fixing a channel does not stop the 503.

The visitor's message stays in the `request_start` event of the same trace, among the form fields,
for `app.trace.retention_days`, and only while the request body is at most 8 KB. A message not in
Latin script goes over that within the form's 4,000-character limit: from about 3,600 Cyrillic
characters, or about 1,300 when the form is sent without JavaScript (URL-encoded, six bytes a
character). Then, as with `app.trace.log_body` set to `false`, it is kept nowhere.

## An alert did not come

Why a channel did not take an alert is in the `alert.failed` events of the request's trace, one per
channel (`channel`). `eventtrace show` lists the event without these fields, so read the raw line:

```bash
grep -h '<trace_id>' storage/logs/requests/*.jsonl | grep alert.failed
```

- `alertloop`: `status` `401` is a key AlertLoop refuses; `403` a key it accepts but that may not
  send this alert (check the key's scope and sources in AlertLoop); `0` with `transport_error: true`
  is AlertLoop not reached.
- `telegram`: `error` is Telegram's description. `Unauthorized`: a wrong bot token;
  `Bad Request: chat not found`: a wrong chat id, or the bot was never started in that chat.
- `mail`: the alert goes out with the same mail settings as the contact form, so when the form's
  email fails, so does this alert. To hear about undelivered requests, add a channel that does not
  go through your mail.

An alert with a dedupe key is sent once per five minutes, and a repeat within that window is not
sent at all ([Features](/docs/features#alerts-for-sensitive-spots)).

Next: [Upgrading](/docs/upgrading).
