# Changelog

All notable changes to Weft are documented in this file. Weft follows semantic versioning;
pre-1.0 the API may change between minor versions.

## 0.2.0 - Unreleased

### Security

- Request and outgoing `HttpClient` bodies in the trace are now parsed into fields, so traceloom
  masks them by key; before, a body was one raw string that masking could not reach. A body that
  decodes as a JSON object or array is parsed whatever its content type (`+json` types, no type,
  JSON sent by `curl -d`); so is any urlencoded form (for `HttpClient`, a body passed as an array),
  and multipart fields (never uploaded files);
  any other body is still logged as the raw string. Weft masks nothing itself: an email or a phone number
  stays in the trace unless you list its key in `app.trace.sensitive_keys`. To keep bodies out of
  the trace, set `app.trace.log_body` to `false` (default `true`): a body is then logged as its size
  and content type only. This does not touch fields your code writes with `Trace::event()`; mask
  those with `sensitive_keys`.
- The query string is parsed into a `query` field and masked by key the same way, whatever
  `log_body` says. The `referer` header is still logged as sent, query string included.
- `Model` rejects a column name that is not a plain identifier (`[A-Za-z_][A-Za-z0-9_]*`) with
  `InvalidArgumentException` before any query runs, in `create()`, `update()`, `findBy()`, `where()`
  and `exists()`. Array keys went into the SQL as-is, so `create($request->body())` was an SQL
  injection. The check is not an allow-list: any valid column name in the array is still written,
  so keep passing `create()` and `update()` only the fields you expect. If you pass quoted or
  qualified names (`` `order` ``, `posts.id`), write that query with `Database` instead.
- An enabled captcha with the site key or the secret key missing no longer passes every
  submission. `Captcha::verify()` records a `captcha.misconfigured` trace event and a line in the
  error log; outside `DEBUG` it also returns `ok => false` with the error code `misconfigured` and
  sends a critical alert (category `security`, dedupe key `captcha:misconfigured`), with `DEBUG` on
  it still passes. Set both keys wherever `app.captcha.enabled` is true, or set it to false.
- `RateLimiter` now limits clients that send no session cookie. `allow()` and `throttle()` no longer
  use the PHP session: they count in Redis when `app.cache.driver` is `redis`, otherwise in files
  under the new `app.rate_limit.path` (default `app.cache.path`), whether caching is enabled or
  not, and no longer use a store set with `Cache::use()`. Each increment is atomic, and a window
  starts at its first hit instead of being extended by every hit. Make that directory writable
  by the web server: if a counter cannot be written, the request is allowed and an error is logged.
  Counters are per client IP, no longer per session: behind a reverse proxy or CDN, list its
  addresses in `PROXY_IPS` (exact addresses, no ranges), or all visitors share one counter.

### Added

- `app.trace.log_body` (default `true`) and `app.trace.sensitive_keys` — extra keys to mask in
  traces, on top of traceloom's defaults (`password`, `token`, `api_key`, …). Each must match a
  whole key name: `phone` does not mask `customer_phone`.

### Changed

- Trace format, for anything that parses traces: `body` in `request_start` and `http_out_request`
  is now an object `{size, content_type, data}` instead of a string, or `null` when the request has
  no body (`data` is an array, or the raw string for a body that is not JSON or a form, and is
  absent with `log_body` off); `uri` in
  `request_start` and `url` in `http_out_request` / `http_out_response` no longer include the query
  string, which moved to the `query` field (absent when there is none).
- Trace files are deleted after 30 days. Set `app.trace.retention_days` to keep them longer, or to
  `0` to keep them forever. The first request after the upgrade already deletes older files: if you
  need that history, set the key before upgrading.
- `example/public/index.php` no longer calls `session_start()`; the session starts only when a form
  needs a CSRF token (`Csrf`), so visitors who only read pages get no `PHPSESSID` cookie. Those
  pages also lose the no-cache headers PHP sent with the session; if your site relied on them, send
  `Cache-Control` yourself. If your site copied this file, remove the `session_start()` line too,
  with the same effect on headers.

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
