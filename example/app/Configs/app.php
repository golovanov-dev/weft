<?php

/**
 * Business configuration of the docs site (structured, non-secret, committed to git).
 *
 * Read anywhere with config('app.<dot.path>'). Secrets come from the environment constants in
 * config/config.php instead.
 */

declare(strict_types=1);

use App\Services\DocsService;

$origin = 'https://weft.golovanov.dev';   // change to your domain

return [
    'default_route' => 'home',
    'layout' => 'layout',

    'url' => [
        'base' => $origin,
    ],

    // Multilingual support is OFF: the docs are English-only. Add a locale and content/<locale>/
    // to turn it on — see the "Features" chapter.
    'locales' => [
        'enabled' => false,
        'default' => 'en',
        'supported' => ['en'],
        'names' => ['en' => 'English'],
    ],

    'seo' => [
        'site_name' => 'Weft',
        'default_title' => 'Weft — a lightweight PHP engine for simple websites',
        'title_template' => '%s — Weft',
        'description' => 'Weft is a lightweight PHP 8.4 engine for quickly launching simple websites: SEO, optional i18n, caching, request tracing and form protection out of the box.',
        'og_image' => '/img/og.png',
        'twitter' => '',
        'robots' => 'index, follow',
        'robots_allow' => true,
        'robots_disallow' => ['/api/'],
        'json_ld' => [
            '@type' => 'SoftwareSourceCode',
            'name' => 'Weft',
            'description' => 'A lightweight PHP engine for quickly launching simple websites.',
            'codeRepository' => 'https://github.com/golovanov-dev/weft',
            'programmingLanguage' => 'PHP',
            'license' => 'https://opensource.org/licenses/MIT',
            'author' => ['@type' => 'Person', 'name' => 'Roman Golovanov', 'url' => 'https://golovanov.dev'],
        ],
    ],

    // The docs URLs come from Markdown files, so the sitemap is built at request time.
    // 'app.sitemap' accepts a plain array or any callable returning one.
    'sitemap' => static fn(): array => (new DocsService())->sitemapRoutes(),

    'vite' => [
        'entry' => 'src/main.js',
        'dev_server' => 'http://localhost:5173',
    ],

    // Caching is ON here: it makes Markdown rendering cheap and shows the feature in a real setting.
    'cache' => [
        'enabled' => true,
        'driver' => 'file',
        'path' => dirname(__DIR__, 2) . '/storage/cache',
        'prefix' => 'weft:docs:',
    ],

    'rate_limit' => [
        'max' => 20,
        'window' => 60,
    ],

    'captcha' => [
        'enabled' => defined('RECAPTCHA_SECRETKEY') && RECAPTCHA_SECRETKEY !== '',
        'site_key' => defined('RECAPTCHA_SITEKEY') ? RECAPTCHA_SITEKEY : '',
        'secret_key' => defined('RECAPTCHA_SECRETKEY') ? RECAPTCHA_SECRETKEY : '',
        'threshold' => 0.5,
        'allowed_hosts' => ['weft.golovanov.dev'],
    ],

    'alertloop' => [
        'enabled' => defined('ALERTLOOP_API_KEY') && ALERTLOOP_API_KEY !== '',
        'base_url' => defined('ALERTLOOP_BASE_URL') ? ALERTLOOP_BASE_URL : '',
        'api_key' => defined('ALERTLOOP_API_KEY') ? ALERTLOOP_API_KEY : '',
        'source' => 'weft-docs',
        'timeout' => 4,
    ],

    'security' => [
        'frame_options' => 'SAMEORIGIN',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
        'hsts' => true,
        'csp' => '',
    ],

    'contact' => [
        'mail_to' => defined('MAIL_TO') ? MAIL_TO : '',
        'mail_from' => defined('MAIL_FROM') ? MAIL_FROM : '',
        'request_types' => ['question', 'bug', 'idea', 'other'],
    ],

    'links' => [
        'github' => 'https://github.com/golovanov-dev/weft',
        'issues' => 'https://github.com/golovanov-dev/weft/issues',
    ],
];
