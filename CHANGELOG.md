# Changelog

All notable changes to Weft are documented in this file. Weft follows semantic versioning;
pre-1.0 the API may change between minor versions.

## 0.2.0 - 2026-10-11

To upgrade from 0.1.x, do what each entry marked **Action.** says (most say which sites they apply
to). The steps, the recipes and the rollback are in the upgrade guide,
[Upgrading](example/content/en/upgrading.md).

### Security

- **Action.** Request and `HttpClient` bodies and query strings are traced as fields, so masking by
  key reaches them; a body that is neither JSON nor a form stays a raw string. Weft masks nothing
  itself: list personal fields (`email`, `phone`) in the new `app.trace.sensitive_keys`, or set the
  new `app.trace.log_body` to `false` to log bodies as their size and content type only.
- **Action.** `Model` rejects a column name that is not a plain identifier with
  `InvalidArgumentException`: array keys went into the SQL as-is, so `create($request->body())` was
  an SQL injection. Still pass `create()` and `update()` only the fields you expect; write queries
  with quoted or qualified names (`posts.id`) with `Database`.
- **Action.** An enabled captcha without both keys no longer passes every submission: outside
  `DEBUG` it rejects them (`misconfigured`) and sends a critical alert. Set both keys or disable it.
- **Action.** `RateLimiter` counts per client IP (IPv6 per `/64`), atomically, in Redis or in files
  under the new `app.rate_limit.path`, not in the session, so clients without a session cookie are
  limited too. Make that directory writable by PHP; behind a proxy, list it in `PROXY_IPS`.
- **Action.** Behind a proxy in `PROXY_IPS`, the client IP is the rightmost `X-Forwarded-For` entry
  not in `PROXY_IPS`, and `X-Client-Ip` is no longer read: a forged header got around rate limits. A
  proxy that passes the address only in `X-Client-Ip` or `X-Real-IP` must append `X-Forwarded-For`.
- **Action.** The example's config template sent contact requests to the Weft author
  (`hello@golovanov.dev`); its addresses are now on example.com. If your config came from it, set
  `MAIL_TO` and `MAIL_FROM` of your own.

### Added

- `Mail::send()`: plain-text UTF-8 email through PHP `mail()` or SMTP (`app.mail.*`), with no extra
  package; it returns `false` and writes `Mail not sent` to `errors/` instead of throwing.
- Alert channels: `app.alerts.channels` lists any of `alertloop`, `telegram`, `mail` and `log`,
  tried in order; `alert.failed` trace events name the `channel`.
- Yandex SmartCaptcha: `app.captcha.driver` `smartcaptcha`, with the same keys and diagnostics as
  reCAPTCHA; the example's pages need three files changed (features guide).
- `vendor/bin/weft-health`, run in the site root, reports whether the site it boots is ready for
  production and exits with 1 when not (`--dev` on a development machine). Its fields:
  [Troubleshooting](example/content/en/troubleshooting.md).
- `Health::check($name, $check)` adds a check of the site to that report; the example checks
  `MAIL_TO` (`checks.contact_form`).
- `Request::isHttps()` believes `X-Forwarded-Proto` from proxies in `PROXY_IPS`; the example uses it
  for the `Secure` flag of the session cookie. **Action.** If your site copied `public/index.php`,
  take its session cookie block, which now comes after `require bootstrap.php`.
- `PROXY_IPS` accepts IPv4 and IPv6 CIDR ranges (`'173.245.48.0/20'`); host names are ignored.
- Catch-all pages: `pages/<dir>/[...path].php` serves every deeper URL with `$path = 'a/b/c'`; a
  template can `throw Abort::notFound()` or `Abort::redirect($url)`.
- `Pagination::fromRequest($request, $total, $perPage)` for `?page=N`: `?page=1` is a 301, a missing
  page a 404, and page 2 on gets its own canonical and a title suffix (`app.seo.page_suffix`).
