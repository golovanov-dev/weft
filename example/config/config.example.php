<?php

/**
 * Environment configuration (template).
 *
 * Copy to config.php on each server and fill in real values. This file holds secrets and
 * per-environment switches as PHP constants. Structured, non-secret business settings live in
 * /app/Configs/app.php and are read via config('app.*').
 *
 * Never commit the real config.php — it is gitignored.
 */

declare(strict_types=1);

// --- Environment switches -----------------------------------------------------------------
define('DEBUG', false);       // verbose errors, curl info, robots blocked
define('DEV_MODE', false);    // true => Vite dev server + relaxed HTTPS

// Sub-directory the app is mounted under, "" for a domain root (https://example.com).
define('BASE_URI', '');

// Absolute log directory: errors/, traces in requests/ (the site) and cli/ (scripts).
define('LOGS_PATH', dirname(__DIR__) . '/storage/logs');

// Application timezone — all dates, logs and the DB session use it. Name or offset; UTC if unset.
define('APP_TIMEZONE', 'UTC');

// --- Database (optional — only if the site uses Golovanov\Weft\Database) ----------------------------
define('DB_HOST', '127.0.0.1');
define('DB_NAME', '');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');

// --- Redis (optional — cache/rate-limit driver 'redis') ----------------------------------
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_DB', 0);
define('REDIS_PASSWORD', '');

// --- Google reCAPTCHA v3 (see app.captcha.enabled) ---------------------------------------
define('RECAPTCHA_SITEKEY', '');
define('RECAPTCHA_SECRETKEY', '');

// --- AlertLoop (notifications for sensitive spots) ---------------------------------------
define('ALERTLOOP_BASE_URL', '');   // e.g. https://alerts.example.com
define('ALERTLOOP_API_KEY', '');    // setting it enables alerts; needs ALERTLOOP_BASE_URL too

// --- Contact form email via PHP mail(): set your own addresses; '' sends no email ------------
// The form answers success only when this email or the AlertLoop alert went out.
define('MAIL_TO', 'hello@example.com');
define('MAIL_FROM', 'noreply@example.com');

// --- JWT (optional — verifying access tokens) --------------------------------------------
define('AUTH_JWT_KEY', '');
define('AUTH_JWT_ISSUER', '');

// --- Trusted reverse proxies: addresses or CIDR ranges; X-Forwarded-For is read only from these
define('PROXY_IPS', []);

// Reuse an incoming X-Trace-Id / X-Request-Id (internal services) instead of generating one.
define('TRACE_TRUST_INCOMING', false);
