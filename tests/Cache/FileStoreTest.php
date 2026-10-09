<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Cache;

use Golovanov\Weft\Cache\FileStore;
use Golovanov\Weft\Tests\TestCase;

final class FileStoreTest extends TestCase
{
    private string $dir = '';
    private FileStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->makeTempDir();
        $this->store = new FileStore($this->dir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    public function testSetAndGetRoundTripPreservesTypes(): void
    {
        $this->store->set('str', 'value');
        $this->store->set('arr', ['a' => 1]);
        $this->store->set('int', 42);

        $this->assertSame('value', $this->store->get('str'));
        $this->assertSame(['a' => 1], $this->store->get('arr'));
        $this->assertSame(42, $this->store->get('int'));
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->store->get('missing'));
    }

    public function testZeroTtlMeansNoExpiry(): void
    {
        $this->store->set('forever', 'v', 0);
        $this->assertSame('v', $this->store->get('forever'));
    }

    public function testExpiredEntryIsAMissAndItsFileIsRemoved(): void
    {
        // Write an already-expired payload directly, matching the on-disk format.
        $file = $this->dir . '/' . sha1('old') . '.cache';
        file_put_contents($file, serialize(['e' => time() - 10, 'v' => 'stale']));

        $this->assertNull($this->store->get('old'));
        $this->assertFileDoesNotExist($file);
    }

    public function testObjectsComeBackWithoutTheirClass(): void
    {
        $this->store->set('obj', new \ArrayObject([1]));

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $this->store->get('obj'));
    }

    public function testPruneDeletesOnlyExpiredEntries(): void
    {
        $this->store->set('live', 1, 60);
        $this->store->set('forever', 1);
        $expired = $this->dir . '/' . sha1('old') . '.cache';
        file_put_contents($expired, serialize(['e' => time() - 10, 'v' => 1]));

        $this->store->prune();

        $this->assertFileDoesNotExist($expired);
        $this->assertCount(2, glob($this->dir . '/*.cache') ?: []);
    }

    public function testCorruptedFileIsAMiss(): void
    {
        $file = $this->dir . '/' . sha1('bad') . '.cache';
        file_put_contents($file, 'not-serialized-garbage');

        $this->assertNull($this->store->get('bad'));
    }

    public function testHasForgetAndFlush(): void
    {
        $this->store->set('a', 1);
        $this->store->set('b', 2);

        $this->assertTrue($this->store->has('a'));
        $this->store->forget('a');
        $this->assertFalse($this->store->has('a'));

        $this->store->flush();
        $this->assertFalse($this->store->has('b'));
    }

    public function testIncrementCountsWithinTheWindowSetByTheFirstHit(): void
    {
        $file = $this->dir . '/' . sha1('hits') . '.cache';

        $this->assertSame(1, $this->store->increment('hits', 60));
        $expiry = unserialize((string) file_get_contents($file))['e'];
        $this->assertSame(2, $this->store->increment('hits', 3600));

        $this->assertSame($expiry, unserialize((string) file_get_contents($file))['e']);
        $this->assertSame(2, $this->store->get('hits'));
    }

    public function testIncrementStartsANewWindowAfterExpiry(): void
    {
        $file = $this->dir . '/' . sha1('hits') . '.cache';
        file_put_contents($file, serialize(['e' => time() - 10, 'v' => 5]));

        $this->assertSame(1, $this->store->increment('hits', 60));
    }
}
