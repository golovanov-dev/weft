# Contributing to Weft

Thanks for taking a look. Weft is small on purpose — the bar for adding to the engine is that a
feature is needed by *most* content sites. Anything site-specific belongs in the site's
`app/Services`, not here.

## Repository layout

```text
weft/
├── composer.json     the golovanov/weft package
├── src/              engine source (Golovanov\Weft\)
└── example/          a working site that also serves as the documentation
```

## Local development

The example site normally installs the engine from Packagist, exactly like a real consumer. To
develop the engine and see your changes in the example immediately, point the example at your
local checkout:

```bash
cd example
composer config repositories.weft path ../
composer require golovanov/weft:@dev --no-update
composer update golovanov/weft
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
php scripts/health.php

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

Please describe *which real site problem* the change solves. "It would be nice to have" is not
enough for the engine — but it may be a great example or a documentation page.

## Reporting issues

Include the PHP version, the relevant part of `config/config.php` (**without secrets**), and the
`X-Trace-Id` from the response if the problem is a runtime error — it points straight at the
request timeline in `storage/logs/requests/`.
