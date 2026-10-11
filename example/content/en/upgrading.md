---
title: Upgrading
description: Moving a site from Weft 0.1.x to 0.2 — config, copied files, the server, Redis keys and the way back.
order: 8
---

# Upgrading

## From 0.1.x to 0.2

Follow [Updating the engine](/docs/deployment#the-engine) in Deployment. This page adds what 0.2
needs at each of its steps. The [CHANGELOG](https://github.com/golovanov-dev/weft/blob/main/CHANGELOG.md)
lists every change; the entries marked **Action.** point here when the work takes more than a line.

### Step 2: your site's code and config

On your machine, in the site's directory. Do what each **Action.** entry of the 0.2.0 section says
(most say which sites they apply to), and these where they apply to your site. Commit the result.

**`app/Configs/app.php`.** A setting under an old name is not an error: its feature just turns off.

- Alerts are switched on by `app.alerts.channels` alone. To keep AlertLoop alerts, add
  `'alerts' => ['channels' => ['alertloop']]` and delete `'enabled'` from the `'alertloop'` block.
  If you have `app/Configs/alertloop.php`, move its array into `app.php` under `'alertloop'` and
  delete the file: 0.1 read it there, so alerts configured as documented were off.
- If your `app.php` came from the example, take its `'captcha'`, `'mail'`, `'alerts'` and
  `'contact'` blocks, and `'indexnow'` if you set `INDEXNOW_KEY`: without that block the key does
  nothing and `/<key>.txt` is a 404. The sender moved from `app.contact.mail_from` to
  `app.mail.from` (still `MAIL_FROM`). Delete the `'prefix' => 'weft:docs:'` line of `'cache'`.
- Trace files older than `app.trace.retention_days` (30 by default) are deleted from the first
  request after the update. To keep that history, set the key first (`0` keeps files forever).
- A relative `app.cache.path` is now taken from the site root (`APP_ROOT`), not from the working
  directory (`public/` under PHP-FPM). Its new place must be writable by PHP; the old directory
  can go.
- A site in a sub-directory whose `og_image` includes it (`'/site/img/og.png'`): remove it
  (`'/img/og.png'`), since `BASE_URI` is now added to a relative image.

**Files copied from the example.** Compare yours with the example of `v0.2.0`:

- `public/index.php`: the `session_start()` line is gone (the session starts only when a form
  needs a CSRF token); the session cookie block comes after `require bootstrap.php`, so its
  `Secure` flag can use `Request::isHttps()`; the `cli-server` block at the top serves assets under
  `php -S`. Take all three.
- `app/Bootstrap.php`: remove `Database::setErrorNotifier(Alert::notifier())`, or every request
  stops with a fatal error. For the `contact_form` health check, take the whole file or, if yours
  has code of its own, the `Health::check()` line of `init()`, the method `contactForm()` and those
  of its three `use` lines your file lacks (a `use` that is already there is a fatal error).
- The contact form, if your site copied it: `api/contact.php`, `app/Services/ContactService.php`,
  `pages/contact.php`, `frontend/src/contact.js` and the `status` strings of
  `lang/en/contact.php`. A 422 now lists fields as `errors: [{name, text}]` (was `fields`), and the
  request is delivered only by email: the alert it sends carries no form data, `New contact request`
  at `info` or `Contact request not delivered` at `critical`.
- `.gitignore`: add `/config/config.dev.php` (a development config with secrets, which
  `git add -A` would otherwise commit) and `/storage/indexnow.json` (what `weft-indexnow` submitted).
  If `config/config.dev.php` is already in git, `git rm --cached config/config.dev.php`, commit, and
  change the secrets it holds: they stay in the repository's history.
  On every other clone (your other machines, a server that runs `git pull`) the next `git pull`
  deletes `config/config.dev.php`: copy it aside before the pull and put it back after.
- `scripts/cache-clear.php`: take the new one. The old one prints `Cache flushed.` even when nothing
  was flushed (caching off, Redis unavailable, a failed flush); the new one says so and, when the
  store cannot be flushed, exits with 1.
- `scripts/health.php`: delete it. Call `vendor/bin/weft-health` from deploy scripts, CI and cron
  (`--dev` on a development machine), and change the `health` script of `composer.json` to
  `weft-health`.

**`composer.json`.** If it requires `golovanov/traceloom` itself, raise that to `^0.5`, or Composer
cannot install Weft 0.2.

### Step 3: the constants

Run the `diff` of step 3 with `v0.2.0`. In your `config/config.php` (and `config/config.dev.php`):

- rename `RECAPTCHA_SITEKEY` to `CAPTCHA_SITE_KEY` and `RECAPTCHA_SECRETKEY` to
  `CAPTCHA_SECRET_KEY`, keeping their values. With the old names, or the new ones added empty from
  the template, the captcha is off and `weft-health` shows `captcha_enabled: false`. The server's
  copy is renamed in step 5, when you deploy 0.2: a server still on 0.1.3 with the new names runs
  without its captcha and reports nothing;
- add the new ones: `MAIL_DRIVER` and `SMTP_*` (email), `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`
  and `ALERT_MAIL_TO` (alert channels), `INDEXNOW_KEY`;
- `MAIL_TO` takes bare addresses, several separated by commas. `Name <owner@site>`, which `mail()`
  accepted, now fails every contact email and `weft-health`;
- if your config came from the template, `MAIL_TO` and `MAIL_FROM` were the Weft author's
  `hello@golovanov.dev` and `noreply@golovanov.dev`: set addresses of your own.

### Step 4: the update

```bash
composer require golovanov/weft:^0.2 -W
vendor/bin/weft-health --dev
```

`-W` also updates traceloom to the `^0.5` that 0.2 requires; `composer update` stays on 0.1.x. The
report has new fields: `config`, `production`, `logs_error`, `cache_driver`, `rate_limit_store`,
`rate_limit_path`, `rate_limit_writable`, `rate_limit_error`, `proxy_ips_errors`,
`captcha_missing_keys`, `alert_channels`, `alert_errors`, `mail_driver`, `mail_errors`,
`seo_errors` and `checks`. `traceloom` (always `true`) and `alertloop_enabled` are gone: if you parse
the output, read `alert_channels` instead. `ok` is false on more than unwritable logs: what each field
means is in [Troubleshooting](/docs/troubleshooting#weft-health-fails).

### Step 5: the server

On the server, in the site root:

1. Set up `storage/` as in steps 5 and 6 of [Deployment](/docs/deployment#steps): one group for PHP
   and every other writer, setgid directories, `umask 002` for PHP-FPM and your shell. Weft no longer
   sets permissions on logs, cache and counters, and step 5 also hands to PHP's group the files your
   scripts left in `storage/logs/requests/`, which is now the site's alone (scripts write to
   `storage/logs/cli/`).
2. Move cron jobs that run Weft scripts from the crontab of `www-data` or `root` to the deploy
   user's, and start each cron line, and each command you run over ssh or from CI, with
   `umask 002;`. Run `weft-health` as PHP's user (step 8).
3. Deploying with rsync: use the excludes of step 1 (`storage/`, `config/config.php`).
4. Rate-limit counter files 0.1 left in the cache directory go with `php scripts/cache-clear.php`.
5. In `config/config.php`: `CAPTCHA_SECRET_KEY` takes the value of `RECAPTCHA_SECRETKEY`,
   `CAPTCHA_SITE_KEY` that of `RECAPTCHA_SITEKEY`; then the `diff` and the new constants, as on
   your machine. Behind a proxy or CDN, list it in `PROXY_IPS`
   ([Deployment](/docs/deployment#behind-a-proxy-or-cdn)), or all visitors share one rate limit.

Then deploy and run step 8: exit code 0, `captcha_enabled: true` if your site uses the captcha
(`false` means a key was added empty instead of renamed), and one request through the contact form
that reaches you.

### Old Redis keys

With `app.cache.driver` `redis`, the key prefix is now the host of `app.url.base` and a colon
(`example.com:`), not `weft:`, and cache entries are kept under `<prefix>cache:`, so the cache
starts empty, with your own `app.cache.prefix` too. Old keys with a TTL expire on their own.
To delete the old `weft:` keys at once — only if no other Weft site uses this Redis database, since
`weft:` was every site's default, and your `app.cache.prefix` does not start with `weft:` — run once
after the update, on a Linux shell. Substitute your `REDIS_HOST`, `REDIS_PORT` and `REDIS_DB`; with
a password, first run `read -rs REDISCLI_AUTH && export REDISCLI_AUTH` and type it, so it stays out
of the shell history:

```bash
redis-cli -h <REDIS_HOST> -p <REDIS_PORT> -n <REDIS_DB> --scan --pattern 'weft:*' | grep '^weft:' | xargs -r -d '\n' -n 500 redis-cli -h <REDIS_HOST> -p <REDIS_PORT> -n <REDIS_DB> unlink
```

It deletes in batches of 500 without blocking Redis; a key with a line break in its name is left as
it is.

### Back to 0.1.x

Roll back as in [Updating the engine](/docs/deployment#the-engine). `git revert` brings back
`scripts/health.php` with the rest of the code; on the server, check with `php scripts/health.php`:
exit code 0, and `captcha_enabled: true` if your site uses the captcha.

That is the whole engine. If something is missing from these docs, please
[open an issue](https://github.com/golovanov-dev/weft/issues).
