<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Http\HttpClient;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Observability\Health;

/**
 * IndexNow (indexnow.org): tells Bing, Yandex and the other participating search engines which
 * pages are new or changed, instead of waiting for them to recrawl.
 *
 * The kernel serves the key at /<key>.txt. `vendor/bin/weft-indexnow` (cron) submits the sitemap
 * addresses that are new or whose lastmod changed since the last accepted submission, and
 * remembers what was accepted in the state file. Config 'app.indexnow': `key`, `endpoint`
 * (default self::ENDPOINT), `state` (default storage/indexnow.json, outside the cache).
 */
final class IndexNow
{
    public const string ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** URLs per request: the protocol's limit. */
    private const int BATCH = 10000;

    /** Why the configured key is unusable, or null when it is fine or not set (IndexNow off). */
    public static function keyError(): ?string
    {
        $key = self::key();
        return $key === '' || preg_match('/^[a-zA-Z0-9-]{8,128}$/D', $key)
            ? null
            : 'app.indexnow.key must be 8 to 128 characters: letters, digits, dashes';
    }

    /** The path the key file is served at, '/<key>.txt', or null without a usable key. */
    public static function keyFile(): ?string
    {
        return self::key() !== '' && self::keyError() === null ? '/' . self::key() . '.txt' : null;
    }

    public static function key(): string
    {
        return (string) Config::get('app.indexnow.key', '');
    }

    /**
     * Submit the new and changed sitemap addresses. Writes what it did to $out, problems to $err.
     *
     * @param list<string> $args command-line arguments, without the command name
     * @param resource $out
     * @param resource $err
     * @return int exit status: 0 when nothing failed, 1 when something did, 2 on an unknown option
     */
    public static function run(array $args, $out, $err): int
    {
        $usage = "usage: weft-indexnow [--dry-run]\n"
            . "  submits the sitemap addresses that are new or have a new lastmod; --dry-run lists them\n";
        if (in_array('--help', $args, true)) {
            fwrite($out, $usage);
            return 0;
        }
        $unknown = array_diff($args, ['--dry-run']);
        if ($unknown !== []) {
            fwrite($err, 'weft-indexnow: unknown option ' . implode(' ', $unknown) . "\n" . $usage);
            return 2;
        }

        $problem = match (true) {
            self::key() === '' => 'app.indexnow.key is not set',
            self::keyError() !== null => self::keyError(),
            Url::origin() === '' => 'app.url.base is not set',
            Health::isExampleHost((string) parse_url(Url::origin(), PHP_URL_HOST)) => 'app.url.base is on example.com',
            default => null,
        };
        if ($problem !== null) {
            fwrite($err, "weft-indexnow: {$problem}.\n");
            return 1;
        }

        $file = Config::path((string) Config::get('app.indexnow.state', '') ?: 'storage/indexnow.json');
        $accepted = self::readState($file);
        if ($accepted === null) {
            fwrite($err, "weft-indexnow: {$file} is not a state file of weft-indexnow.\n");
            return 1;
        }

        $current = Sitemap::lastmods(Sitemap::routes());
        $changed = array_keys(array_filter(
            $current,
            static fn(string $lastmod, string $url): bool => ($accepted[$url] ?? null) !== $lastmod,
            ARRAY_FILTER_USE_BOTH,
        ));
        // A change to a page without a lastmod cannot be seen: it is submitted once, when new.
        $undated = count(array_keys($current, '', true));
        $note = $undated === 0
            ? ''
            : "{$undated} of " . count($current) . " addresses have no lastmod: each is submitted once and never again.\n";

        if ($changed === []) {
            fwrite($out, 'Nothing to submit: ' . count($current) . " addresses unchanged.\n" . $note);
            return 0;
        }
        if (in_array('--dry-run', $args, true)) {
            fwrite($out, 'Would submit ' . count($changed) . " addresses:\n" . implode("\n", $changed) . "\n" . $note);
            return 0;
        }
        if ((bool) Config::env('DEBUG', false)) {
            fwrite($out, 'DEBUG is on: ' . count($changed) . " addresses not submitted.\n");
            return 0;
        }

        $endpoint = (string) Config::get('app.indexnow.endpoint', '') ?: self::ENDPOINT;
        $state = $accepted;
        foreach (array_chunk($changed, self::BATCH) as $batch) {
            $response = (new HttpClient(timeout: 30))->post($endpoint, [
                'host' => (string) parse_url(Url::origin(), PHP_URL_HOST),
                'key' => self::key(),
                'keyLocation' => Url::origin() . Url::asset(self::key() . '.txt'),
                'urlList' => $batch,
            ], ['decodeJson' => false]);

            if (!in_array($response['status'], [200, 202], true)) {
                fwrite($err, 'weft-indexnow: ' . $endpoint . ' did not accept ' . count($batch) . ' addresses: '
                    . ($response['error'] ?? 'HTTP ' . $response['status']) . ".\n");
                return 1;
            }
            foreach ($batch as $url) {
                $state[$url] = $current[$url];
            }
            if (!self::writeState($file, $state)) {
                fwrite($err, "weft-indexnow: cannot write {$file}.\n");
                return 1;
            }
        }

        fwrite($out, 'Submitted ' . count($changed) . " addresses to {$endpoint}.\n" . $note);
        return 0;
    }

    /** @return array<string, string>|null accepted address => its lastmod; null when the file is not one */
    private static function readState(string $file): ?array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        return is_array($data) && array_filter($data, 'is_string') === $data ? $data : null;
    }

    /** @param array<string, string> $state */
    private static function writeState(string $file, array $state): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $file . '.tmp';
        return @file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false
            && @rename($tmp, $file);
    }
}
