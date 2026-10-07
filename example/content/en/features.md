---
title: Features
description: i18n, SEO, caching, tracing, forms, alerts, database, JWT, HTTP client and Vite — how to switch each on and use it.
order: 4
---

# Features

Everything optional is a flag in `app/Configs/app.php`; secrets live in `config/config.php`. Until a
feature is switched on, it stays out of your way.

## Multilingual routing

Off by default. Turn it on:

```php
'locales' => [
    'enabled'   => true,
    'default'   => 'en',
    'supported' => ['en', 'ru'],
    'names'     => ['en' => 'English', 'ru' => 'Русский'],
],
```

URL rules: the default locale has **no prefix** (`/about`), others do (`/ru/about`), and hitting the
default locale *with* a prefix (`/en/about`) returns a 301 to `/about` — no duplicate content.

Translations are arrays in `lang/<locale>/`: `common.php` loads for every route, `<route>.php` for a
specific one. Missing keys fall back to the default locale, then to the key itself.

```php
t('nav.about');
t('greeting', ['name' => 'Roman']);   // "Hi, {name}" → "Hi, Roman"
I18n::raw('page.faq');                // raw value — e.g. an array of items
```

A language switcher and `hreflang` tags build themselves:

```php
use Golovanov\Weft\I18n\{Locale, Url};

foreach (Locale::supported() as $code) {
    echo '<a href="' . e(Url::inLocale($code, Url::currentPath())) . '">' . strtoupper($code) . '</a>';
}
```

## SEO

A page sets a few values; the layout calls `seo()`; the engine renders a complete `<head>`.

```php
use Golovanov\Weft\Seo\Seo;

Seo::set([
    'title'       => 'About',        // → title_template
    'description' => '…',
    'image'       => '/img/og-about.png',
    'image_alt'   => 'The About page card',
    'type'        => 'article',
    'canonical'   => null,           // null ⇒ derived from the current path
]);
Seo::noindex();
Seo::jsonLd(['@type' => 'TechArticle', 'headline' => '…']);
```

Rendered for you: title, description, robots, canonical, full Open Graph, Twitter card, hreflang
alternates and every JSON-LD block. `/sitemap.xml` and `/robots.txt` are generated too; in `DEBUG`
robots blocks indexing automatically.

## Caching

```php
'cache' => ['enabled' => true, 'driver' => 'file'],   // or 'redis'
```

```php
use Golovanov\Weft\Cache\Cache;

$chapters = Cache::remember('docs:index', 600, fn() => $this->readAllMarkdown());
```

Also `Cache::get/set/has/forget/flush`, and `php scripts/cache-clear.php`.

Two properties worth knowing: when caching is **off**, a NullStore is used — `remember()` still runs
the callback, so wrapping code in it is always safe and you can enable the cache later. And if Redis
fails to connect, the engine falls back to the file store instead of failing the request.

> A useful trick used by this very site: put the file's `mtime` in the cache key. Editing content
> invalidates the entry automatically.

## Request tracing

