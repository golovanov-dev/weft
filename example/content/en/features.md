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
    'canonical'   => null,           // null ⇒ from the matched route; none on a 404
]);
Seo::noindex();
Seo::jsonLd(['@type' => 'TechArticle', 'headline' => '…']);
```

Rendered for you: title, description, robots, canonical, full Open Graph, Twitter card, hreflang
alternates and every JSON-LD block. Canonical, `og:url` and hreflang are built from the page the
engine matched, never from the URL as requested. A 404 gets none of the three — the engine's own and
a page that answers `http_response_code(404)` itself, like an unknown slug on a dynamic page. `/sitemap.xml` and `/robots.txt` are generated too; in `DEBUG`
robots blocks indexing automatically.

## Caching

```php
'cache' => ['enabled' => true, 'driver' => 'file'],   // or 'redis'
```

```php
use Golovanov\Weft\Cache\Cache;

$chapters = Cache::remember('docs:index', 600, fn() => $this->readAllMarkdown());
```

Also `Cache::get/set/has/forget/flush`, and `php scripts/cache-clear.php`, which deletes cache
entries only. Values come back without their classes, so cache arrays and scalars.

Two properties worth knowing: when caching is **off**, a NullStore is used — `remember()` still runs
the callback, so wrapping code in it is always safe and you can enable the cache later. And if Redis
is unreachable or rejects `REDIS_PASSWORD` or `REDIS_DB`, the engine falls back to the file store
instead of failing the request; `php scripts/health.php` then fails and says why. A store that fails
later (a read-only replica, `maxmemory` reached) does not fail the page either: reads are misses,
writes are skipped, and the error log gets one line per request.

> A useful trick used by this very site: put the file's `mtime` in the cache key. Editing content
> invalidates the entry automatically.

## Request tracing

Always on. Every request gets a correlation id and a single event timeline written to
`storage/logs/requests/<date>.jsonl` by [traceloom](https://github.com/golovanov-dev/traceloom),
with rotation, size caps and automatic redaction of keys like `password`, `token` and `api_key`.
Console scripts (`scripts/*.php`, cron jobs) write theirs to `storage/logs/cli/<date>.jsonl`, so a
script never shares a file with the site. Files older than 30 days are deleted.

The engine records `request_start` (method, path, query, headers, client IP, body), `request_end`
and outbound HTTP calls. JSON bodies (by content, whatever the content type), form bodies and
query strings are logged as fields, so the redaction reaches them; add your own key names with
`app.trace.sensitive_keys`. Any other body is logged as a raw string, and the `referer` header as
sent, query string included. A body over 8 KB is logged as its size and content type only. To keep
bodies out of the trace, set `app.trace.log_body` to `false`;
fields you pass to `Trace::event()` stay — mask them with `sensitive_keys`. All
three settings are in [Configuration](/docs/configuration).

Add your own events:

```php
use Golovanov\Weft\Observability\Trace;

Trace::event('order_created', ['order_id' => $id, 'amount' => $sum]);
Trace::id();   // the id, also returned to the client as X-Trace-Id
```

Read one request's timeline, or a script's:

```bash
vendor/bin/eventtrace show <trace_id> --dir=storage/logs/requests
vendor/bin/eventtrace show <trace_id> --dir=storage/logs/cli
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

Rate-limit counters live in Redis when `app.cache.driver` is `redis`, on the cache's connection and
apart from its keys (in files when Redis cannot be used, as for the cache), otherwise in files under
`app.rate_limit.path` (absolute, or relative to the site root `APP_ROOT`), which defaults to
`ratelimit/` in `app.cache.path` — whether caching is enabled or not. `cache-clear.php` does not
reset them. A window starts at the first hit and later hits never extend it; the limiter deletes
expired counter files now and then.
If a counter cannot be written, the limiter **fails open**: the request is allowed and the failure
goes to the error log, so a broken store never locks users out. `php scripts/health.php` shows
where counters go (`rate_limit_store`, `rate_limit_path`) and fails when they cannot be written.

Limits count per client IP, taken from `REMOTE_ADDR`; an IPv6 client counts by its `/64` network,
the block one host usually gets. On shared hosting and with nginx + php-fpm on
one machine that is usually the visitor, and `PROXY_IPS` stays empty. Behind a CDN, load balancer or
another server proxying to PHP it is the proxy's address, so every visitor shares one counter: the
example's contact form then takes one submission per 5 seconds for the whole site. List the proxies
in the `PROXY_IPS` constant (`config/config.php`) as IP addresses or CIDR ranges
(`'173.245.48.0/20'`, `'2400:cb00::/32'`); a host name such as `localhost` or a Docker service name
is ignored, and `php scripts/health.php` lists it under `proxy_ips_invalid`. For a request from one of them, the client IP is the rightmost `X-Forwarded-For` entry
that is not in `PROXY_IPS`; entries further left are set by the client and ignored. No other header
is read: a proxy that passes the address only in `X-Real-IP` or `X-Client-Ip`, or an entry that is
not an IP, leaves the proxy's address in place. To see which address Weft took, look at `client_ip`
in the `request_start` trace event. What to list for Cloudflare, a chain of proxies or Docker, and
how to check it: [Deployment](/docs/deployment).

The captcha is on when `app.captcha.enabled` is true and both keys are set; in this example the
flag follows `RECAPTCHA_SECRETKEY`. While the flag is off, `verify()` returns a pass
(`skipped => true`), so forms work in development without keys. With the flag on and a key
missing, `verify()` records a `captcha.misconfigured` trace event and a line in the error log
(`storage/logs/errors/`), then: with `DEBUG` on it passes, so the form works locally; with `DEBUG`
off it fails with the error code `misconfigured` and sends a critical alert (category `security`),
so in production every submission is rejected until both keys are set. `php scripts/health.php`
lists the missing keys under `captcha_missing_keys` and, with `DEBUG` off, fails.
A rejection the visitor did not cause — Google unreachable (`request-failed`), the secret refused
(`invalid-input-secret`), a token for a host not in `app.captcha.allowed_hosts` or for another
action — records a `captcha.failed` trace event and a line in the error log with the reason. A low
score records nothing.

`Csrf::check()`, `RateLimiter::honeypot()` and `Captcha::verify()` take the field as it came. A
value that is not a string is a wrong CSRF token (`_csrf[]=…`) and no captcha token
(`recaptcha_token[]=…`); a non-empty array in the honeypot is a filled field.

The usual order in an endpoint: **honeypot → CSRF → validation → rate limit → captcha → service.**
Validation before the rate limit lets a visitor correct a field and send again at once. See
`api/contact.php` in this example for the complete flow: it answers the form's script with JSON
(`422` lists the fields to correct as `errors: [{name, text}]`, `419` asks to refresh the page) and
redirects a form posted without JavaScript back to the contact page with `?status=`. It answers
success only when the request was delivered — AlertLoop accepted the alert or `mail()` sent the
email to `MAIL_TO` — and `503` otherwise: where to see why, and where the message stays, is in
[Deployment](/docs/deployment), "The contact form answers 503".

## Alerts for sensitive spots

In `app/Configs/app.php`, with `ALERTLOOP_BASE_URL` and `ALERTLOOP_API_KEY` set in
`config/config.php` (see [Configuration](/docs/configuration)):

```php
'alertloop' => [
    'enabled' => defined('ALERTLOOP_API_KEY') && ALERTLOOP_API_KEY !== '',
    'base_url' => defined('ALERTLOOP_BASE_URL') ? ALERTLOOP_BASE_URL : '',
    'api_key' => defined('ALERTLOOP_API_KEY') ? ALERTLOOP_API_KEY : '',
    'source' => 'weft-docs',   // your site's name in AlertLoop
    'timeout' => 4,            // seconds a send waits for AlertLoop
],
```

```php
use Golovanov\Weft\Observability\Alert;

Alert::send('New contact request', Alert::INFO, 'business_event', context: $data);
Alert::critical('Payment webhook failed', ['order' => $id]);
Alert::send('Captcha rejected', Alert::WARNING, dedupeKey: 'captcha-rejected', category: 'security');
```

Events go to [AlertLoop](https://github.com/golovanov-dev/alertloop) (`POST /v1/events`). `$context`
is sent as the event's `payload`, the current trace id travels as the top-level `trace_id`, and
`$type` is an AlertLoop event type (`incident`, `business_event`, `audit`) — use `category` for your
own labels.

Delivery is synchronous and best-effort: a send waits for AlertLoop up to `app.alertloop.timeout`
seconds, nothing is retried and no failure reaches your code. An alert with a dedupe key also waits
for the counter store that keeps its window (below): with Redis, 1 second at most for each of its
two commands, plus, when this request has not connected to Redis yet, 2 seconds to connect and 1 for
each of `AUTH`, `SELECT` and `PING`. So a send takes at most `app.alertloop.timeout` + 7 seconds. It is not silent, though — a delivery
that fails is recorded on the request trace as an `alert.failed` event (status, transport flag, event
type and category; never the alert body).

An alert with a dedupe key is sent once per five minutes: a repeat within that window is dropped
locally and `send()` returns `false`, so an error hit on every request does not make every request
wait for AlertLoop. The window opens when a send starts and is dropped when delivery fails, so the
next repeat tries again. It is kept where rate-limit counters are (Redis or files); if that store
fails, the alert is sent. Alerts without a dedupe key, such as `business_event`, are always sent.

Unhandled exceptions and fatals alert automatically. For an exception thrown by a page or an API
endpoint the 500 response goes out first and the alert after it, so under PHP-FPM the visitor does
not wait for AlertLoop. Database errors alert once the site registers a notifier, as this example's
`app/Bootstrap.php` does with `Database::setErrorNotifier(Alert::notifier())`; the same database
error message is one dedupe key.

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

Array keys become column names, so each must be a plain identifier (`[A-Za-z_][A-Za-z0-9_]*`);
anything else throws `InvalidArgumentException` before a query runs. Values are always bound.
A valid key is still written, so pass `create()` only the fields you expect, not the whole
request body.

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

`ok` is `false` when no response arrived, when the status is 400 or higher, or when the body is not
valid JSON (with `decodeJson`, the default); `status` and the decoded `body` are returned either way,
and `error` says what went wrong. Safe defaults: SSL verification on, short timeouts, JSON in/out,
and every call recorded on the current request's trace timeline.

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
