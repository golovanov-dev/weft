# Changelog

All notable changes to Weft are documented in this file. Weft follows semantic versioning;
pre-1.0 the API may change between minor versions.

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
