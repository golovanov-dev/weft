<?php

declare(strict_types=1);

/**
 * Front controller / web entry point. The web server's document root points here.
 *
 * Everything else (config, autoload, tracing, routing, rendering) is handled by the shared
 * bootstrap and the Weft kernel.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');   // errors are logged, never shown; DEBUG controls page output
ini_set('log_errors', '1');

// Web root, used by the Vite manifest resolver.
define('WEB_ROOT', __DIR__);

// Sessions (CSRF + session-based rate limiting).
session_set_cookie_params(['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

require dirname(__DIR__) . '/bootstrap.php';

Golovanov\Weft\Kernel::handle();
