<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

/*
 * The engine reads its filesystem root from the APP_ROOT environment constant.
 * Tests run against a tiny fixture site (pages/, api/, components/, lang/).
 */
define('APP_ROOT', __DIR__ . '/fixtures/site');
