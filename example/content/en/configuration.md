---
title: Configuration
description: Two independent layers — environment constants and business config — and every key they hold.
order: 3
---

# Configuration

Weft splits configuration in two, on purpose.

| Layer | File | Format | Holds | In git? |
|---|---|---|---|---|
| Environment | `config/config.php` | `define()` constants | secrets, credentials, `DEBUG`, `DEV_MODE`, timezone | no |
| Business | `app/Configs/app.php` | array with dot keys | SEO, locales, feature flags, limits | yes |

The rule: **a secret or something that differs per server → environment. A structural setting of the
site → business config.** Business config may read secrets from the environment — it is loaded after
the constants are defined.

## Environment — `config/config.php`

```php
define('DEBUG', false);       // verbose errors, curl info, robots blocks indexing
define('DEV_MODE', false);    // assets from the Vite dev server; HSTS off
define('BASE_URI', '');       // '' at a domain root; '/blog' for example.com/blog: with the
                              // leading /, without a trailing one ('blog' breaks every URL)
define('APP_TIMEZONE', 'UTC');// every date, log and DB session uses this
define('LOGS_PATH', dirname(__DIR__) . '/storage/logs'); // errors/, traces: requests/ (site), cli/ (scripts); unset: weft-logs in the system temp dir
```

Database (only if you use `Golovanov\Weft\Database`):

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');
define('DB_TIMEOUT', 5);     // optional: seconds to wait for the connection, default 5
```

The MySQL server is reached on its default port. There is no read timeout setting: queries wait
`mysqlnd.net_read_timeout` ([Features](/docs/features), "Database").

Redis (only for the `redis` cache driver):

```php
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_DB', 0);
define('REDIS_PASSWORD', '');
```

Integrations:

```php
define('CAPTCHA_SITE_KEY', '');     // public key → the frontend
define('CAPTCHA_SECRET_KEY', '');   // secret key → verification; setting it enables the captcha (needs the site key too)

define('MAIL_DRIVER', 'mail');      // 'mail': PHP mail(); 'smtp': the SMTP_* server below
define('MAIL_FROM', 'noreply@example.com'); // From: of every email; an address of your own domain
define('SMTP_HOST', '');            // e.g. smtp.yandex.ru; only for 'smtp'
define('SMTP_PORT', 587);           // 587 with starttls, 465 with ssl: change both together
define('SMTP_ENCRYPTION', 'starttls'); // starttls (587), ssl (465) or none (plain text, only on a trusted network)
define('SMTP_USERNAME', '');        // '' connects without AUTH
define('SMTP_PASSWORD', '');

define('MAIL_TO', 'hello@example.com'); // where the contact form emails requests; '' sends none;
                                    // plain addresses, several separated by commas (not 'Name <a@b>')

define('ALERTLOOP_BASE_URL', '');   // alert channel alertloop: on when ALERTLOOP_API_KEY is set
define('ALERTLOOP_API_KEY', '');
define('TELEGRAM_BOT_TOKEN', '');   // alert channel telegram: on when the token is set; needs the chat id
define('TELEGRAM_CHAT_ID', '');     // a number, below
define('ALERT_MAIL_TO', '');        // alert channel mail: on when set; sent with the MAIL_* settings

define('INDEXNOW_KEY', '');         // IndexNow: 8–128 letters, digits, dashes; '' turns it off.
                                    // generate one: php -r 'echo bin2hex(random_bytes(16));'

define('AUTH_JWT_KEY', '');         // JWT verification
define('AUTH_JWT_ISSUER', '');

define('PROXY_IPS', []);            // IPs or CIDR ranges of a CDN / proxy in front of PHP (not host names);
                                    // X-Forwarded-For/-Proto are read only from these. Empty on shared hosting
                                    // and nginx + php-fpm; behind a proxy, empty means one rate limit for all
define('TRACE_TRUST_INCOMING', false); // reuse an upstream X-Trace-Id / X-Request-Id
```

The bot token comes from [@BotFather](https://t.me/BotFather) (`/newbot`). For the chat id, send
your bot a message (for a group, add the bot to it), then open
`https://api.telegram.org/bot<token>/getUpdates` and take `chat.id` from the reply: a number,
negative for a group. A bot cannot write first: without that message Telegram answers
`chat not found`.

> Optional: define `G_ANALYTICS` and the layout will add gtag when `DEV_MODE` is false. No constant,
> no analytics.

## Business — `app/Configs/app.php`

The file returns an array. Read it anywhere with `config('app.<path>')`.

