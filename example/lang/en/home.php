<?php

return [
    'home' => [
        'hero' => [
            'eyebrow' => 'PHP 8.4 · MIT · No framework lock-in',
            'title' => 'Launch a simple website without re-solving the same problems.',
            'lead' => 'Weft gives you routing, SEO, optional i18n, caching, request tracing and form protection out of the box — so a new site is mostly content.',
            'cta_primary' => 'Read the docs',
            'cta_secondary' => 'View on GitHub',
        ],
        'showcase' => [
            'title' => 'A route is just a file',
            'lead' => 'No route table, no controller, no registration. Drop a file in pages/ and it answers a URL.',
            'note' => 'This very site is the example: it documents Weft while being built with it.',
        ],
        'features' => [
            'title' => 'What you get',
            'routing' => [
                'title' => 'Convention routing',
                'text' => 'pages/about.php → /about, api/contact.php → /api/contact, pages/notes/[slug].php → /notes/anything.',
            ],
            'seo' => [
                'title' => 'SEO under the hood',
                'text' => 'Title templates, canonical, Open Graph, Twitter cards, hreflang and JSON-LD. Generated sitemap.xml and robots.txt.',
            ],
            'i18n' => [
                'title' => 'Optional i18n',
                'text' => 'Off by default. Turn it on and the default locale stays prefix-free while others get /ru/…, with automatic hreflang.',
            ],
            'cache' => [
                'title' => 'Optional caching',
                'text' => 'File or Redis. Cache::remember() is safe to use even when caching is disabled, so you can switch it on later.',
            ],
            'trace' => [
                'title' => 'Request tracing',
                'text' => 'One JSONL timeline per request via traceloom, with automatic redaction of secrets and an X-Trace-Id on every response.',
            ],
            'forms' => [
                'title' => 'Forms that survive bots',
                'text' => 'Rate limiting, CSRF, honeypot and reCAPTCHA v3 — wired and enabled by config, not by copy-paste.',
            ],
        ],
        'docs' => [
            'title' => 'Documentation',
        ],
        'cta' => [
            'text' => 'Five minutes from composer require to a running site.',
            'button' => 'Get started',
        ],
    ],
];
