<?php

declare(strict_types=1);

/**
 * Front controller / web entry point. The web server's document root points here.
 *
 * Everything else (config, autoload, tracing, routing, rendering) is handled by the shared
 * bootstrap and the Weft kernel.
 */

// PHP's built-in server (php -S ... -t public public/index.php) runs this script for every request,
// files included. Let it send an existing file inside public/ (CSS, JS, images) itself. Apache and
// nginx serve those files before PHP is reached, so this does nothing there.
if (PHP_SAPI === 'cli-server') {
    $file = realpath(__DIR__ . (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    if ($file !== false && $file !== __FILE__ && is_file($file) && str_starts_with($file, __DIR__ . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

error_reporting(E_ALL);
ini_set('display_errors', '0');   // errors are logged, never shown; DEBUG controls page output
ini_set('log_errors', '1');

// Web root, used by the Vite manifest resolver.
define('WEB_ROOT', __DIR__);

// Session cookie parameters. The session itself starts only when a form needs a CSRF token
// (Csrf), so pages without a form set no cookie.
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);

require dirname(__DIR__) . '/bootstrap.php';

Golovanov\Weft\Kernel::handle();