```php
'default_route' => 'home',    // which pages/*.php answers "/"; /home redirects to "/"
'layout' => 'layout',          // components/<layout>.php
'url' => ['base' => 'https://example.com'],   // origin for canonical / OG / sitemap
```

### Locales (optional)

```php
'locales' => [
    'enabled'   => false,          // off ⇒ single-language site, no prefixes
    'default'   => 'en',           // served without a prefix
    'supported' => ['en'],
    'names'     => ['en' => 'English', 'ru' => 'Русский'],
],
```

### SEO

```php
'seo' => [
    'site_name'      => 'Weft',
    'default_title'  => 'Weft — a lightweight PHP engine',  // used when a page sets no title
    'title_template' => '%s — Weft',    // Seo::set(['title' => 'About']) → "About — Weft"
    'page_suffix'    => ' — Page %d',   // page 2+ of a Pagination list: "Blog — Page 2 — Weft"; '' gives "Blog 2 — Weft"
    'description'    => '…',
    'og_image'       => '/img/og.png',  // under public/ (BASE_URI is added) or a full URL
    'og_image_alt'   => 'Weft social card',  // describes og_image; a page setting its own
                                             // image without an alt gets none, not this one
    'og_image_width' => 1200,           // both or neither, and only if every OG image on the
    'og_image_height'=> 630,            // site is that size; a page may override or clear them
    'twitter'        => '',
    'robots'         => 'index, follow',
    'robots_allow'   => true,           // robots.txt (DEBUG always blocks indexing); it lists
                                        // /sitemap.xml when app.sitemap is a non-empty list
                                        // or a callable (not called for robots.txt)
    'robots_disallow'=> ['/api/'],      // for every crawler, in every group too
    'robots_groups'  => [               // per-crawler rules; presets ai-search, ai-user, ai-training
        ['agents' => ['ai-training'], 'disallow' => ['/']],   // (Features, "Crawlers and AI assistants")
    ],
    'json_ld'        => [ /* one schema or a list */ ],
],
```

### Sitemap

A plain list of routes:

```php
'sitemap' => [
    ['path' => '/',      'changefreq' => 'weekly',  'priority' => '1.0'],
    ['path' => '/about', 'changefreq' => 'monthly', 'priority' => '0.7'],
],
```

…or a callable, when URLs come from content:

```php
'sitemap' => static fn(): array => (new DocsService())->sitemapRoutes(),
```

A route may carry `lastmod` where a real modification date exists:

```php
['path' => '/notes/hello', 'lastmod' => '2026-08-24', 'changefreq' => 'monthly'],
```

Give it only for routes whose date you actually know, such as content with a date in its front
matter. Search engines ignore `changefreq` and `priority` and do read `lastmod`, but only while
they trust it: a date derived from a file mtime or set to today on every build teaches them to
ignore the field, and a distrusted `lastmod` is worth less than an absent one. Mixing routes with
and without it is valid.

### llms.txt and IndexNow

```php
'llms' => [                       // /llms.txt; an array or a callable returning one; unset: 404
    'title' => 'Acme', 'summary' => '…', 'details' => '…',
    'sections' => ['Docs' => [['url' => '/start', 'title' => 'Start', 'notes' => '…']]],
],
'indexnow' => [
    'key' => defined('INDEXNOW_KEY') ? INDEXNOW_KEY : '',   // served at /<key>.txt; '' turns it off
    'state' => 'storage/indexnow.json',   // what weft-indexnow submitted; relative to the site root
    'endpoint' => 'https://api.indexnow.org/indexnow',
],
```

Both are described in [Features](/docs/features), "Crawlers and AI assistants" and "IndexNow".

### The rest

```php
'vite'       => ['entry' => 'src/main.js', 'dev_server' => 'http://localhost:5173'],
'cache'      => ['enabled' => true, 'driver' => 'file', 'path' => '…/storage/cache'],  // on in this demo, see below; path: absolute or relative to APP_ROOT, defaults to storage/cache
'rate_limit' => ['max' => 20, 'window' => 60, 'path' => '…'],  // per client IP (IPv6: per /64) per bucket; path: absolute or relative to APP_ROOT, defaults to ratelimit/ in cache.path
'captcha'    => ['enabled' => …, 'driver' => 'recaptcha', 'site_key' => …, 'secret_key' => …, 'threshold' => 0.5, 'allowed_hosts' => ['example.com']],
'trace'      => ['log_body' => true, 'sensitive_keys' => [], 'retention_days' => 30],    // see below
'security'   => ['frame_options' => 'SAMEORIGIN', 'referrer_policy' => 'strict-origin-when-cross-origin',
                 'permissions_policy' => 'geolocation=(), microphone=(), camera=()', 'csp' => '',
                 'hsts' => false, 'hsts_max_age' => 31536000, 'hsts_subdomains' => false],
```

