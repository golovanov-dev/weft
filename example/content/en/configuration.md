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
define('RECAPTCHA_SECRETKEY', '');  // secret key → verification; setting it enables the captcha

define('ALERTLOOP_BASE_URL', '');   // e.g. https://alerts.example.com
define('ALERTLOOP_API_KEY', '');    // setting it enables alerts

define('MAIL_TO', 'hello@example.com');
define('MAIL_FROM', 'noreply@example.com');

define('AUTH_JWT_KEY', '');         // JWT verification
define('AUTH_JWT_ISSUER', '');

define('PROXY_IPS', []);            // X-Forwarded-For is trusted only from these
define('TRACE_TRUST_INCOMING', false); // reuse an upstream X-Trace-Id
```

> Optional: define `G_ANALYTICS` and the layout will add gtag when `DEV_MODE` is false. No constant,
> no analytics.

## Business — `app/Configs/app.php`

The file returns an array. Read it anywhere with `config('app.<path>')`.

```php
'default_route' => 'home',    // which pages/*.php answers "/"
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
    'default_title'  => 'Weft — a lightweight PHP engine',  // used on "/"
    'title_template' => '%s — Weft',    // Seo::set(['title' => 'About']) → "About — Weft"
    'description'    => '…',
    'og_image'       => '/img/og.png',
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

### The rest

```php
'vite'       => ['entry' => 'src/main.js', 'dev_server' => 'http://localhost:5173'],
'cache'      => ['enabled' => true, 'driver' => 'file', 'path' => '…/storage/cache', 'prefix' => 'weft:'],  // on in this demo, see below
'rate_limit' => ['max' => 20, 'window' => 60],       // per client IP per bucket
'captcha'    => ['enabled' => …, 'threshold' => 0.5, 'allowed_hosts' => ['example.com']],
'alertloop'  => ['enabled' => …, 'source' => 'my-site', 'timeout' => 4],
'security'   => ['frame_options' => 'SAMEORIGIN', 'hsts' => true, 'csp' => ''],
```

Caching is deliberately **on** in this demo (`'cache' => ['enabled' => true]` in
`app/Configs/app.php`): it makes Markdown rendering cheap and shows the feature working in a real
setting rather than as a switched-off example. Caching is optional — with `enabled => false`,
`Cache::remember()` still runs its callback.

Note how feature flags derive from the environment, so there is one source of truth:

```php
'captcha' => [
    'enabled'    => defined('RECAPTCHA_SECRETKEY') && RECAPTCHA_SECRETKEY !== '',
    'secret_key' => defined('RECAPTCHA_SECRETKEY') ? RECAPTCHA_SECRETKEY : '',
],
```

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
