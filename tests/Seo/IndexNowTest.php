<?php

declare(strict_types=1);

namespace Golovanov\Weft\Tests\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Observability\Health;
use Golovanov\Weft\Seo\IndexNow;
use Golovanov\Weft\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** IndexNow::run(), the body of vendor/bin/weft-indexnow, against a fake endpoint. */
final class IndexNowTest extends TestCase
{
    /** The line every run that reads the sitemap prints: /about has no lastmod. */
    private const string UNDATED = "1 of 2 addresses have no lastmod: each is submitted once and never again.\n";

    private string $dir = '';

    /** @var list<array<string, mixed>> requests the fake endpoint received */
    private array $sent = [];

    private int $status = 200;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->makeTempDir('weft-indexnow-');
        Config::set('app.url.base', 'https://site.test');
        Config::set('app.indexnow', ['key' => 'a1B2-c3D4e5', 'state' => $this->dir . '/state/indexnow.json']);
        Config::set('app.sitemap', [['path' => '/', 'lastmod' => '2026-10-01'], '/about']);
        self::fakeHttp(function (array $request): array {
            $this->sent[] = $request;
            return ['status' => $this->status];
        });
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    /** @param list<string> $args @return array{int, string, string} exit status, output, errors */
    private function submit(array $args = []): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $status = IndexNow::run($args, $out, $err);
        rewind($out);
        rewind($err);
        return [$status, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    /** @return list<string> */
    private function submittedUrls(int $request): array
    {
        return json_decode((string) $this->sent[$request]['body'], true)['urlList'];
    }

    /** The first run submits every address; the key file is named, here under BASE_URI. */
    #[RunInSeparateProcess]
    public function testFirstRunSubmitsEveryAddressWithTheKeyLocation(): void
    {
        define('BASE_URI', '/site');

        [$status, $output] = $this->submit();

        $this->assertSame(0, $status);
        $this->assertSame("Submitted 2 addresses to https://api.indexnow.org/indexnow.\n" . self::UNDATED, $output);
        $this->assertSame('POST', $this->sent[0]['method']);
        $this->assertSame('https://api.indexnow.org/indexnow', $this->sent[0]['url']);
        $this->assertSame([
            'host' => 'site.test',
            'key' => 'a1B2-c3D4e5',
            'keyLocation' => 'https://site.test/site/a1B2-c3D4e5.txt',
            'urlList' => ['https://site.test/site/', 'https://site.test/site/about'],
        ], json_decode((string) $this->sent[0]['body'], true));
    }

    /** An address without a lastmod goes once, when new, and the output says so every time. */
    public function testLaterRunsSubmitOnlyNewAddressesAndChangedLastmods(): void
    {
        $this->submit();
        [$status, $output] = $this->submit();
        $this->assertSame(0, $status);
        $this->assertSame("Nothing to submit: 2 addresses unchanged.\n" . self::UNDATED, $output);
        $this->assertCount(1, $this->sent);

        Config::set('app.sitemap', [['path' => '/', 'lastmod' => '2026-10-09'], '/about', '/new']);
        $this->submit();
        $this->assertSame(['https://site.test/', 'https://site.test/new'], $this->submittedUrls(1));
    }

    /** Only an accepted submission is remembered: a refused one goes again next time. */
    public function testARefusedSubmissionIsNotRemembered(): void
    {
        $this->status = 403;
        [$status, , $errors] = $this->submit();
        $this->assertSame(1, $status);
        $this->assertSame("weft-indexnow: https://api.indexnow.org/indexnow did not accept 2 addresses: HTTP 403.\n", $errors);
        $this->assertFileDoesNotExist($this->dir . '/state/indexnow.json');

        $this->status = 202;
        $this->assertSame(0, $this->submit()[0]);
        $this->assertCount(2, $this->submittedUrls(1));
    }

    public function testDryRunListsWithoutSubmittingOrRemembering(): void
    {
        [$status, $output] = $this->submit(['--dry-run']);

        $this->assertSame(0, $status);
        $this->assertSame("Would submit 2 addresses:\nhttps://site.test/\nhttps://site.test/about\n" . self::UNDATED, $output);
        $this->assertSame([], $this->sent);
        $this->assertFileDoesNotExist($this->dir . '/state/indexnow.json');
    }

    /** A typo of --dry-run must not submit for real; --help only prints the usage. */
    public function testAnUnknownOptionSubmitsNothing(): void
    {
        [$status, $output, $errors] = $this->submit(['--dryrun']);

        $this->assertSame([2, ''], [$status, $output]);
        $this->assertStringStartsWith("weft-indexnow: unknown option --dryrun\nusage: weft-indexnow [--dry-run]\n", $errors);
        $this->assertSame([], $this->sent);
        $this->assertFileDoesNotExist($this->dir . '/state/indexnow.json');

        [$status, $output, $errors] = $this->submit(['--help']);
        $this->assertSame([0, ''], [$status, $errors]);
        $this->assertStringStartsWith('usage: weft-indexnow [--dry-run]', $output);
        $this->assertSame([], $this->sent);
    }

    /** The template's address is not submitted: the same check as health's `production`. */
    public function testAnExampleComAddressIsRefused(): void
    {
        Config::set('app.url.base', 'https://www.example.com');

        $this->assertSame([1, '', "weft-indexnow: app.url.base is on example.com.\n"], $this->submit());
        $this->assertSame([], $this->sent);
    }

    #[RunInSeparateProcess]
    public function testDebugSubmitsNothing(): void
    {
        define('DEBUG', true);

        $this->assertSame([0, "DEBUG is on: 2 addresses not submitted.\n", ''], $this->submit());
        $this->assertSame([], $this->sent);
    }

    /** A key outside the protocol's format: the command refuses and health fails. */
    public function testAMalformedKeyIsRefused(): void
    {
        // The key the guide's command generates is accepted.
        Config::set('app.indexnow.key', bin2hex(random_bytes(16)));
        $this->assertNull(IndexNow::keyError());

        Config::set('app.indexnow.key', 'key with spaces');

        $this->assertSame(
            [1, '', "weft-indexnow: app.indexnow.key must be 8 to 128 characters: letters, digits, dashes.\n"],
            $this->submit(),
        );
        $report = Health::report(dev: true);
        $this->assertFalse($report['ok']);
        $this->assertSame('app.indexnow.key must be 8 to 128 characters: letters, digits, dashes', $report['indexnow_error']);
    }
}