Always on. Every request gets a correlation id and a single event timeline written to
`storage/logs/requests/<date>.jsonl` by [traceloom](https://github.com/golovanov-dev/traceloom),
with rotation, size caps and automatic redaction of keys like `password`, `token` and `api_key`.
Files older than 30 days are deleted.

The engine records `request_start` (method, path, headers, client IP), `request_end` and outbound
HTTP calls. Request and outgoing bodies are recorded as size and content type only; the query
string in the request URI and outgoing URLs is logged as is. To debug with bodies, turn on
`app.trace.log_body`: JSON and form bodies are then logged as fields, masked by key; any other body
stays size and type. All three settings are in
[Configuration](/docs/configuration).

Add your own events:

```php
use Golovanov\Weft\Observability\Trace;

Trace::event('order_created', ['order_id' => $id, 'amount' => $sum]);
Trace::id();   // the id, also returned to the client as X-Trace-Id
```

Read one request's timeline:

```bash
vendor/bin/eventtrace show <trace_id> --dir=storage/logs/requests
```

## Forms: rate limiting, CSRF, honeypot, captcha

Rate limiting is **on by default**.

```php
use Golovanov\Weft\Security\{RateLimiter, Csrf, Captcha};

RateLimiter::allow('contact');            // app.rate_limit.max per window, per IP
RateLimiter::throttle('contact', 5);      // at most once every 5 seconds
RateLimiter::honeypot($body, 'website');  // true ⇒ a bot filled the hidden field

echo Csrf::field();                        // in the form (helper: csrf_field())
Csrf::check($body[Csrf::fieldName()] ?? null);

$result = Captcha::verify($body['recaptcha_token'] ?? '', 'contact');
if (!$result['ok']) { /* reject */ }
```

The rate limiter uses the cache when enabled, otherwise the session, and **fails open** — a missing
backend never locks users out. The captcha is enabled by setting both `RECAPTCHA_SITEKEY` and `RECAPTCHA_SECRETKEY`; while it is
off, `verify()` returns a pass (`skipped => true`) so forms work in development without keys.

The usual order in an endpoint: **honeypot → CSRF → rate limit → validation → captcha → service.**
See `api/contact.php` in this example for the complete flow.

## Alerts for sensitive spots

```php
'alertloop' => ['enabled' => true, 'base_url' => ALERTLOOP_BASE_URL, 'api_key' => ALERTLOOP_API_KEY],
```

```php
use Golovanov\Weft\Observability\Alert;

Alert::send('New contact request', Alert::INFO, 'business_event', context: $data);
Alert::critical('Payment webhook failed', ['order' => $id]);
Alert::send('Captcha rejected', Alert::WARNING, category: 'security');
```

Events go to [AlertLoop](https://github.com/golovanov-dev/alertloop) (`POST /v1/events`). `$context`
is sent as the event's `payload`, the current trace id travels as the top-level `trace_id`, and
`$type` is an AlertLoop event type (`incident`, `business_event`, `audit`) — use `category` for your
own labels.

Delivery is best-effort and non-blocking: nothing is retried and no failure reaches your code. It is
not silent, though — a delivery that fails is recorded on the request trace as an `alert.failed`
event (status, transport flag, event type and category; never the alert body). Unhandled exceptions
and fatals alert automatically; database errors alert once the site registers a notifier, as this
example's `app/Bootstrap.php` does with `Database::setErrorNotifier(Alert::notifier())`.

## Database

```php
use Golovanov\Weft\Database\Database;

$db = Database::connect();
$user  = $db->row('SELECT * FROM users WHERE id = ?', [$id]);
$rows  = $db->all('SELECT * FROM posts WHERE published = ?', [1]);
$count = $db->value('SELECT COUNT(*) FROM posts');
$db->execute('UPDATE posts SET views = views + 1 WHERE id = ?', [$id]);
$db->transaction(fn($db) => /* … */);
```

A thin PDO wrapper, one connection per process, with the DB session set to the application timezone
so `NOW()` matches `Clock`. `Golovanov\Weft\Database\Model` adds simple CRUD (`find`, `findBy`,
`where`, `create`, `update`, `delete`). It is not an ORM, and that is the point.

## JWT

```php
use Golovanov\Weft\Security\Jwt;

$claims = Jwt::decode($token);                                   // null on any failure
$token  = Jwt::fromBearer($request->header('authorization'));
$jwt    = Jwt::encode(['uid' => 42], ttl: 3600);
```

## HTTP client

```php
use Golovanov\Weft\Http\HttpClient;

$http = new HttpClient();
$res = $http->get('https://api.example.com/rates', ['base' => 'usd']);
$res = $http->post('https://api.example.com/orders', ['amount' => 100]);
// ['ok' => bool, 'status' => int, 'headers' => [...], 'error' => ?string, 'body' => mixed]
```

Safe defaults: SSL verification on, short timeouts, JSON in/out, and every call recorded on the
current request's trace timeline.

## Vite

```php
echo Golovanov\Weft\Assets\Vite::tags('src/main.js');   // already in the layout
```

`DEV_MODE=true` points at the dev server with HMR; `DEV_MODE=false` reads
`public/dist/.vite/manifest.json` and emits hashed, cache-busted files.

In production the tags also carry preload hints: `rel="modulepreload"` for the entry and every
chunk it statically imports (the manifest's `imports`, walked recursively) and `rel="preload"` for
the stylesheets, so the browser fetches them without waiting to parse the entry. Dynamic imports
are left out on purpose — preloading them would undo the code split.

## Security defaults

Sent on every response: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
`Permissions-Policy`, optional `Content-Security-Policy` and HSTS, plus `X-Trace-Id`.

Sanitising is split by direction: `Sanitize::input()` normalises incoming data (no HTML escaping),
`Sanitize::output()` escapes for HTML — that is what `e()` calls. Escape when you render, never when
you store.

Next: [Reference](/docs/reference).
