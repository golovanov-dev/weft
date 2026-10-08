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
define('BASE_URI', '');       // sub-directory mount point, '' for a domain root
define('APP_TIMEZONE', 'UTC');// every date, log and DB session uses this
define('LOGS_PATH', dirname(__DIR__) . '/storage/logs');
```

Database (only if you use `Golovanov\Weft\Database`):

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');
```

Redis (only for the `redis` cache driver):

```php
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_DB', 0);
define('REDIS_PASSWORD', '');
```

Integrations:

```php
define('RECAPTCHA_SITEKEY', '');    // public key → the frontend
define('RECAPTCHA_SECRETKEY', '');  // secret key → verification; setting it enables the captcha (needs the site key too)

define('ALERTLOOP_BASE_URL', '');   // e.g. https://alerts.example.com
define('ALERTLOOP_API_KEY', '');    // setting it enables alerts

define('MAIL_TO', 'hello@example.com');
define('MAIL_FROM', 'noreply@example.com');

define('AUTH_JWT_KEY', '');         // JWT verification
define('AUTH_JWT_ISSUER', '');

define('PROXY_IPS', []);            // IPs or CIDR ranges of a CDN / proxy in front of PHP (not host names);
                                    // X-Forwarded-For is read only from these. Empty on shared hosting
                                    // and nginx + php-fpm; behind a proxy, empty means one rate limit for all
define('TRACE_TRUST_INCOMING', false); // reuse an upstream X-Trace-Id / X-Request-Id
```

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
    'description'    => '…',
    'og_image'       => '/img/og.png',
    'og_image_alt'   => 'Weft social card',  // describes og_image; a page setting its own
                                             // image without an alt gets none, not this one
    'og_image_width' => 1200,           // both or neither, and only if every OG image on the
    'og_image_height'=> 630,            // site is that size; a page may override or clear them
    'twitter'        => '',
    'robots'         => 'index, follow',
    'robots_allow'   => true,           // robots.txt (DEBUG always blocks indexing)
    'robots_disallow'=> ['/api/'],
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

### The rest

```php
'vite'       => ['entry' => 'src/main.js', 'dev_server' => 'http://localhost:5173'],
'cache'      => ['enabled' => true, 'driver' => 'file', 'path' => '…/storage/cache', 'prefix' => 'weft:'],  // on in this demo, see below; path: absolute or relative to APP_ROOT, defaults to storage/cache; prefix applies to redis only
'rate_limit' => ['max' => 20, 'window' => 60, 'path' => '…'],  // per client IP per bucket; path: absolute or relative to APP_ROOT, defaults to cache.path
'captcha'    => ['enabled' => …, 'site_key' => …, 'secret_key' => …, 'threshold' => 0.5, 'allowed_hosts' => ['example.com']],
'alertloop'  => ['enabled' => …, 'base_url' => …, 'api_key' => …, 'source' => 'my-site', 'timeout' => 4],
'trace'      => ['log_body' => true, 'sensitive_keys' => [], 'retention_days' => 30],    // see below
'security'   => ['frame_options' => 'SAMEORIGIN', 'referrer_policy' => 'strict-origin-when-cross-origin',
                 'permissions_policy' => 'geolocation=(), microphone=(), camera=()', 'hsts' => true, 'csp' => ''],
```

Caching is deliberately **on** in this demo (`'cache' => ['enabled' => true]` in
`app/Configs/app.php`): it makes Markdown rendering cheap and shows the feature working in a real
setting rather than as a switched-off example. Caching is optional — with `enabled => false`,
`Cache::remember()` still runs its callback.

Note how feature flags derive from the environment, so there is one source of truth:

```php
'captcha' => [
    'enabled'    => defined('RECAPTCHA_SECRETKEY') && RECAPTCHA_SECRETKEY !== '',
    'site_key'   => defined('RECAPTCHA_SITEKEY') ? RECAPTCHA_SITEKEY : '',
    'secret_key' => defined('RECAPTCHA_SECRETKEY') ? RECAPTCHA_SECRETKEY : '',
],
```

So the keys `php scripts/health.php` names under `captcha_missing_keys` (`site_key`, `secret_key`)
are set as `RECAPTCHA_SITEKEY` and `RECAPTCHA_SECRETKEY` in `config/config.php`. To disable the
captcha in this example, set `RECAPTCHA_SECRETKEY` to `''`.

### Trace

```php
'trace' => [
    'log_body'       => true,   // false: log a body as its size and content type only
    'sensitive_keys' => [],     // e.g. ['passport', 'card_number'], Latin names only: masked on top of password, token, api_key, …
    'retention_days' => 30,     // trace files older than this are deleted; 0 keeps them forever
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
`body` has only `size` and `content_type`. A string `log_body` (from the environment) is read
as a boolean: `'true'`, `'1'`, `'yes'`, `'on'` keep it on, `'false'` and anything else turn it off.

The query string is logged apart from the URL, parsed into fields and masked the same way,
whatever `log_body` says: `request_start` has `uri` without the query and `query` with its
parameters; outgoing calls have `url` and `query`. A parameter or field name that is not valid
UTF-8 (`?%E8%EC%FF=1`) is logged with the bad bytes replaced by `�`, and `~` added if that name is
already taken. The `referer` header is logged as sent, query string included: a browser moving
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
