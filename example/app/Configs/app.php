<?php

/**
 * Business configuration of the docs site (structured, non-secret, committed to git).
 *
 * Read anywhere with config('app.<dot.path>'). Secrets come from the environment constants in
 * config/config.php instead.
 */

declare(strict_types=1);

use App\Services\DocsService;

$origin = 'https://example.com';   // change to your domain

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
        // Per-crawler groups; presets ai-search, ai-user, ai-training (Features, "Crawlers and AI assistants").
        // 'robots_groups' => [['agents' => ['ai-training'], 'disallow' => ['/']]],
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

    // /llms.txt for AI assistants: what the site is and where its main pages are.
    'llms' => [
        'summary' => 'Weft is a lightweight PHP 8.4 engine for content websites: pages are PHP files, SEO, i18n, caching, tracing and form protection are built in.',
        'sections' => [
            'Docs' => [
                ['url' => '/docs/getting-started', 'title' => 'Getting started', 'notes' => 'Install and run a site'],
                ['url' => '/docs/project-structure', 'title' => 'Project structure'],
                ['url' => '/docs/configuration', 'title' => 'Configuration'],
                ['url' => '/docs/features', 'title' => 'Features'],
                ['url' => '/docs/reference', 'title' => 'Reference'],
                ['url' => '/docs/deployment', 'title' => 'Deployment'],
                ['url' => '/docs/troubleshooting', 'title' => 'Troubleshooting', 'notes' => 'What weft-health reports and how to fix it'],
                ['url' => '/docs/upgrading', 'title' => 'Upgrading', 'notes' => 'From 0.1.x to 0.2'],
            ],
            'Optional' => [
                ['url' => 'https://github.com/golovanov-dev/weft', 'title' => 'Source code'],
            ],
        ],
    ],

    // IndexNow: the key file and vendor/bin/weft-indexnow are on when INDEXNOW_KEY is set.
    'indexnow' => [
        'key' => defined('INDEXNOW_KEY') ? INDEXNOW_KEY : '',
    ],

    'vite' => [
        'entry' => 'src/main.js',
        'dev_server' => 'http://localhost:5173',
    ],

    // Caching is ON here: it makes Markdown rendering cheap and shows the feature in a real setting.
    'cache' => [
        'enabled' => true,
        'driver' => 'file',
        'path' => dirname(__DIR__, 2) . '/storage/cache',
        // 'prefix' => 'docs:',   // Redis keys; default: the host of app.url.base, e.g. 'example.com:'
    ],

    'rate_limit' => [
        'max' => 20,
        'window' => 60,
        // Counters are files in ratelimit/ of app.cache.path (Redis with the redis driver), even with
        // caching off; cache-clear leaves them. 'path' => dirname(__DIR__, 2) . '/storage/ratelimit',
    ],

    'captcha' => [
        'enabled' => defined('CAPTCHA_SECRET_KEY') && CAPTCHA_SECRET_KEY !== '',
        'driver' => 'recaptcha',     // or 'smartcaptcha': then change the widget in three files, or the form
                                     // rejects every request (Features → "SmartCaptcha in this example")
        'site_key' => defined('CAPTCHA_SITE_KEY') ? CAPTCHA_SITE_KEY : '',
        'secret_key' => defined('CAPTCHA_SECRET_KEY') ? CAPTCHA_SECRET_KEY : '',
        'threshold' => 0.5,
        // Hostnames a captcha is accepted from — replace with your own domain(s).
        'allowed_hosts' => ['example.com'],
    ],

    'mail' => [
        'driver' => defined('MAIL_DRIVER') ? MAIL_DRIVER : 'mail',
        'from' => defined('MAIL_FROM') ? MAIL_FROM : '',
        'from_name' => 'Weft docs',
        'smtp' => [
            'host' => defined('SMTP_HOST') ? SMTP_HOST : '',
            'port' => defined('SMTP_PORT') ? SMTP_PORT : 587,
            'encryption' => defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'starttls',   // 587: starttls, 465: ssl
            'username' => defined('SMTP_USERNAME') ? SMTP_USERNAME : '',
            'password' => defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '',
            'timeout' => 10,         // seconds for the whole SMTP exchange
        ],
    ],

    'alerts' => [
        // Where alerts go, in this order: alertloop, telegram, mail, log (the error log only).
        // Here a channel is on when its constants in config.php are set.
        'channels' => array_keys(array_filter([
            'alertloop' => defined('ALERTLOOP_API_KEY') && ALERTLOOP_API_KEY !== '',
            'telegram' => defined('TELEGRAM_BOT_TOKEN') && TELEGRAM_BOT_TOKEN !== '',
            'mail' => defined('ALERT_MAIL_TO') && ALERT_MAIL_TO !== '',
        ])),
        'mail_to' => defined('ALERT_MAIL_TO') ? ALERT_MAIL_TO : '',
        'telegram' => [
            'bot_token' => defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : '',
            'chat_id' => defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '',
        ],
    ],

    'alertloop' => [
        'base_url' => defined('ALERTLOOP_BASE_URL') ? ALERTLOOP_BASE_URL : '',
        'api_key' => defined('ALERTLOOP_API_KEY') ? ALERTLOOP_API_KEY : '',
        'source' => 'weft-docs',   // your site's name in AlertLoop
        'timeout' => 4,            // seconds a send waits for AlertLoop
    ],

    // Traces (traceloom): web requests in storage/logs/requests, console scripts in storage/logs/cli.
    'trace' => [
        'log_body' => true,          // bodies are logged (JSON / form fields masked by key); false: size and type only
        'sensitive_keys' => [],      // extra whole key names to mask, e.g. ['passport', 'card_number'];
                                     // Latin letters and digits only: 'Пароль' masks nothing;
                                     // traceloom's password, token, api_key, … are masked anyway
        'retention_days' => 30,      // delete trace files older than this; 0 keeps them forever
    ],

    'security' => [
        'frame_options' => 'SAMEORIGIN',
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'permissions_policy' => 'geolocation=(), microphone=(), camera=()',
        'hsts' => false,             // turn on only once every page of the domain is HTTPS; see Deployment
        'hsts_max_age' => 31536000,  // seconds browsers keep HTTPS-only (one year)
        'hsts_subdomains' => false,  // includeSubDomains: every subdomain must serve HTTPS too
        'csp' => '',
    ],

    'contact' => [
        'mail_to' => defined('MAIL_TO') ? MAIL_TO : '',
        'request_types' => ['question', 'bug', 'idea', 'other'],
    ],

    'links' => [
        'github' => 'https://github.com/golovanov-dev/weft',
        'issues' => 'https://github.com/golovanov-dev/weft/issues',
    ],
];