- JSON-LD builders `Schema::organization()`, `localBusiness()`, `webSite()`, `article()`,
  `product()`, `faq()` and `breadcrumbs()`, which check the fields search engines require.
- robots.txt groups per crawler (`app.seo.robots_groups`) with the presets `ai-search`, `ai-user`
  and `ai-training`; without the key robots.txt is unchanged.
- `/llms.txt` from `app.llms`, an array or a callable; without the key it stays a 404.
- IndexNow: with `app.indexnow.key` set, the key is served at `/<key>.txt`, and
  `vendor/bin/weft-indexnow` (for cron) submits the sitemap addresses that are new or changed.
- `vendor/bin/weft-seo-audit <site-url>` checks a live site's pages over HTTP and exits with 1 when
  one fails, for CI. It needs `ext-dom`, now in `suggest`; Basic auth comes from `WEFT_AUDIT_AUTH`.

### Changed

- **Action.** The health check moved from the example's `scripts/health.php` into the engine,
  `vendor/bin/weft-health`: new fields, `ok` false on more, no `traceloom` or `alertloop_enabled`.
  Delete `scripts/health.php` and call the new command from deploy scripts, CI and cron.
- **Action.** Weft requires `golovanov/traceloom` `^0.5`: update with
  `composer require golovanov/weft:^0.2 -W`, and raise your own traceloom constraint if you have one.
- **Action.** Each page has one address: a GET or HEAD for `/about/`, `/home` or `/ru/` gets a 301 to
  `/about`, `/` or `/ru` (`Url::link('/', 'ru')`, hreflang and the sitemap give `/ru` too), and `/404` and
  `/<dir>/index` are a 404. Point your links at the final address, and run
  `php scripts/sitemap.php` again if you keep a static sitemap.
- **Action.** A path Weft used to clean up silently is a 404 (anything but `A-Z a-z 0-9 / _ - .`,
  an empty, `.` or `..` segment): fix links that use anything else.
- Canonical, `og:url` and hreflang are built from the matched route, never from the request URI; a
  404 gets none of them. `Url::routePath()` is the matched path.
- **Action.** Trace format: `body` is an object `{size, content_type, data}` or `null`, and the
  query string moved from `uri` and `url` to `query`. Update anything that parses traces.
- **Action.** Trace files are deleted after `app.trace.retention_days` (30), from the first request
  after the upgrade: set it before upgrading to keep them (`0` keeps them forever).
- **Action.** Console scripts (`cli` and `phpdbg`) trace to `LOGS_PATH/cli/`, not
  `LOGS_PATH/requests/`: point whatever reads script traces there.
- **Action.** A relative `app.cache.path` is taken from `APP_ROOT`, not from the working directory
  (without `APP_ROOT`, the default `storage/cache` is in the working directory, not the system temp
  directory): make its new location writable by PHP.
- **Action.** `example/public/index.php` no longer calls `session_start()`, so readers get no session
  cookie. Remove the line from your copy; send `Cache-Control` yourself if you relied on the
  session's no-cache headers.
- **Action.** Alerts are switched on by `app.alerts.channels` alone, and `app.alertloop.enabled` is
  gone; `Alert` reads AlertLoop's settings only from `app.alertloop.*` (it read a top-level
  `alertloop.*`, so alerts configured as documented were off). Add
  `'alerts' => ['channels' => ['alertloop']]` to keep them.
- `Alert::send()` returns `true` only when a channel that reaches a person took the alert.
- **Action.** Weft sets no permissions on the error log, cache entries and rate-limit counters (it
  created their directories at most `0770`): they get the process umask. Set up `storage/` with one
  group, setgid directories and `umask 002`, and run Weft scripts as the deploy user (upgrade guide).
- **Action.** The Redis key prefix defaults to the host of `app.url.base` (`example.com:`), not
  `weft:`, and `Cache::flush()` and `cache-clear.php` delete only cache entries. The cache starts
  empty; delete the example's `'prefix' => 'weft:docs:'` line. The old keys: upgrade guide.