HSTS is off by default and in this example. With `'hsts' => true` every response outside
`DEV_MODE` carries `Strict-Transport-Security: max-age=<hsts_max_age>`, plus `; includeSubDomains`
only with `'hsts_subdomains' => true`. A browser keeps it for `max-age` seconds; turning `hsts` off
does not shorten that, only `max-age=0` sent to each returning visitor does. Read "HTTPS and HSTS" in
[Deployment](/docs/deployment) before turning it on.

The session cookie (set in `public/index.php`) is `HttpOnly`, `SameSite=Lax`, and `Secure` when the
request came over HTTPS (`Request::isHttps()`: `X-Forwarded-Proto` from a proxy in `PROXY_IPS`,
otherwise `$_SERVER['HTTPS']`); `session.use_strict_mode` makes PHP replace a session id it did not
issue. Behind a proxy that talks plain HTTP to PHP, list it in `PROXY_IPS` or pass `HTTPS=on` to
PHP, or the cookie goes without `Secure`: "HTTPS behind a proxy" in [Deployment](/docs/deployment)
shows how.

Caching is deliberately **on** in this demo (`'cache' => ['enabled' => true]` in
`app/Configs/app.php`): it makes Markdown rendering cheap and shows the feature working in a real
setting rather than as a switched-off example. Caching is optional — with `enabled => false`,
`Cache::remember()` still runs its callback. Cached values come back without their classes: cache
arrays and scalars, an object is returned as `__PHP_Incomplete_Class`.

Do not set `rate_limit.path` to `cache.path` itself: `cache-clear.php` deletes the `*.cache` files
there, rate-limit counters included. Any other directory, a subdirectory of the cache one too, is fine.

With `'driver' => 'redis'`, keys start with `app.cache.prefix`, by default the host of
`app.url.base` and a colon (`example.com:`), so sites sharing a Redis keep apart. Cache entries are
under `<prefix>cache:`, rate-limit counters and alert windows under the rest of `<prefix>`, and
`php scripts/cache-clear.php` deletes only the cache entries. Set `'prefix' => 'yoursite:'` in
`'cache'` when two sites on one Redis share a host.

Note how feature flags derive from the environment, so there is one source of truth:

```php
'captcha' => [
    'enabled'    => defined('CAPTCHA_SECRET_KEY') && CAPTCHA_SECRET_KEY !== '',
    'driver'     => 'recaptcha',
    'site_key'   => defined('CAPTCHA_SITE_KEY') ? CAPTCHA_SITE_KEY : '',
    'secret_key' => defined('CAPTCHA_SECRET_KEY') ? CAPTCHA_SECRET_KEY : '',
],
```

So the keys `vendor/bin/weft-health` names under `captcha_missing_keys` (`site_key`, `secret_key`;
`driver` when it is not `recaptcha` or `smartcaptcha`) are set as `CAPTCHA_SITE_KEY` and
`CAPTCHA_SECRET_KEY` in `config/config.php`. To disable the captcha in this example, set
`CAPTCHA_SECRET_KEY` to `''`. `'driver' => 'smartcaptcha'` verifies Yandex SmartCaptcha tokens with
the same keys, but the example's pages are written for reCAPTCHA: switching the driver alone makes
the form reject every request. Change `components/layout.php`, `pages/contact.php` and
`api/contact.php` with it ([Features](/docs/features), "SmartCaptcha in this example").

### Mail

```php
'mail' => [
    'driver'    => 'mail',               // 'mail' (PHP mail()) or 'smtp'
    'from'      => 'noreply@site.test',  // sender of every email: a plain address
    'from_name' => 'Weft docs',          // optional display name
    'smtp' => [
        'host'       => 'smtp.site.test',
        'port'       => 587,             // default: 465 with ssl, otherwise 587
        'encryption' => 'starttls',      // starttls, ssl or none; default: ssl on port 465, otherwise starttls
        'username'   => '',              // '' sends without AUTH
        'password'   => '',
        'timeout'    => 10,              // seconds for the whole exchange
    ],
],
```

The example fills these from `MAIL_DRIVER`, `MAIL_FROM` and `SMTP_*`. `vendor/bin/weft-health` lists
what is missing under `mail_errors` (`app.mail.from`, `app.mail.smtp.host` with `smtp`).

### Alerts

