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
  as its size and content type only. A body over 8 KB is always logged as its size and content
  type only. Fields you write with `Trace::event()` are masked only by `sensitive_keys`.
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
  client IP (an IPv6 client by its `/64` network), atomically, in Redis when `app.cache.driver` is
  `redis`, otherwise in files under the new `app.rate_limit.path` (default `ratelimit/` in
  `app.cache.path`; relative to `APP_ROOT`), whether caching is on or not; no longer in the session
  or a `Cache::use()` store, and `cache-clear.php` no longer resets them. A window starts at its
  first hit. Counter files 0.1.x left in the cache directory go with `php scripts/cache-clear.php`.
  Make that directory writable by PHP (a counter that cannot be written allows the request and
  logs an error), and behind a reverse proxy or CDN list it in `PROXY_IPS`, or all visitors share
  one counter.
- Behind a proxy in `PROXY_IPS`, the client IP is the rightmost `X-Forwarded-For` entry not in
  `PROXY_IPS`; `X-Client-Ip` is no longer read. Both it and the leftmost entry, used before, are set
  by the client, so a forged header got around rate limits. If your proxy passes the address only
  in `X-Client-Ip` or `X-Real-IP`, make it append `X-Forwarded-For`, or every visitor shares the
  proxy's limit. On shared hosting and with nginx + php-fpm on one machine `PROXY_IPS` usually
  stays empty. To check, compare `client_ip` in the newest `request_start` trace with your address.
- The example's config template sent contact requests to the Weft author: `MAIL_TO` and `MAIL_FROM`
  were `hello@golovanov.dev` and `noreply@golovanov.dev`, now `hello@example.com` and
  `noreply@example.com`; step 3 of the deployment guide names them. If your `config/config.php`
  came from the template, set both to addresses of your own.

### Added

- `Mail::send($to, $subject, $body, $replyTo)` sends a plain-text UTF-8 email through PHP `mail()`
  or an SMTP server (`app.mail.driver` `mail` or `smtp`; `app.mail.from`, `from_name`, and
  `app.mail.smtp.host`, `port`, `encryption` — `starttls`, `ssl` or `none` — `username`, `password`,
  `timeout`), with no extra package. It returns `false` and writes `Mail not sent` to `errors/`
  instead of throwing, refuses an address with a line break, and traces a `mail_out` event without
  the subject, body or addresses.
- Alert channels: `app.alerts.channels` lists any of `alertloop`, `telegram` (a bot message,
  `app.alerts.telegram.bot_token` and `chat_id`), `mail` (an email to `app.alerts.mail_to` through
  `Mail`) and `log` (a line in `errors/`), tried in order. `alert.failed` trace events name the
  `channel`. `Alert::channels()`, `Alert::deliveryChannels()` and `Alert::configErrors()` tell what is
  set up.
- Yandex SmartCaptcha: `app.captcha.driver` `smartcaptcha` verifies its tokens with the same keys and
  the same diagnostics as reCAPTCHA (`recaptcha`, the default); only the secret and the token are
  sent to Yandex, and the host is checked against `allowed_hosts`. The page loads Yandex's widget
  itself: in the example that means changing three files, listed in the features guide.