- **Action.** The cache restores values without their classes (`__PHP_Incomplete_Class`): cache
  arrays and scalars.
- **Action.** `HttpClient` returns `ok => false` for a status of 400 or higher (`error` is
  `HTTP <status>`): where you read an error response's body, branch on `status`.
- **Action.** An alert with a dedupe key is sent at most once per five minutes, whether the first was
  delivered or not: send without a key where every occurrence must arrive.
- **Action.** The example's contact form answers success only when the email to `MAIL_TO` was
  accepted, otherwise 503; its alert carries no form data. A 422 lists `errors: [{name, text}]` (was
  `fields`), and a form posted without JavaScript gets a 303. If you copied the form, copy the files
  the upgrade guide lists.
- **Action.** The example's constants `RECAPTCHA_SITEKEY` and `RECAPTCHA_SECRETKEY` are now
  `CAPTCHA_SITE_KEY` and `CAPTCHA_SECRET_KEY`; with the old names the captcha is off. Rename them in
  your copy, and in the server's when you deploy 0.2.0 (step 5 of the upgrade guide). New constants:
  `MAIL_DRIVER`, `SMTP_*`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `ALERT_MAIL_TO`, `INDEXNOW_KEY`.
- **Action.** The sender moved from `app.contact.mail_from` to `app.mail.from` (still `MAIL_FROM`),
  and `MAIL_TO` takes bare addresses separated by commas: `Name <owner@site>` now fails every contact
  email and `weft-health`.
- **Action.** `Database` sends no alert: `Database::setErrorNotifier()` and `Alert::notifier()` are
  removed, and an uncaught database error alerts as any unhandled exception. Remove the
  `setErrorNotifier()` call from `app/Bootstrap.php`, or every request stops with a fatal error.
- **Action.** `Database::execute()`, `Model::update()` and `Model::delete()` return the number of rows
  changed, not always `true`: compare the count where you compared with `=== true`.
- **Action.** `Database` no longer runs `SELECT 1` before every call: a call on a lost connection
  throws and the next one reconnects, so the first call after an idle longer than `wait_timeout`
  throws; inside `transaction()` every call throws until it returns. Retry the whole transaction.
- Connecting to MySQL gives up after 5 seconds, not 30; the new optional `DB_TIMEOUT` sets it.
- **Action.** `Jwt::decode()` rejects a token without `exp`, and `Jwt::encode()` without `ttl` issues
  one that expires in an hour: tokens issued without `ttl` stop working, issue new ones.
- **Action.** HSTS sends `includeSubDomains` only with the new `app.security.hsts_subdomains`, and the
  new `hsts_max_age` sets `max-age` (default `31536000`). The example ships with `hsts` off.
- **Action.** A relative `og:image` gets `BASE_URI` ahead of it: in a sub-directory, remove it from
  the value (`'/img/og.png'`, not `'/site/img/og.png'`).

### Fixed

- **Action.** The example under PHP's built-in server serves its CSS, JS and images. If you copied
  `public/index.php` and develop with `php -S`, copy the `cli-server` block at its top.
- 65 smaller fixes: 5 security, 30 reliability, 30 docs.

### Removed

- **Action.** Unused API: `Config::isLoaded()`, `Locale::fromAcceptLanguage()`, the `LOCALE`
  constant, `RequestContext::hasRequest()`, `logPath()`, `setLogPath()`, and the manifest of Vite
  before 5. Use `locale()` and `RequestContext::request() !== null`; build with Vite 5 or later.
- **Action.** `RedisStore::client()`: open your own `new \Redis()` with the `REDIS_*` settings.

## 0.1.3 - 2026-08-24

### Fixed

