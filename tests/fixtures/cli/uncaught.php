<?php

declare(strict_types=1);

/**
 * Fixture for ErrorHandlerTest: a CLI script that dies on an uncaught throwable while the
 * engine's error handler is registered. Run as a subprocess so the assertion can be made on
 * the real exit status — the behaviour under test is process termination, which cannot be
 * observed from inside the test process.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

define('APP_ROOT', __DIR__);
define('LOGS_PATH', sys_get_temp_dir() . '/weft-cli-exit-fixture');

Golovanov\Weft\Observability\ErrorHandler::run();

throw new RuntimeException('boom');