- `vendor/bin/weft-health`, run in the site root, prints the health report of the site it boots
  (through the site's `bootstrap.php`) and exits with 1 (`ok: false`) when logs or rate-limit
  counters cannot be written (`errors/` and the site's `requests/` under `LOGS_PATH`, though the
  command runs from the console, with today's files and traceloom's lock file; the counter directory
  or a file in it; with Redis, a probe write), when counters went to files while `app.cache.driver`
  is `redis`, when `PROXY_IPS` is not an array or has an entry that is invalid or too wide for a
  proxy (`/0`, IPv4 wider than `/8`, IPv6 wider than `/16`), outside `DEBUG` when the captcha, a
  listed alert channel or `app.mail` lacks a setting, when `production` names a development setting (`DEBUG`,
  `DEV_MODE`, `config/config.example.php` loaded, `app.url.base` or, with the captcha on,
  `app.captcha.allowed_hosts` on example.com, no built `app.vite.entry` in the Vite manifest) unless
  run with `--dev`, and when a check of the site fails. New fields: `config` (the environment config
  loaded), `production`, `checks`, `cache_driver`, `rate_limit_store`, `rate_limit_path`,
  `rate_limit_writable`, `rate_limit_error`, `proxy_ips_error`, `proxy_ips_invalid`,
  `proxy_ips_trust_all`, `captcha_missing_keys`, `alert_channels`, `alert_errors`, `mail_driver`,
  `mail_errors`, `logs_error`. Engine side: `Captcha::missingKeys()`, `Alert::configErrors()`, `ErrorHandler::logsError()`,
  `RateLimiter::storeStatus()`, `Request::invalidProxyIps()`, `Request::trustAllProxyIps()`,
  `Request::proxyIpsError()`, `Vite::manifestError()`.
- `Health::check($name, $check)` adds a check of the site to `weft-health`: register it in
  `app/Bootstrap.php`; the callable returns the fields to show and `ok`, reported under
  `checks.<name>`, and anything but `ok => true`, or an exception, fails the report. The example
  checks that its contact form has a channel and shows where requests go
  (`checks.contact_form.mail_to`).
- `Request::isHttps()`: a request from a proxy in `PROXY_IPS` came over HTTPS when the last value of
  its `X-Forwarded-Proto` is `https`; from any other address, or without the header, when
  `$_SERVER['HTTPS']` is set and not `off`. `example/public/index.php` uses it for the `Secure` flag
  of the session cookie, so a proxy listed in `PROXY_IPS` needs no `HTTPS=on`. If your site copied
  that file, take its session cookie block, which now comes after `require bootstrap.php`.
- `PROXY_IPS` accepts IPv4 and IPv6 CIDR ranges (`'173.245.48.0/20'`, `'2400:cb00::/32'`) next to
  addresses, so a CDN such as Cloudflare can be listed by its published ranges. Host names
  (`localhost`, a Docker service name) are ignored: write the address.
- Catch-all pages: `pages/<dir>/[...path].php` serves every deeper URL under `<dir>` with
  `$path = 'a/b/c'`, after the exact file, `index.php` and `[param].php`; the nearest one wins, and
  `pages/[...path].php` catches any page URL. A page template can end with
  `throw Abort::notFound()` (the site's 404 page, without canonical) or `Abort::redirect($url)` (301).
- `Pagination::fromRequest($request, $total, $perPage)` gives page numbers, offset and links for
  `?page=N`: `?page=1` is a 301 to the address without it, a page that does not exist is a 404, and
  page 2 and later get their own canonical and a title suffix (`app.seo.page_suffix`, default
  ` — Page %d`).
- JSON-LD builders `Schema::organization()`, `localBusiness()`, `webSite()`, `article()`,
  `product()`, `faq()` and `breadcrumbs()` check the fields search engines require; a missing one
  throws in `DEBUG` and otherwise leaves the block out with a line in `errors/`.
- robots.txt groups per crawler: `app.seo.robots_groups` lists `agents` (user-agent tokens or the
  presets `ai-search`, `ai-user`, `ai-training`) with their `allow` and `disallow` paths. Without
  it robots.txt is unchanged.
- `/llms.txt` in the llmstxt.org format from `app.llms`, an array or a callable returning one;
  without the key it stays a 404. `weft-health` fails (`seo_errors`) on an `ai-…` agent name in
  `robots_groups` that is not a preset, and on a `public/robots.txt` or `public/llms.txt` the web
  server serves instead of the configured one: delete the file.
- IndexNow: with `app.indexnow.key` set, the key is served at `/<key>.txt`, and
  `vendor/bin/weft-indexnow` (for cron) submits the sitemap addresses that are new or have a new
  `lastmod`, remembering only what the endpoint accepted; `--dry-run` lists them. `weft-health`
  fails on a malformed key (`indexnow_error`).
- `vendor/bin/weft-seo-audit <site-url>` checks the pages in a live site's sitemap over HTTP —
  status 200, title, meta description, canonical, one `h1`, JSON-LD that parses, no `noindex` —
  and exits with 1 when one fails, for CI. It needs `ext-dom`, now in `suggest`.

### Changed

- The health check moved from the example's `scripts/health.php` into the engine:
  `vendor/bin/weft-health`, run in the site root. Delete your copy of `scripts/health.php` and call
  `vendor/bin/weft-health` from deploy scripts, CI and cron (`--dev` on a development machine). The
  report no longer has the `traceloom` field, which was always `true`, nor `alertloop_enabled`: if you
  parse the output, stop reading them (alerts are in `alert_channels`). `ok` is now false on a server with development settings: fix what `production` names.
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
- Alerts are switched on by the new `app.alerts.channels` list alone; `app.alertloop.enabled` is
  gone, and without `channels` no alert is sent. To keep AlertLoop alerts, add
  `'alerts' => ['channels' => ['alertloop']]` to `app/Configs/app.php` and delete `'enabled'` from
  its `'alertloop'` block. `Alert` reads AlertLoop's settings only from that block (`app.alertloop.*`),
  as documented; it read a top-level `alertloop.*`, so alerts configured as documented were silently
  off: if you have `app/Configs/alertloop.php`, move its array into `app.php` under `'alertloop'` and
  delete the file. A listed channel without its settings sends nothing and says so in the error log,
  once per request. `Alert::send()` returns `true` only when a channel that reaches a person took the
  alert.
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
  `storage/` and `config/config.php` if you deploy with rsync, and run `vendor/bin/weft-health` as
  the PHP user.
- The Redis key prefix defaults to the host of `app.url.base` and a colon (`example.com:`), not
  `weft:`, and cache entries are kept under `<prefix>cache:`: `Cache::flush()` and `cache-clear.php`
  delete only those, not rate-limit counters or another site's keys. After the upgrade the Redis
  cache starts empty, with your own `app.cache.prefix` too. If your `app/Configs/app.php` came from
  the example, delete its `'prefix' => 'weft:docs:'` line. Old keys with a TTL expire on their own.
  To delete the old `weft:` keys at once — only if no other Weft site uses this Redis database,
  since `weft:` was every site's default — run once after the upgrade
  `redis-cli -h <REDIS_HOST> -p <REDIS_PORT> -n <REDIS_DB> --scan --pattern 'weft:*' | xargs -r -d '\n' redis-cli -h <REDIS_HOST> -p <REDIS_PORT> -n <REDIS_DB> unlink`
  (with a password, add `-a '<REDIS_PASSWORD>'` to both).
- The cache restores values without their classes: an object comes back as
  `__PHP_Incomplete_Class`. Cache arrays and scalars; convert objects before `Cache::set()` or
  `remember()`.
- `HttpClient` returns `ok => false` for a response with status 400 or higher (`error` is
  `HTTP <status>`); `status` and the decoded `body` are still returned. Before, any response that
  arrived was `ok`, so a 503 `{"error":"maintenance"}` counted as success. Where your code reads an
  error response's body, branch on `status`, not on `ok`.
- An alert with a dedupe key is sent at most once per five minutes: a repeat within that window is
  not sent and `Alert::send()` returns `false`; a failed delivery does not open the window. The
  window is kept with the rate-limit counters, and if that store fails the alert is sent. Where
  every occurrence must arrive, send the alert without a dedupe key.
- The example's contact form answers success only when the request was delivered: the email to
  `MAIL_TO` was accepted or the alert reached a person. Otherwise it answers 503 with a
  message for the visitor and writes `Contact request not delivered` to `errors/`; a failed
  `mail()` writes a line too. A 422 lists the fields as `errors: [{name, text}]` (was `fields`),
  the rate limit counts after validation, and a form posted without JavaScript is redirected (303)
  to the contact page with `?status=`. If your site copied the form, copy `api/contact.php`,
  `app/Services/ContactService.php`, `pages/contact.php`, `frontend/src/contact.js` and the
  `status` strings of `lang/en/contact.php`, and set `MAIL_TO` or an alert channel, or every
  submission answers 503.
- The example's environment constants: `RECAPTCHA_SITEKEY` and `RECAPTCHA_SECRETKEY` are now
  `CAPTCHA_SITE_KEY` and `CAPTCHA_SECRET_KEY`; new are `MAIL_DRIVER` and `SMTP_*` (email) and
  `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID`, `ALERT_MAIL_TO` (alert channels); the sender moved from
  `app.contact.mail_from` to `app.mail.from` (still `MAIL_FROM`). If your `config/config.php` came
  from the template, rename the two captcha constants and add the new ones from
  `config/config.example.php`; with the old names and the new `app.php` the captcha is off. If your
  site copied `app.php`, take its `'captcha'`, `'mail'`, `'alerts'` and `'contact'` blocks.
  `MAIL_TO` takes plain addresses, several separated by commas; `Name <owner@site>`, which `mail()`
  accepted, now fails every contact email (and `weft-health`, when no alert channel reaches a
  person): write the bare address.
- `Database` sends no alert, and `Database::setErrorNotifier()` and `Alert::notifier()` are removed:
  a database error nobody catches alerts as any unhandled exception does, once per five minutes, with
  the MySQL message only in `errors/`. Remove
  `Database::setErrorNotifier(Alert::notifier())` from your `app/Bootstrap.php`, or every request
  stops with a fatal error.
- `Database::execute()`, `Model::update()` and `Model::delete()` return the number of rows changed
  (MySQL does not count a row an `UPDATE` leaves as it was), not always `true`. Where you compare
  their result with `=== true`, compare the count instead.
- `Database` no longer runs `SELECT 1` before every call. A call on a lost connection (MySQL 2006
  or 2013) throws, and the next call opens a new connection; the statement is not repeated. Inside
  `transaction()` a lost connection throws
  `RuntimeException('Database connection lost in a transaction')` on every call until
  `transaction()` returns; before, Weft reconnected and the rest of the transaction was written
  without it. If a rollback fails, the original exception is still the one thrown. Retry the whole
  transaction where that is safe.
- Connecting to MySQL gives up after 5 seconds, not pdo_mysql's 30; the new optional `DB_TIMEOUT`
  constant sets it. A query still has no read timeout but `mysqlnd.net_read_timeout` (the
  database section of the features guide).
- `Jwt::decode()` rejects a token without `exp`, and `Jwt::encode()` without `ttl` issues a token
  that expires in an hour instead of one that never does. Tokens issued by `encode()` without `ttl`
  stop working after the upgrade: issue new ones, and pass `ttl` where an hour is too short.
- HSTS no longer sends `includeSubDomains`, and its lifetime is configurable: `app.security.hsts`
  stays the switch, the new `app.security.hsts_max_age` (default `31536000`) sets `max-age`, and the
  new `app.security.hsts_subdomains` (default `false`) adds `includeSubDomains`. If every subdomain of
  your domain serves HTTPS and you want them covered, set `hsts_subdomains` to `true`. The example's
  `app.php` now ships with `hsts` off: read "HTTPS and HSTS" in the deployment guide before turning
  it on.
- A relative `og:image` (`app.seo.og_image`, or an image a page sets with `Seo::set()`) gets
  `BASE_URI` ahead of it, as links do. If your site lives in a sub-directory and the value includes
  it (`'/site/img/og.png'`), remove it (`'/img/og.png'`), or the address doubles it
  (`https://example.com/site/site/img/og.png`) and social previews lose their picture. A full URL
  is used as it is.

### Fixed

- An exception thrown by a page or an API endpoint raises the same critical alert as any other
  unhandled exception (same message and dedupe key); before, it was only logged. The alert is sent
  after the 500 response, and the half-rendered output is discarded.
- A Redis that rejects the connection (a wrong, missing or unexpected `REDIS_PASSWORD`, `REDIS_DB`
  out of range, which used to select database 0) or rejects commands (a read-only replica,
  `maxmemory` under `noeviction`) no longer makes cached pages fail with 500. On a rejected
  connection the cache and rate limits fall back to files, as with an unreachable Redis; a rejected
  read is a miss, a write is skipped, and the error log gets one `Cache store failed` line per
  request. If `weft-health` reports `rate_limit_store: file`, `rate_limit_error` says why.
- The example under PHP's built-in server (`php -S … -t public public/index.php`) serves its CSS, JS
  and images; before, every asset request returned 404. If your site copied
  `example/public/index.php` and you develop with `php -S`, copy the new `cli-server` block at its
  top.
- 26 smaller fixes: 3 security, 12 reliability, 11 docs.

### Removed

- Unused API: `Config::isLoaded()`, `Locale::fromAcceptLanguage()`, the `LOCALE` constant,
  `RequestContext::hasRequest()`, `logPath()` and `setLogPath()`, and reading the manifest from
  `public/dist/manifest.json`, where Vite before 5 wrote it. Use `locale()` instead of `LOCALE` and
  `RequestContext::request() !== null` instead of `hasRequest()`; build assets with Vite 5 or later.

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
