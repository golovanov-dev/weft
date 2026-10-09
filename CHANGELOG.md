# Changelog

All notable changes to Weft are documented in this file. Weft follows semantic versioning;
pre-1.0 the API may change between minor versions.

## 0.2.0 - Unreleased

### Security

- Request and outgoing `HttpClient` bodies and query strings go into the trace parsed into fields,
  so traceloom masks them by key; before, a body was one raw string masking could not reach.
  Parsed: a body that decodes as JSON (whatever its content type), an urlencoded form, multipart
  fields (never uploaded files), and always the query string, into `query`. Any other body stays a
  raw string; the `referer` header is logged as sent. Weft masks nothing itself: list keys such as
  `email` or `phone` in the new `app.trace.sensitive_keys` (a whole key name: `phone` does not mask
  `customer_phone`), or set the new `app.trace.log_body` to `false` (default `true`) to log a body
  as its size and content type only. Fields you write with `Trace::event()` are masked only by
  `sensitive_keys`.
- `Model` rejects a column name that is not a plain identifier (`[A-Za-z_][A-Za-z0-9_]*`) with
  `InvalidArgumentException` in `create()`, `update()`, `findBy()`, `where()` and `exists()`: array
  keys went into the SQL as-is, so `create($request->body())` was an SQL injection. It is not an
  allow-list: keep passing `create()` and `update()` only the fields you expect, and write queries
  with quoted or qualified names (`` `order` ``, `posts.id`) with `Database`.
- An enabled captcha without its site key or secret key no longer passes every submission: outside
  `DEBUG`, `Captcha::verify()` returns `ok => false` with the error code `misconfigured` and sends a
  critical alert (dedupe key `captcha:misconfigured`); with `DEBUG` on it passes and logs the
  problem. Set both keys wherever `app.captcha.enabled` is true, or set it to false.
- `RateLimiter` limits clients that send no session cookie. `allow()` and `throttle()` count per
  client IP, atomically, in Redis when `app.cache.driver` is `redis`, otherwise in files under the
  new `app.rate_limit.path` (default `app.cache.path`; relative to `APP_ROOT`), whether caching is
  on or not; no longer in the session or a `Cache::use()` store. A window starts at its first hit.
  Make that directory writable by PHP (a counter that cannot be written allows the request and
  logs an error), and behind a reverse proxy or CDN list it in `PROXY_IPS`, or all visitors share
  one counter.
- Behind a proxy in `PROXY_IPS`, the client IP is the rightmost `X-Forwarded-For` entry not in
  `PROXY_IPS`; `X-Client-Ip` is no longer read. Both it and the leftmost entry, used before, are set
  by the client, so a forged header got around rate limits. If your proxy passes the address only
  in `X-Client-Ip` or `X-Real-IP`, make it append `X-Forwarded-For`, or every visitor shares the
  proxy's limit. On shared hosting and with nginx + php-fpm on one machine `PROXY_IPS` usually
  stays empty. To check, compare `client_ip` in the newest `request_start` trace with your address.

### Added

- `scripts/health.php` in the example exits with 1 (`ok: false`) when logs or rate-limit counters
  cannot be written (`errors/` and the site's `requests/` under `LOGS_PATH`, though the script runs
  from the console, with today's files and traceloom's lock file; the counter directory or a file
  in it; with Redis, a probe write), when counters went to files while `app.cache.driver` is
  `redis`, when `PROXY_IPS` is not an array or has an entry that is invalid or too wide for a proxy
  (`/0`, IPv4 wider than `/8`, IPv6 wider than `/16`), and outside `DEBUG` when a captcha or alerts
  are enabled without their keys. New fields: `cache_driver`, `rate_limit_store`,
  `rate_limit_path`, `rate_limit_writable`, `rate_limit_error`, `proxy_ips_error`,
  `proxy_ips_invalid`, `proxy_ips_trust_all`, `captcha_missing_keys`, `alertloop_missing_keys`,
  `logs_error`. If your site copied the script, update the engine to 0.2.0 first, then copy the
  script again: on an older engine it stops with a fatal error. Engine side:
  `Captcha::missingKeys()`, `Alert::missingKeys()`, `ErrorHandler::logsError()`,
  `RateLimiter::storeStatus()`, `Request::invalidProxyIps()`, `Request::trustAllProxyIps()`,
  `Request::proxyIpsError()`.
- `PROXY_IPS` accepts IPv4 and IPv6 CIDR ranges (`'173.245.48.0/20'`, `'2400:cb00::/32'`) next to
  addresses, so a CDN such as Cloudflare can be listed by its published ranges. Host names
  (`localhost`, a Docker service name) are ignored: write the address.

### Changed

- Weft requires `golovanov/traceloom` `^0.5`. If your site's `composer.json` requires traceloom
  itself, raise that constraint to `^0.5` too, or Composer cannot install Weft 0.2.0.
