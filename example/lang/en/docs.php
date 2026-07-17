<?php

/**
 * Strings for the docs section — both /docs and /docs/<slug> read this file.
 *
 *  - /docs            -> route "docs"  (served by pages/docs/index.php)
 *  - /docs/whatever   -> dynamic page pages/docs/[slug].php; dynamic pages take their route name
 *                        from the parent directory, which is also "docs".
 */

return [
    'docs' => [
        'index' => [
            'meta_title' => 'Documentation',
            'meta_description' => 'Install Weft, learn the conventions, and ship a simple site: routing, SEO, i18n, caching, tracing and forms.',
            'eyebrow' => 'Documentation',
            'title' => 'Everything you need to ship a site with Weft',
            'lead' => 'Read in order — each chapter builds on the previous one.',
        ],
        'page' => [
            'eyebrow' => 'Documentation',
            'sidebar' => 'Chapters',
            'pager' => 'Chapter navigation',
            'prev' => 'Previous',
            'next' => 'Next',
            'missing' => 'That chapter does not exist. It may have been renamed.',
            'all' => 'All chapters',
        ],
    ],
];
