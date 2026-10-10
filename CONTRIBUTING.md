# Contributing to Weft

Thanks for taking a look. Weft is small on purpose — the bar for adding to the engine is that a
feature is needed by *most* content sites. Anything site-specific belongs in the site's
`app/Services`, not here.

## Repository layout

```text
weft/
├── composer.json     the golovanov/weft package
├── src/              engine source (Golovanov\Weft\)
├── tests/            PHPUnit suite (composer test)
└── example/          a working site that also serves as the documentation
```

## Local development

The example site normally installs the engine from Packagist, exactly like a real consumer. To
develop the engine and see your changes in the example immediately, point the example at your
local checkout:

```bash
cd example
composer config repositories.weft path ../
composer require golovanov/weft:@dev
```

This writes a `repositories` block into `example/composer.json` — **do not commit it**. Revert
with `git checkout example/composer.json` before opening a pull request.

> Composer symlinks the repository root into `example/vendor/golovanov/weft`, which nests the
> example inside itself. That is harmless (vendor is gitignored) but can confuse tools that walk
> the tree — another reason the override stays local.

## Running the example

```bash
cd example
composer install
php -S localhost:8000 -t public public/index.php   # committed dist → runs without Node
vendor/bin/weft-health --dev                        # exit 1 on a fresh copy: contact_form, example.com addresses

# To work on the frontend with HMR, opt into dev mode:
cp config/config.example.php config/config.php      # set DEV_MODE=true in it
npm install
npm run dev                                          # Vite dev server
```

## Code style

- PHP 8.4, `declare(strict_types=1)` in every file.
- Engine classes live under `Golovanov\Weft\`, one concern per namespace.
- Static facades (`Seo::`, `Cache::`, `Trace::`) are intentional — templates and endpoints call
  them directly, without a container.
- Comments explain *why*, not *what*. Skip a comment if the code already says it.
- Optional features must degrade safely: when a feature is off, its calls stay valid no-ops
  (`Cache::remember()` still executes the callback, `Captcha::verify()` returns a pass).

## Before a pull request

```bash
# run the test suite
composer install
composer test

# syntax check
find src -name "*.php" -exec php -l {} \;

# exercise the engine through the example site
cd example && php -S localhost:8000 -t public public/index.php
```

New engine behaviour should come with a test in `tests/` — see `tests/TestCase.php` for the
static-state reset the suite relies on.

### Integration tests

Tests in the `integration` group run `Database` against a real MySQL server and the Redis cache and
rate limiter against a real Redis. Without `WEFT_TEST_DB_HOST` and `WEFT_TEST_REDIS_HOST` they are
skipped, so `composer test` passes without either. The MySQL tests create and drop the table
`weft_items` and kill their own connections: give them a database of their own. MySQL listens on
port 3306, Redis on 6379, without a password; Redis keys are `weft-test[…]:*` and are removed. CI
runs the group on MySQL 5.7 and 8.0.

```bash
# from the repository root; any Docker host works
docker run -d --name weft-mysql -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=weft_test -p 3306:3306 mysql:8.0
docker run -d --name weft-redis -p 6379:6379 redis:7-alpine

# wait until both answer: a fresh MySQL takes 10-30 s to start
until docker exec weft-mysql mysqladmin ping -h 127.0.0.1 --silent; do sleep 1; done
until docker exec weft-redis redis-cli ping; do sleep 1; done

# WEFT_TEST_DB_NAME (default weft_test) and WEFT_TEST_DB_USER (default root) are optional
WEFT_TEST_DB_HOST=127.0.0.1 WEFT_TEST_DB_PASSWORD=root WEFT_TEST_REDIS_HOST=127.0.0.1 \
  vendor/bin/phpunit --group integration --fail-on-skipped

docker rm -f weft-mysql weft-redis
```

### Smoke test of the example

`tests/smoke/run.php` copies `example/` to a temporary directory, adds the pages of
`tests/smoke/site/`, installs the engine from your working tree, writes a production-like
`config/config.php` and checks the site through `php -S`: `vendor/bin/weft-health` exits with 0,
pages, 404, security headers, the contact form (the email must reach a mail catcher), catch-all
and paginated pages, `robots.txt`, `llms.txt` and the IndexNow key file. Your `example/` is not
changed. It needs Composer, access to Packagist and a mail catcher with Mailpit's API.

```bash
# from the repository root
docker run -d --name weft-mailpit -p 1025:1025 -p 8025:8025 axllent/mailpit
until curl -sf http://127.0.0.1:8025/readyz; do sleep 1; done
php tests/smoke/run.php   # SMOKE_SMTP_PORT (1025) and SMOKE_MAILPIT_URL (http://127.0.0.1:8025) override
docker rm -f weft-mailpit
```

Please describe *which real site problem* the change solves. "It would be nice to have" is not
enough for the engine — but it may be a great example or a documentation page.

## Reporting issues

Include the PHP version, the relevant part of `config/config.php` (**without secrets**), and the
`X-Trace-Id` from the response if the problem is a runtime error — it points straight at the
request timeline in `storage/logs/requests/`.

Security vulnerabilities are the exception: report them privately, as described in
[SECURITY.md](SECURITY.md), not as a public issue.
