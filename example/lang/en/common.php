<?php

/**
 * Shared strings, loaded for every route. Per-route files (home.php, docs.php, …) are merged on
 * top. Keys are read with a dot path: t('nav.docs'), t('error.404.title').
 */

return [
    'nav' => [
        'docs' => 'Docs',
        'contact' => 'Feedback',
    ],
    'footer' => [
        'tagline' => 'A lightweight PHP engine for simple websites',
        'issues' => 'Issues',
        'built_with' => 'This site is built with Weft',
    ],
    'error' => [
        '404' => [
            'title' => 'Page not found',
            'text' => 'The page you are looking for does not exist or has moved.',
            'home' => 'Back to home',
        ],
    ],
];