- `scripts/sitemap.php` works when `app.sitemap` is a callable, the documented way to build a
  sitemap from content. Only the kernel resolved the callable, so the script handed the generator
  a list holding a closure and died on it. Both paths now read the routes through
  `Sitemap::routes()`.
- A CLI script that dies on an uncaught throwable now exits with status 1 and writes the error to
  stderr. Registering an exception handler makes PHP terminate with status 0, so a failed cron job,
  CI step or deploy step reported success and, with `display_errors` off, printed nothing.

### Added

- Sitemap routes accept an optional `lastmod`, emitted in schema order (`loc`, `lastmod`,
  `changefreq`, `priority`). Give it only where a real modification date exists: search engines
  ignore `changefreq` and `priority` and do read `lastmod`, but only while they trust it.
- `og:image:alt`, from a page's `image_alt` or the new `app.seo.og_image_alt`. The configured alt
  applies only to the configured image, so a page that sets its own image without an alt gets none
  instead of a caption for a different picture.
- `og:image:width` and `og:image:height`, from `app.seo.og_image_width` / `og_image_height` or a
  per-page override. Emitted only as a pair.

## 0.1.2 - 2026-07-30

### Fixed

- Data passed to `Alert::send()` / `Alert::critical()` as `$context` now reaches AlertLoop: it is
  sent as the event's `payload`, and the trace id travels as the top-level `trace_id` — previously
  both were dropped on arrival.
- A failed alert delivery is no longer silent: transport errors and non-2xx responses are recorded
  on the request trace as an `alert.failed` event (metadata only — never the alert body). Delivery
  stays best-effort and non-blocking.

### Performance

- Vite asset tags now include preload hints in production: `rel="modulepreload"` for the entry and
  for every chunk it statically imports (walked recursively through the manifest), and
  `rel="preload"` for stylesheets. Dynamic imports are deliberately left out.

### Added

- `Alert::send()` accepts a `category`, the AlertLoop field for your own labels — `$type` stays the
  AlertLoop event type (`incident`, `business_event`, `audit`).

## 0.1.1 - 2026-07-22

### Fixed

- The example/documentation site now runs straight after `composer install`, with no Node build
  step: its built assets are committed and it defaults to production mode.
- `composer require golovanov/weft` no longer pulls the example site, tests or CI config into
  `vendor/` — the package archive now ships only the engine (`src/`) and its metadata.

## 0.1.0 - 2026-07-17

Initial release: the complete engine as a Composer library (`golovanov/weft`), with a working
example site that doubles as the documentation.

### Added

- Convention routing: `pages/about.php` → `/about`, directory indexes, `[slug]` dynamic
  segments, `api/*.php` → JSON endpoints under `/api/*`; no route table.
- SEO toolkit: title templates, canonical, Open Graph, Twitter cards, hreflang and JSON-LD
  rendered from config with per-page overrides; generated `/sitemap.xml` and `/robots.txt`.
- Optional i18n (off by default): prefix-free default locale, prefixed URLs for others,
  automatic hreflang and canonical redirects, translation arrays in `lang/<locale>/`.
- Optional caching with file and Redis stores; `Cache::remember()` stays a safe no-op when
  caching is disabled.
- Request tracing: one JSONL timeline per request via golovanov/traceloom, with automatic
  redaction of sensitive keys and an `X-Trace-Id` response header.
- Form protection: rate limiting, CSRF tokens, honeypot check and optional Google
  reCAPTCHA v3 (safe pass when disabled).
- Best-effort alerting to AlertLoop that never blocks a request.
- Built-in utilities: curl-based HTTP client, thin PDO wrapper with a minimal model, JWT
  (firebase/php-jwt), security headers, string/array/JSON helpers.
- Vite integration: dev server with HMR in development, hashed production assets resolved
  from the manifest.
- Two-layer configuration: gitignored environment constants plus committed business config
  read via `config('dot.keys')`.
- `example/` — a complete working site that is also the engine's documentation
  (`example/content/en/`).
