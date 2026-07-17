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

        $this->assertTrue(Config::isLoaded());
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
}
