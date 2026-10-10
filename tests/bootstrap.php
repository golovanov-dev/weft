<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/*
 * The engine reads its filesystem root from the APP_ROOT environment constant.
 * Tests run against a tiny fixture site (pages/, api/, components/, lang/).
 */
define('APP_ROOT', __DIR__ . '/fixtures/site');

/*
 * Error logs and traces of this test process go to a directory of its own, removed when it ends,
 * not to the shared weft-logs in the system temp directory.
 */
define('LOGS_PATH', sys_get_temp_dir() . '/weft-test-logs-' . bin2hex(random_bytes(6)));
register_shutdown_function(static function (): void {
    $remove = static function (string $dir) use (&$remove): void {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $name) {
            is_dir($dir . '/' . $name) ? $remove($dir . '/' . $name) : @unlink($dir . '/' . $name);
        }
        @rmdir($dir);
    };
    $remove(LOGS_PATH);
});
