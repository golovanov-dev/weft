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
engine matched, never from the URL as requested. A canonical the page sets with
`Seo::set(['canonical' => …])` is a full URL or a link from `url()`; both are rendered absolute, in
the canonical and in `og:url`. A 404 gets none of the three — the engine's own and
a page that answers 404 itself (`Abort::notFound()` or `http_response_code(404)`), like an unknown
slug on a dynamic page. JSON-LD
is written with `<` and `>` escaped, so no value can end its `<script>` element. `/sitemap.xml` and
`/robots.txt` are generated too; in `DEBUG` robots blocks indexing automatically, and it lists the
sitemap only when `app.sitemap` is a non-empty list or a callable. robots.txt does not call the
callable, so a sitemap generator that fails cannot turn it into an error.

### Crawlers and AI assistants

robots.txt allows every crawler unless you add groups. A group names crawlers — user-agent tokens or
a preset — and the paths they may or may not fetch:

```php
'seo' => [
    'robots_disallow' => ['/api/'],                                  // every crawler, every group
    'robots_groups' => [
        ['agents' => ['ai-training'], 'disallow' => ['/']],         // no training on this site
        ['agents' => ['Yandex', 'Bingbot'], 'disallow' => ['/drafts']],
    ],
],
```

| Preset | Crawlers |
|---|---|
| `ai-search` | `OAI-SearchBot`, `Claude-SearchBot`, `PerplexityBot`, `YandexAdditional` |
| `ai-user` | `ChatGPT-User`, `Claude-User`, `Perplexity-User` |
| `ai-training` | `GPTBot`, `ClaudeBot`, `Google-Extended`, `Applebot-Extended`, `CCBot` |

`ai-search` crawlers put your pages into an assistant's search answers, `ai-user` fetch a page a
user asked about, `ai-training` collect training data. A crawler obeys only the group that names it,
so each group also gets `robots_disallow`, and a group's `allow` inside one of those paths is left
out: it would reopen it. Operators say their user-triggered fetchers may ignore
robots.txt. In `DEBUG` or with `robots_allow` off, groups are left out and everything is closed.
Preset names are lowercase; any other name is written as a user-agent as is.

A `robots.txt` or `llms.txt` file in `public/` is served by the web server instead of the engine's,
and the settings here do nothing: delete it. With `robots_groups` or `llms` set, `weft-health` fails
on such a file.

