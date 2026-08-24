---
title: Reference
description: A cheat sheet of the global helpers and the engine classes.
order: 5
---

# Reference

The short version of what you call most. Full signatures live in `src/` — the classes are small and
commented.

## Global helpers

Defined in `src/helpers.php`, available everywhere without a `use`.

| Helper | Does | Example |
|---|---|---|
| `e($value)` | escape for HTML | `<?= e($name) ?>` |
| `t($key, $vars = [])` | translate a dot key | `t('nav.docs')` |
| `url($path, $locale = null)` | link with BASE_URI and locale | `url('/about')` |
| `asset($path)` | path to a file under the web root | `asset('img/logo.svg')` |
| `api_url($path)` | endpoint link (never localised) | `api_url('contact')` |
| `config($key, $default = null)` | business config value | `config('app.seo.site_name')` |
| `locale()` | current locale | `<html lang="<?= e(locale()) ?>">` |
| `seo($attrs = [])` | render the SEO head block | `<?= seo() ?>` |
| `csrf_field()` | hidden CSRF input | `<?= csrf_field() ?>` |
| `is_active($route, $class = 'active')` | class if the route is current | `class="<?= is_active('docs') ?>"` |

## Engine classes

All under `Golovanov\Weft\`.

### Config and support

```php
Config::get($key, $default);  Config::env($const, $default);  Config::has($key);  Config::set($key, $v);
Clock::now();  Clock::date('Y-m-d');  Clock::timestamp();  Clock::numericOffset();
Sanitize::input($s);  Sanitize::inputArray($arr);  Sanitize::output($v);  Sanitize::path($p);
Arr::get($arr, 'a.b.c', $def);  Str::slug($s);  Str::limit($s, 160);  Json::decode($s);  Random::uuid();
```

### HTTP

```php
$request->method;  $request->path;  $request->clientIp;  $request->post;  $request->rawBody;
$request->isPost();  $request->wantsJson();  $request->header('authorization');  $request->body();

Response::html($html, 200);  Response::json($data, 200);  Response::text($t);  Response::redirect($url, 302);
$response->withHeader($n, $v);  $response->send();

RequestContext::request();  RequestContext::traceId();  RequestContext::elapsed();

$http = new HttpClient();  $http->get($url, $query, $opts);  $http->post($url, $body, $opts);
```

### I18n and URLs

```php
Locale::enabled();  Locale::supported();  Locale::default();  Locale::current();  Locale::name('ru');
I18n::t($key, $vars);  I18n::has($key);  I18n::raw($key, $def);
Url::link($path, $locale);  Url::asset($path);  Url::api($path);  Url::absolute($path, $locale);
Url::inLocale('ru', $path);  Url::currentPath();  Url::isActive('about');
```

### SEO

```php
Seo::set(['title' => …, 'description' => …, 'image' => …, 'type' => …, 'canonical' => …]);
Seo::title($t);  Seo::description($d);  Seo::noindex();  Seo::jsonLd($schema);  Seo::render();
Sitemap::xml($routes);  Robots::text();
```

### Cache

```php
Cache::remember($key, $ttl, $callback);  Cache::get($key, $def);  Cache::set($key, $val, $ttl);
Cache::has($key);  Cache::forget($key);  Cache::flush();
```

### Database

```php
$db = Database::connect();
$db->row($sql, $params);  $db->all($sql, $params);  $db->value($sql, $params);  $db->execute($sql, $params);
$db->lastInsertId();  $db->transaction($fn);
Database::setErrorNotifier($callable);

// Model
$m->find($id);  $m->findBy($cond);  $m->where($cond);  $m->exists($cond);
$m->create($data);  $m->update($id, $data);  $m->delete($id);
```

### Security

```php
Captcha::enabled();  Captcha::siteKey();  Captcha::verify($token, $action);
RateLimiter::allow($bucket, $max, $window);  RateLimiter::throttle($bucket, $sec);
RateLimiter::honeypot($body, $field);
Csrf::field();  Csrf::token();  Csrf::check($token);  Csrf::fieldName();
Jwt::decode($token);  Jwt::encode($claims, ttl: 3600);  Jwt::fromBearer($header);
Headers::send();
```

### Observability

```php
Trace::event($name, $data);  Trace::id();  Trace::isActive();
Alert::send($msg, $severity, $type, $dedupeKey, $context, $category);  Alert::critical($msg, $ctx);  Alert::notifier();
ErrorHandler::log($type, $code, $msg, $file, $line);  ErrorHandler::debug();
```

### Assets and views

```php
Vite::tags('src/main.js');  Vite::devMode();
View::render($file, $data);  View::page($pageFile, $layoutFile, $data);  View::share($key, $value);
```

## Template variables

Pages receive `$request`, `$route`, `$locale`, and on dynamic routes the params (both as `$slug` and
`$params['slug']`). The layout additionally receives `$content`.

## CLI

```bash
php scripts/health.php       # environment diagnostics (JSON, exit code 0/1)
php scripts/sitemap.php      # write a static public/sitemap.xml
php scripts/cache-clear.php  # flush the cache store
```

Your own script: `require dirname(__DIR__) . '/bootstrap.php';` and the whole engine is initialised.

Any script that boots the engine reports a crash honestly: an uncaught throwable is logged, written
to stderr and ends the process with status 1, so a failed step in cron or CI is not mistaken for a
successful one.

Next: [Deployment](/docs/deployment).
