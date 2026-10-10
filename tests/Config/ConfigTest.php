<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Config;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testLoadUsesFilenameAsTopLevelKey(): void
    {
        Config::load(__DIR__ . '/../fixtures/config');

        $this->assertSame('Fixture', Config::get('app.name'));
        $this->assertSame('value', Config::get('app.nested.key'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertNull(Config::get('nope.nope'));
        $this->assertSame('d', Config::get('nope.nope', 'd'));
    }

    public function testGetReturnsFalsyValuesInsteadOfDefault(): void
    {
        Config::load(__DIR__ . '/../fixtures/config');
        $this->assertFalse(Config::get('app.nested.flag', true));
    }

    public function testSetInjectsNestedValueAtRuntime(): void
    {
        Config::set('app.deep.key', 'v');
        $this->assertSame('v', Config::get('app.deep.key'));
        $this->assertSame(['key' => 'v'], Config::get('app.deep'));
    }

    public function testSetOverwritesScalarNodeWithArrayWhenPathGoesDeeper(): void
    {
        Config::set('app.node', 'scalar');
        Config::set('app.node.child', 1);
        $this->assertSame(1, Config::get('app.node.child'));
    }

    public function testHasDistinguishesPresenceFromNull(): void
    {
        Config::set('app.present', null);
        $this->assertTrue(Config::has('app.present'));
        $this->assertFalse(Config::has('app.absent'));
    }

    public function testEnvReadsConstantsWithDefault(): void
    {
        // APP_ROOT is defined by the test bootstrap; an undefined constant yields the default.
        $this->assertSame(APP_ROOT, Config::env('APP_ROOT'));
        $this->assertSame('d', Config::env('WEFT_TEST_UNDEFINED_CONSTANT', 'd'));
    }

    public function testPathResolvesARelativePathFromAppRoot(): void
    {
        $this->assertSame(APP_ROOT . '/storage/cache', Config::path('storage/cache'));
        $this->assertSame('/var/cache/site', Config::path('/var/cache/site'));
        $this->assertSame('C:\site\cache', Config::path('C:\site\cache'));
    }

    /**
     * An empty APP_ROOT must not turn a relative path into one under the filesystem root; without
     * LOGS_PATH logs go to the system temp directory. The bootstrap defines both for every test,
     * so this runs in a subprocess.
     */
    public function testPathsFallBackWhenAppRootIsEmptyAndLogsPathUnset(): void
    {
        $script = dirname(__DIR__) . '/fixtures/cli/path.php';
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(__DIR__) . ' 2>&1', $output);

        $this->assertSame([__DIR__ . '/storage/cache', sys_get_temp_dir() . '/weft-logs'], $output);
    }
}
