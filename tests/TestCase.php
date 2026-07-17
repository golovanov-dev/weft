<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests;

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\RequestContext;
use Golovanov\Weft\I18n\I18n;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\Seo\Seo;
use Golovanov\Weft\View\View;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case: the engine keeps per-request state in static properties (populated once by
 * the Kernel in production). Tests reset that state around every run so cases stay isolated.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::resetEngineState();
    }

    protected function tearDown(): void
    {
        self::resetEngineState();
        parent::tearDown();
    }

    protected static function resetEngineState(): void
    {
        self::setStatics(Config::class, ['items' => [], 'loaded' => false]);
        self::setStatics(Seo::class, ['data' => [], 'jsonLd' => [], 'booted' => false]);
        self::setStatics(Locale::class, ['current' => null]);
        self::setStatics(I18n::class, ['dict' => [], 'locale' => 'en']);
        self::setStatics(Cache::class, ['store' => null]);
        self::setStatics(View::class, ['shared' => []]);
        self::setStatics(RequestContext::class, [
            'request' => null,
            'traceId' => '',
            'startedAt' => 0.0,
            'logPath' => 'requests',
        ]);

        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['REMOTE_ADDR']);
    }

    /** @param array<string, mixed> $values */
    private static function setStatics(string $class, array $values): void
    {
        $ref = new \ReflectionClass($class);
        foreach ($values as $name => $value) {
            $ref->getProperty($name)->setValue(null, $value);
        }
    }

    /** Create a scratch directory unique to the running test. */
    protected function makeTempDir(string $prefix = 'weft-test-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($dir, 0770, true);
        return $dir;
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDir($file) : @unlink($file);
        }
        @rmdir($dir);
    }
}