`/llms.txt` ([llmstxt.org](https://llmstxt.org)) tells an assistant what the site is and where its
main pages are. It is served when `app.llms` is set — an array, or a callable returning one, called
only on that request:

```php
'llms' => [
    'title' => 'Acme',                 // default: seo.site_name
    'summary' => 'Garden tools, made in Leeds since 1998.',
    'details' => 'Prices include VAT. Orders ship within two days.',
    'sections' => [
        'Shop' => [['url' => '/tools', 'title' => 'Tools', 'notes' => 'The full catalogue']],
        'Optional' => [['url' => '/blog', 'title' => 'Blog']],
    ],
],
```

A `url` without a scheme is a path of the site and is written as a full URL. A callable that throws
is a 500 of `/llms.txt` alone, with a line in `errors/`.

### IndexNow

[IndexNow](https://www.indexnow.org) tells Bing, Yandex and other participating search engines which
pages are new or changed. Set a key of 8–128 letters, digits and dashes:

```php
'indexnow' => ['key' => defined('INDEXNOW_KEY') ? INDEXNOW_KEY : ''],   // other keys: Configuration
```

The engine serves the key at `/<key>.txt`. `vendor/bin/weft-indexnow`, run in the site root, submits
the sitemap addresses that are new or have a new `lastmod`: the first run submits all of them. An
address is remembered only once the endpoint accepts it, in the state file — `cache-clear` does not
touch it. Removed pages are not submitted. A change is a new `lastmod`: an address without one is
submitted once, when it is new, and never after an edit — the command says how many there are. To
have edits submitted, give the routes a `lastmod` ([Configuration](/docs/configuration),
"Sitemap"). `--dry-run` lists the addresses without submitting; in `DEBUG` nothing is submitted. It
refuses to run while `app.url.base` is on example.com. It exits with 1 when it refuses or the
endpoint does, 2 on an unknown option (`--help` prints the usage). Run it from cron:
[Deployment](/docs/deployment), "IndexNow".

### SEO audit

`vendor/bin/weft-seo-audit` checks a live site over HTTP, so it runs anywhere: your machine or CI.
Every page must answer 200 with HTML, have a title, a meta description, a canonical link and
exactly one `h1`, JSON-LD that parses, and no `noindex` (or `none`) for all bots or for googlebot
in meta robots, meta googlebot or `X-Robots-Tag`. It reads a sitemap of pages, not a sitemap index:
for a site with an index, list the pages with `--pages`. A site behind Basic auth gets its user
name and password from the environment, never from the address (an address with `@` anywhere is refused; write `%40` in a path):
`WEFT_AUDIT_AUTH=user:pass`, sent only to the address you gave.

```bash
vendor/bin/weft-seo-audit https://example.com               # the pages in its sitemap.xml
vendor/bin/weft-seo-audit --pages https://example.com/ https://example.com/about
vendor/bin/weft-seo-audit --limit=50 https://example.com    # at most 50 pages (default 500)
WEFT_AUDIT_AUTH='user:pass' vendor/bin/weft-seo-audit https://staging.example.com   # behind Basic auth
```

It prints a line per page and exits with 1 when a page has a problem, 2 when it could not run. It
needs the PHP `dom` extension. The sitemap names pages by `app.url.base`; when that is another
address (production, for a staging copy), the audit checks the same paths on the address you gave
and says so in the first line.

### Pagination

`Pagination` turns `?page=N` into numbers and links; the page draws the navigation itself:

```php
use Golovanov\Weft\Http\Pagination;

$pages = Pagination::fromRequest($request, count($posts), 10);
foreach (array_slice($posts, $pages->offset, $pages->limit) as $post) { /* … */ }
if ($pages->prev !== null) echo '<a href="' . e($pages->prev) . '">Newer</a>';
if ($pages->next !== null) echo '<a href="' . e($pages->next) . '">Older</a>';
```

You get `page`, `pages` (at least 1), `total`, `offset`, `limit`, and `prev`, `next` and
`url($n)` as site-relative links (base URI and locale prefix included, other query parameters kept,
no `?page` for page 1). The engine keeps one address per page: `?page=1` is a 301 to the address
without it, and a value other than `2`, `3`, … (`0`, `abc`, `02`) or past the last page shows the
404 page. An empty list has page 1. Page 2 and later get their own canonical, `og:url` and hreflang
with `?page=N`. Without a canonical of its own the page's address is used, with no other
parameters. A canonical the page set with `Seo::set(['canonical' => …])` keeps its own query: the
number replaces a `page=` it had and goes before a `#fragment`. hreflang always uses the page's own
address with `?page=N`, not the canonical the page set. It does not matter whether `Seo::set()`
comes before or after `Pagination`.

Page 2 and later also get a title suffix: `app.seo.page_suffix`, by default ` — Page %d`, so
"Blog — Page 2 — Weft". `%d` is the page number; a suffix without it gets the number after a space,
an empty one too ("Blog 2 — Weft"): pages of a list never share a title.
A multilingual site translates it: put the key in `lang/<locale>/common.php` of every locale,
the default one included (a locale without the key falls back to the default locale's text, and
without that `t()` returns the key itself), and set it on the page:

```php
// lang/en/common.php:  'pagination' => ['suffix' => ' — Page %d'],
// lang/ru/common.php:  'pagination' => ['suffix' => ' — страница %d'],
Seo::set(['page_suffix' => t('pagination.suffix')]);
```

`Pagination` works in an `api/` endpoint too: there a page that does not exist is the standard
JSON 404 and `?page=1` the same 301.

### Structured data

`Schema` builders add a JSON-LD block after checking the fields search engines need. Fields are
schema.org names; `@type` may name a subtype (`BlogPosting`, `Restaurant`).

```php
use Golovanov\Weft\Seo\Schema;

Schema::organization(['name' => 'Acme', 'url' => 'https://acme.test', 'logo' => '…']);
Schema::article(['@type' => 'BlogPosting', 'headline' => $post->title, 'datePublished' => '2026-10-09']);
Schema::product(['name' => 'Mug', 'offers' => ['price' => '9.50', 'priceCurrency' => 'EUR']]);
Schema::faq(['Is it free?' => 'Yes, MIT.', 'Does it need a database?' => 'No.']);
Schema::breadcrumbs(['Home' => url('/'), 'Blog' => url('/blog'), $post->title => null]);
```

| Builder | Required |
|---|---|
| `organization()`, `webSite()` | `name`, `url` |
| `localBusiness()` | `name`, `address` |
| `article()` | `headline` |
| `product()` | `name`; `offers`, `review` or `aggregateRating`; every offer `price` and `priceCurrency` |
| `faq()` | at least one question with an answer |
| `breadcrumbs()` | at least one crumb; a name each, and a URL for all but the last |

`faq()` and `breadcrumbs()` also take a list of pairs (`[['Home', url('/')], …]`). A breadcrumb URL
is a full URL or the link `url()` returned — the same one your HTML trail uses; the builder adds the
scheme and host from `app.url.base` and nothing else, so the base URI and locale prefix are not
doubled. That also means a path written by hand (`'/blog'`) loses them: under a base URI or in a
second locale it points at the wrong page, with no error. Always pass `url()`. The breadcrumb trail on the page is your HTML. A missing required field (`null`, `''`, `[]`) throws in
`DEBUG`; in production the block is left out, a line goes to `errors/`, and the builder returns
`false`. Any other schema still goes through `Seo::jsonLd()`.

## API endpoints

`api/<name>.php` (or `api/<name>/index.php`) answers `/api/<name>`; `api_url('<name>')` builds the
link. The engine runs the file with `$request` in scope and turns what it prints into the response:

- the body is what the file echoes; its `return` value is ignored;
- the status is the one it set (`http_response_code()`, or a `Response` it `send()`s), otherwise 200;
- `Content-Type` is always `application/json; charset=utf-8`, whatever the file set; its other
  headers (`Location`) are sent as set. A redirect (`Abort::redirect()`) has no body and is not
  JSON;
- an uncaught throwable discards the printed output and becomes a JSON 500, logged and alerted like
  any other;
- `exit` or `die` bypasses the engine: the output goes out with PHP's default `Content-Type`, and the
  trace has no `request_end`. Use `return` to stop early.

`api_url()` adds no locale prefix, so an endpoint runs in the default locale, and no translations are
loaded: `t()` returns the key. Write an endpoint's texts in the file.

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
instead of failing the request; `vendor/bin/weft-health` then fails and says why. A store that fails
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

The example's `api/contact.php` runs them in this order, and each one answers before the next runs:

1. honeypot — a filled hidden field gets a fake success, so the bot learns nothing;
2. CSRF — 419: the page has to be reloaded;
3. the fields — 422 with the list, before the rate limit, so correcting a field is not throttled;
4. rate limit — 429;
5. captcha — 400; last, as it costs a request to Google or Yandex;
6. record and deliver (`ContactService`) — the request goes into the trace, then out by email, and
   an alert without the form data says it came; 503 when the email was not accepted, 200 otherwise.

Rate-limit counters live in Redis when `app.cache.driver` is `redis`, on the cache's connection and
apart from its keys (in files when Redis cannot be used, as for the cache), otherwise in files under
`app.rate_limit.path` (absolute, or relative to the site root `APP_ROOT`), which defaults to
`ratelimit/` in `app.cache.path` — whether caching is enabled or not. `cache-clear.php` does not
reset them. A window starts at the first hit and later hits never extend it; the limiter deletes
expired counter files now and then.
If a counter cannot be written, the limiter **fails open**: the request is allowed and the failure
goes to the error log, so a broken store never locks users out. `vendor/bin/weft-health` shows
where counters go (`rate_limit_store`, `rate_limit_path`) and fails when they cannot be written.

Limits count per client IP, taken from `REMOTE_ADDR`; an IPv6 client counts by its `/64` network,
the block one host usually gets. On shared hosting and with nginx + php-fpm on
one machine that is usually the visitor, and `PROXY_IPS` stays empty. Behind a CDN, load balancer or
another server proxying to PHP it is the proxy's address, so every visitor shares one counter: the
example's contact form then takes one submission per 5 seconds for the whole site. List the proxies
in the `PROXY_IPS` constant (`config/config.php`) as IP addresses or CIDR ranges
(`'173.245.48.0/20'`, `'2400:cb00::/32'`); a host name such as `localhost` or a Docker service name
is ignored, and `vendor/bin/weft-health` lists it under `proxy_ips_errors`. For a request from one of them, the client IP is the rightmost `X-Forwarded-For` entry
that is not in `PROXY_IPS`; entries further left are set by the client and ignored. No other header
is read: a proxy that passes the address only in `X-Real-IP` or `X-Client-Ip`, or an entry that is
not an IP, leaves the proxy's address in place. To see which address Weft took, look at `client_ip`
in the `request_start` trace event. From the same proxies Weft reads `X-Forwarded-Proto`:
`Request::isHttps()` is true when its last value is `https` or, without the header, when PHP sees
HTTPS; the example uses it for the `Secure` flag of the session cookie. What to list for Cloudflare,
a chain of proxies or Docker, and how to check it: [Deployment](/docs/deployment).

The captcha is on when `app.captcha.enabled` is true and both keys are set; in this example the
flag follows `CAPTCHA_SECRET_KEY`. `app.captcha.driver` picks the provider: `recaptcha` (Google
reCAPTCHA v3, the default) or `smartcaptcha` (Yandex SmartCaptcha). Either way only the secret and
the token go to the provider. reCAPTCHA is checked for score (`threshold`), action and host;
SmartCaptcha has no score or action and is checked for host (`score` is 1.0 when it passes). While
the flag is off, `verify()` returns a pass (`skipped => true`), so forms work in development without
keys. With the flag on and a key missing (or an unknown driver), `verify()` records a
`captcha.misconfigured` trace event and a line in the error log (`storage/logs/errors/`), then: with
`DEBUG` on it passes, so the form works locally; with `DEBUG` off it fails with the error code
`misconfigured` and sends a critical alert (category `security`), so in production every submission
is rejected until both keys are set. `vendor/bin/weft-health` lists the missing keys under
`captcha_missing_keys` and fails. A rejection the visitor did not cause — the
provider unreachable (`request-failed`), the secret refused (`invalid-input-secret`), a token for a
host not in `app.captcha.allowed_hosts` or for another action — records a `captcha.failed` trace
event and a line in the error log with the reason. A low score records nothing; a SmartCaptcha
token that failed (`invalid-input-response`) leaves Yandex's message in the error log.

`Csrf::check()`, `RateLimiter::honeypot()` and `Captcha::verify()` take the field as it came. A
value that is not a string is a wrong CSRF token (`_csrf[]=…`) and no captcha token
(`recaptcha_token[]=…`); a non-empty array in the honeypot is a filled field.

The usual order in an endpoint: **honeypot → CSRF → validation → rate limit → captcha → service.**
Validation before the rate limit lets a visitor correct a field and send again at once. See
`api/contact.php` in this example for the complete flow: it answers the form's script with JSON
(`422` lists the fields to correct as `errors: [{name, text}]`, `419` asks to refresh the page) and
redirects a form posted without JavaScript back to the contact page with `?status=`. It answers
success only when the request was delivered — the email to `MAIL_TO` was accepted — and `503`
otherwise: where to see why, and where the message stays, is in
[Deployment](/docs/deployment), "The contact form answers 503".

### SmartCaptcha in this example

This example's pages are written for reCAPTCHA. Setting `'driver' => 'smartcaptcha'` alone makes the
form reject every request: the page still asks Google for a token, none comes, and the server gets
an empty one. Change three files together:

1. `components/layout.php` — inside `<?php if (Captcha::enabled()): ?>` at the end, replace the
   `https://www.google.com/recaptcha/api.js` script with Yandex's:

   ```html
   <script src="https://smartcaptcha.yandexcloud.net/captcha.js" defer></script>
   ```

2. `pages/contact.php` — inside the `<form>`, replace `<input type="hidden" name="recaptcha_token">`
   with the widget; it adds a hidden `smart-token` field with the token:

   ```php
   <?php if (Captcha::enabled()): ?>
     <div class="smart-captcha" data-sitekey="<?= e(Captcha::siteKey()) ?>"></div>
   <?php endif; ?>
   ```

3. `api/contact.php` — step 5 verifies the widget's field instead of `recaptcha_token`:

   ```php
   $captcha = Captcha::verify($field('smart-token'));
   ```

`data-recaptcha` on the form can stay: without Google's script the form's script skips the token
step and posts the form with the widget's field.

## Mail

```php
use Golovanov\Weft\Mail\Mail;

$sent = Mail::send('owner@site.test', 'New order', "Order 42\nTotal: 100", replyTo: $customerEmail);
```

Plain-text email in UTF-8, sent by `app.mail.driver` ([Configuration](/docs/configuration), "Mail"):
`mail` hands it to PHP `mail()`, `smtp` talks to the server in `app.mail.smtp` (STARTTLS on 587 or
TLS on 465, certificate verified, AUTH PLAIN or LOGIN). `send()` returns `true` when the driver
accepted the message — `mail()` returned `true`, or the SMTP server answered `250` — which is not
yet delivery to the mailbox. It never throws: a message that is not sent leaves a line
`Mail not sent (<driver>): <reason>` in `errors/` and returns `false`. Addresses are plain
(`name@site.test`); one with a line break or a display name is refused, and a line break in the
subject becomes a space, so neither can add a header. The trace gets a `mail_out` event with the
driver, number of recipients, size and outcome — never the subject, the body or an address.

With `smtp` the whole exchange, connecting included, waits at most `app.mail.smtp.timeout` seconds
(default 10), except the TLS handshake, which has a limit of its own: with `ssl` or `starttls` up to
twice the timeout. Looking up `SMTP_HOST` in DNS comes on top and no timeout bounds it. With `mail`
it waits as long as the local sendmail takes.

## Alerts for sensitive spots

```php
use Golovanov\Weft\Observability\Alert;

Alert::send('New contact request', Alert::INFO, 'business_event', context: $data);
Alert::critical('Payment webhook failed', ['order' => $id]);
Alert::send('Captcha rejected', Alert::WARNING, dedupeKey: 'captcha-rejected', category: 'security');
```

An alert goes to every channel listed in `app.alerts.channels`, in that order
([Configuration](/docs/configuration), "Alerts"):

| Channel | Where | Settings |
|---|---|---|
| `alertloop` | [AlertLoop](https://github.com/golovanov-dev/alertloop), `POST /v1/events` | `app.alertloop.*` |
| `telegram` | a message from your bot to a chat | `app.alerts.telegram.bot_token`, `chat_id` |
| `mail` | an email through `Mail` | `app.alerts.mail_to`, `app.mail.*` |
| `log` | a line in `errors/`; reaches nobody | — |

No channels, no alerts. `send()` returns `true` when a channel that reaches a person (`alertloop`,
`telegram`, `mail`) took the alert; `log` alone always gives `false`. For AlertLoop, `$context` is
the event's `payload`, the current trace id is the top-level `trace_id`, and `$type` is an AlertLoop
event type (`incident`, `business_event`, `audit`) — use `category` for your own labels. Telegram and
mail get the message, type, category, site, trace id and `$context` as text. The `log` line has the
severity, type, category and message, without `$context`.

Delivery is synchronous and best-effort: nothing is retried and no failure reaches your code.
Channels are tried one after another, each up to its own timeout; an alert with a dedupe key also
waits for the counter store that keeps its window (below). It is not silent, though — a
channel that fails is recorded on the request trace as an `alert.failed` event (channel, status,
transport flag, event type and category; never the alert body, never the bot token). A listed
channel without its settings is skipped and the error log says so once per request.

An alert with a dedupe key is sent once per five minutes: a repeat within that window is dropped
locally and `send()` returns `false`, so an error hit on every request does not make every request
wait for the channels, even when the first send failed. It is kept where rate-limit counters are (Redis or files); if that store fails, the alert is sent.
Alerts without a dedupe key, such as `business_event`, are always sent.

Unhandled exceptions and fatals alert automatically. For an exception thrown by a page or an API
endpoint the 500 response goes out first and the alert after it, so under PHP-FPM the visitor does
not wait for the channels. A database error your code does not catch takes the same path: the alert
reads `Unhandled exception: Database query failed` (or `Database connection error`), and the MySQL
message is only in `errors/`.

## Database

```php
use Golovanov\Weft\Database\Database;

$db = Database::connect();
$user    = $db->row('SELECT * FROM users WHERE id = ?', [$id]);
$rows    = $db->all('SELECT * FROM posts WHERE published = ?', [1]);
$count   = $db->value('SELECT COUNT(*) FROM posts');
$changed = $db->execute('UPDATE posts SET views = views + 1 WHERE id = ?', [$id]);
$db->transaction(fn($db) => /* … */);
```

A thin PDO wrapper over MySQL, one connection per process, configured by the `DB_*` constants
([Configuration](/docs/configuration)). `execute()` returns the number of rows the statement
changed: MySQL does not count a row an `UPDATE` leaves with the same values. The DB session gets
the application timezone, so `NOW()` matches `Clock`: by name (`Europe/Moscow`) when the MySQL
server has its time zone tables loaded, otherwise as the offset at connection time (`+03:00`).

Errors are thrown as `RuntimeException` with the `PDOException` as its previous, and the MySQL
message goes to `errors/`. `Database` sends no alert itself: an error nobody catches alerts as an
unhandled exception ("Alerts for sensitive spots", above).

If MySQL drops the connection (error 2006 or 2013), the call throws and the next call opens a new
connection; nothing is repeated, since a statement cut off mid-way may already have run on the
server. A CLI script idle for longer than the server's `wait_timeout` gets the exception on its
first call, and the next call runs on a new connection. Inside `transaction()` a lost connection
throws `RuntimeException('Database connection lost in a transaction')`, and so does every later
call until `transaction()` returns, even if your callback catches the first one: the server has
already rolled the transaction back. A transaction you open yourself with
`pdo()->beginTransaction()` has no such guard: after a lost connection the next call reconnects and
writes without it; use `transaction()`. `transaction()` rolls back and rethrows whatever the callback
throws; if the rollback fails too, you still get the callback's exception, and the rollback failure
goes to `errors/`.

Connecting waits at most `DB_TIMEOUT` seconds (default 5). pdo_mysql has no read timeout of its
own: a query to a server that stopped answering waits `mysqlnd.net_read_timeout` seconds (PHP's
default is 86400). Set it in `php.ini`, or with `ini_set('mysqlnd.net_read_timeout', '30')` before
the first `Database::connect()`; a longer query is cut off and throws, as a lost connection does.

`Golovanov\Weft\Database\Model` adds simple CRUD (`find`, `findBy`, `where`, `exists`, `create`,
`update`, `delete`); `update()` and `delete()` return the changed rows, as `execute()` does. It is
not an ORM, and that is the point.

Array keys become column names, so each must be a plain identifier (`[A-Za-z_][A-Za-z0-9_]*`);
anything else throws `InvalidArgumentException` before a query runs. Column names and the primary
key go into the SQL in backticks, so a column named like a MySQL keyword (`order`, `key`) works;
the table name is written as you gave it (it may be `db.table`). Values are always bound.
A valid key is still written, so pass `create()` only the fields you expect, not the whole
request body.

## JWT

```php
use Golovanov\Weft\Security\Jwt;

$claims = Jwt::decode($token);                                   // null on any failure
$token  = Jwt::fromBearer($request->header('authorization'));
$jwt    = Jwt::encode(['uid' => 42], ttl: 900);                  // expires in 15 minutes
```

Keys come from `AUTH_JWT_KEY` unless passed. Every token expires: `encode()` sets `exp` an hour
ahead unless you pass `ttl` or an `exp` claim, and `decode()` rejects a token without `exp`. With
`AUTH_JWT_ISSUER` set, `encode()` adds it as `iss` and `decode()` rejects any other issuer.

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

## Health check

`vendor/bin/weft-health`, run in the site root, prints a JSON report and exits with 1 when the site
is not ready to serve: logs or rate-limit counters PHP cannot write, an unusable `PROXY_IPS`, a
captcha, an alert channel or mail without its settings, a static `robots.txt` or `llms.txt` that
hides the generated one, development settings (`DEBUG`, example.com, no built assets),
or a failed check of the site. What each field means: [Deployment](/docs/deployment), "What
`weft-health` checks".

The engine cannot know what your site needs, so the site adds its own checks in `app/Bootstrap.php`.
A check returns the fields to show and `ok`; anything but `ok => true`, or an exception, fails the
report. A minimal check that a contact form has an address to email (this example's
`App\Bootstrap::contactForm()` also fails on example.com and on an address that is not plain):

```php
Health::check('contact_form', static function (): array {
    $mailTo = (string) Config::get('app.contact.mail_to', '');
    return ['ok' => $mailTo !== '', 'mail_to' => $mailTo];
});
```

The result is under `checks.contact_form` in the report. Keep a check fast and free of side effects:
cron may run it every few minutes.

## Security defaults

Sent on every response: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`,
`Permissions-Policy`, optional `Content-Security-Policy` and HSTS (off by default; see
[Configuration](/docs/configuration)), plus `X-Trace-Id`.

Sanitising is split by direction: `Sanitize::input()` normalises incoming data (no HTML escaping),
`Sanitize::output()` escapes for HTML — that is what `e()` calls. Escape when you render, never when
you store.

Next: [Reference](/docs/reference).