- Each page has one address: a GET or HEAD request for `/about/` gets a 301 to `/about`, `/home`
  (the `app.default_route` page) to `/`, `/ru/home` and `/ru/` to `/ru`, the query string kept;
  other methods are served as before. Point your own links at the final address.
  `Url::link('/', 'ru')`, hreflang and sitemap entries for a locale root are `/ru`, not `/ru/`: if
  you write a static `public/sitemap.xml` with `php scripts/sitemap.php`, run it again. `/404` and
  `/<dir>/index` are a 404, not a second address of the 404 page or of `/<dir>`.
- A path Weft used to clean up silently is a 404 (JSON under `/api`), not the page its clean form
  names with 200: `/about!!`, non-ASCII or percent-encoded characters, empty, `.` or `..` segments,
  a leading `//`. Pages are reachable only by paths of `A-Z a-z 0-9 / _ - .`; fix links that use
  anything else.
- Canonical, `og:url` and hreflang are built from the matched route, never from the request URI; a
  404 gets none of them, including a page that answers `http_response_code(404)` itself.
  `Seo::set(['canonical' => …])` still wins. The matched path is `Url::routePath()` (`null` on a
  404); on a 404 `Url::currentPath()` is `/` and `Url::isActive()` is false.
- Trace format, for anything that parses traces: `body` in `request_start` and `http_out_request`
  is an object `{size, content_type, data}` (`data` an array, or the raw string for a body that is
  not JSON or a form, absent with `log_body` off), or `null` without a body, instead of a string;
  `uri` in `request_start` and `url` in `http_out_request` / `http_out_response` no longer include
  the query string, which moved to `query` (absent when there is none).
- Trace files are deleted after 30 days, starting with the first request after the upgrade. To keep
  that history, set `app.trace.retention_days` before upgrading (`0` keeps files forever).
- A relative `app.cache.path` is taken from `APP_ROOT`, not from the working directory (under
  PHP-FPM, `public/`), so the site and CLI scripts share one cache; without `APP_ROOT` the default
  is `storage/cache` in the working directory, not in the system temp directory. If your path is
  relative, make its new location writable by PHP; the old directory can be deleted.
- `example/public/index.php` no longer calls `session_start()`: the session starts only when a form
  needs a CSRF token (`Csrf`), so readers get no `PHPSESSID` cookie and no no-cache headers PHP
  sent with the session. If your site copied this file, remove the line too; if you relied on those
  headers, send `Cache-Control` yourself.
- `Alert` reads its settings only from `app.alertloop.*`, the `'alertloop'` block of
  `app/Configs/app.php`, as documented; it read a top-level `alertloop.*`, so alerts configured as
  documented were silently off. If you have `app/Configs/alertloop.php`, move its array into
  `app.php` under `'alertloop'` and delete the file; if your settings are already in `app.php`,
  alerts start being sent after the upgrade. Alerts enabled without `base_url` or `api_key` still
  send nothing, and now say so in the error log, once per request.
- Console scripts (`cli` and `phpdbg` SAPIs: `scripts/*.php`, cron jobs) write traces to
  `LOGS_PATH/cli/`, not `LOGS_PATH/requests/`, which is now the site's alone (PHP's built-in server
  counts as the site); `app.trace.retention_days` applies to both. To read a script's trace, use
  `vendor/bin/eventtrace show <trace_id> --dir=storage/logs/cli`.
- Weft sets no permissions on the error log, cache entries, rate-limit counters and their
  directories (it created the directories at most `0770`): they get the mode the process umask
  gives, so with umask `022` error logs are readable by other users unless `storage/` is closed to
  them. Traces keep traceloom's modes (`0640`, directory `0750`). When upgrading, apply steps 5 and
  6 of the deployment guide (one group for PHP and every other writer, setgid directories,
  `umask 002`; step 5 also hands files your scripts left in `storage/logs/requests/` to PHP's
  group), add `umask 002;` to your cron lines and to commands you run over ssh or from CI, move cron
  jobs that run Weft scripts from the crontab of `www-data` or `root` to the deploy user's, exclude
  `storage/` and `config/config.php` if you deploy with rsync, and run `health.php` as the PHP user.

### Fixed

- An exception thrown by a page or an API endpoint raises the same critical alert as any other
  unhandled exception (same message and dedupe key); before, it was only logged. The alert is sent
  after the 500 response, and the half-rendered output is discarded.
- A Redis that rejects the connection (a wrong, missing or unexpected `REDIS_PASSWORD`, `REDIS_DB`
  out of range, which used to select database 0) or rejects commands (a read-only replica,
  `maxmemory` under `noeviction`) no longer makes cached pages fail with 500. On a rejected
  connection the cache and rate limits fall back to files, as with an unreachable Redis; a rejected
  read is a miss, a write is skipped, and the error log gets one `Cache store failed` line per
  request. If `health.php` reports `rate_limit_store: file`, `rate_limit_error` says why.
- The example under PHP's built-in server (`php -S … -t public public/index.php`) serves its CSS, JS
  and images; before, every asset request returned 404. If your site copied
  `example/public/index.php` and you develop with `php -S`, copy the new `cli-server` block at its
  top.
- 10 smaller fixes: 1 reliability, 9 docs.

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