```php
'alerts' => [
    'channels' => ['alertloop', 'telegram', 'mail', 'log'],  // any of these, tried in this order
    'mail_to'  => 'ops@site.test',                           // channel mail: one address or a list
    'telegram' => ['bot_token' => '…', 'chat_id' => '…'],    // channel telegram
],
'alertloop' => [                       // channel alertloop
    'base_url' => defined('ALERTLOOP_BASE_URL') ? ALERTLOOP_BASE_URL : '',
    'api_key' => defined('ALERTLOOP_API_KEY') ? ALERTLOOP_API_KEY : '',
    'source' => 'weft-docs',   // your site's name in AlertLoop
    'timeout' => 4,            // seconds a send waits for AlertLoop
],
```

`channels` is the only switch: an empty list or no key sends no alerts. `log` writes the alert to
`errors/` and reaches nobody. The example lists `alertloop`, `telegram` and `mail` when
`ALERTLOOP_API_KEY`, `TELEGRAM_BOT_TOKEN` and `ALERT_MAIL_TO` are set. A listed channel without its
settings is skipped, the error log gets a line, and `vendor/bin/weft-health` lists the setting under
`alert_errors`.

The example's contact form also sends an alert for every request (`business_event`, with the trace
id and no form data): `New contact request` at `info` when the email was accepted, `Contact request
not delivered` at `critical` when it was not. With `ALERT_MAIL_TO` the same as `MAIL_TO`, every request arrives as two
emails: give alerts an address of their own.

### Trace

```php
'trace' => [
    'log_body'       => true,   // false: log a body as its size and content type only
    'sensitive_keys' => [],     // e.g. ['passport', 'card_number'], Latin names only: masked on top of password, token, api_key, …
    'retention_days' => 30,     // trace files older than this are deleted, in requests/ and cli/; 0 keeps them forever
],
```

A request body (`request_start`) and the body of an outgoing `HttpClient` call (`http_out_request`)
are logged as `body: {size, content_type, data}`; a request without a body has `body: null`. These
go into `data` as fields, so the masking below reaches them: a body that decodes as a JSON object
or array, whatever its content type (`application/vnd.api+json`, none, JSON sent by `curl -d`);
any other `application/x-www-form-urlencoded` body, parsed as a form; multipart form fields.
Uploaded files are never logged. Any other body — XML, plain text, broken JSON not sent as a
form, a string you pass to `HttpClient` with `['json' => false]`, even a form string such as
`a=1&password=x` (pass an array to get it masked) — goes into `data` as the raw string,
which masking by key cannot look inside; traceloom shortens long strings. With `log_body` off,
and for any body over 8 KB, `body` has only `size` and `content_type`. A string `log_body` (from the environment) is read
as a boolean: `'true'`, `'1'`, `'yes'`, `'on'` keep it on, `'false'` and anything else turn it off.

The query string is logged apart from the URL, parsed into fields and masked the same way,
whatever `log_body` says: `request_start` has `uri` without the query and `query` with its
parameters; outgoing calls have `url` and `query`. A parameter or field name that is not valid
UTF-8 (`?%E8%EC%FF=1`) is logged with each bad byte replaced by `�` and a `~<16 hex>` suffix, so
it never overwrites another field: `���~<16 hex>`. The `referer` header is logged as sent, query string included: a browser moving
within your site sends the full URL of the previous page, so a `?token=` on one page shows up
unmasked in the next request's `referer`.

Weft masks nothing itself: traceloom does, by key name. Personal data such as an email or a phone
number stays in the trace unless you list its key in `sensitive_keys`. `log_body` covers bodies
only: fields your code writes with `Trace::event()` (the example's `contact_submit` records the
email) are logged with `log_body` off too; `sensitive_keys` masks them.

Key names are compared in lowercase with everything but the Latin letters `a`–`z` and the digits
`0`–`9` removed, so `first-name` also masks `first_name` and `FirstName`. Cyrillic and other
non-Latin letters are removed as well, so `sensitive_keys` works with Latin names only:
`'Пароль'` masks nothing, not even a field named `пароль`, and nothing warns you. Give the fields
you need masked Latin names. A key in `sensitive_keys` must match the whole name — `phone` does
not mask `customer_phone` — so list every name your forms use. traceloom's
built-in keys also mask any name that contains `password`, `secret`, `token`, `api_key`, `cookie`,
`session` or a few others (`user_password`).

A missing or negative `retention_days` means 30.

## Reading configuration

```php
use Golovanov\Weft\Config\Config;

Config::get('app.seo.site_name');
Config::get('app.cache.enabled', false);   // with a default
Config::env('DEBUG', false);               // an environment constant

config('app.seo.site_name');               // the global helper, for templates
```

`Config::set('app.x.y', …)` also exists — handy in tests.

Next: [Features](/docs/features).
