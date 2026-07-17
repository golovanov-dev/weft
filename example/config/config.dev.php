<?php

/**
 * Local development environment. Loaded automatically when config.php is absent.
 * Safe to commit — it must not contain production secrets.
 */

declare(strict_types=1);

define('DEBUG', true);
define('DEV_MODE', true);        // <script>/<link> point at the Vite dev server (npm run dev)

define('BASE_URI', '');
define('LOGS_PATH', dirname(__DIR__) . '/storage/logs');
define('APP_TIMEZONE', 'UTC');

// Database off by default in the skeleton.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');

define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_DB', 0);
define('REDIS_PASSWORD', '');

// Captcha disabled locally — Captcha::verify() returns a passing result so forms still work.
define('RECAPTCHA_SITEKEY', '');
define('RECAPTCHA_SECRETKEY', '');

define('ALERTLOOP_BASE_URL', '');
define('ALERTLOOP_API_KEY', '');

define('MAIL_TO', 'hello@golovanov.dev');
define('MAIL_FROM', 'noreply@golovanov.dev');

define('AUTH_JWT_KEY', '');
define('AUTH_JWT_ISSUER', '');

define('PROXY_IPS', []);
define('TRACE_TRUST_INCOMING', false);
