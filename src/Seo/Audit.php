<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\Http\HttpClient;

/**
 * `vendor/bin/weft-seo-audit`: checks a site from outside, over HTTP, so it runs anywhere (a
 * developer machine, CI) without the site's bootstrap.php.
 *
 *   weft-seo-audit https://example.com                  the pages listed in its /sitemap.xml
 *   weft-seo-audit --pages https://example.com/ ...     these pages
 *   --limit=N                                           at most N pages (default 500)
 *
 * Each page must answer 200 (redirects are not followed) with a title, a meta description, a
 * canonical link, exactly one h1, JSON-LD that parses, and no noindex in meta robots or
 * X-Robots-Tag. Needs ext-dom.
 */
final class Audit
{
    private const int LIMIT = 500;

    /**
     * @param list<string> $args command-line arguments, without the command name
     * @param resource $out the report
     * @param resource $err why it could not run
     * @return int 0: no problems, 1: problems found, 2: could not run
     */
    public static function run(array $args, $out, $err): int
    {
        $usage = "usage: weft-seo-audit [--limit=N] <site-url>\n       weft-seo-audit [--limit=N] --pages <page-url>...\n";
        if (in_array('--help', $args, true)) {
            fwrite($out, $usage);
            return 0;
        }
        if (!class_exists(\Dom\HTMLDocument::class)) {
            fwrite($err, "weft-seo-audit: needs the PHP dom extension (ext-dom).\n");
            return 2;
        }

        $limit = self::LIMIT;
        $pages = false;
        $targets = [];
        foreach ($args as $arg) {
            if ($arg === '--pages') {
                $pages = true;
            } elseif (preg_match('/^--limit=([1-9][0-9]*)$/D', $arg, $m)) {
                $limit = (int) $m[1];
            } elseif (preg_match('~^https?://~i', $arg)) {
                $targets[] = $arg;
            } else {
                $targets = [];
                break;
            }
        }
        if ($targets === [] || (!$pages && count($targets) > 1)) {
            fwrite($err, $usage);
            return 2;
        }

        $http = new HttpClient(timeout: 15);
        if (!$pages) {
            $site = self::origin($targets[0]);
            $sitemap = rtrim($targets[0], '/') . '/sitemap.xml';
            $targets = self::sitemapPages($http, $sitemap);
            if (is_string($targets)) {
                fwrite($err, "weft-seo-audit: {$sitemap}: {$targets}.\n");
                return 2;
            }
            // The sitemap names pages by app.url.base, the production address on a staging copy:
            // check them where the audit was pointed.
            $others = [];
            foreach ($targets as $i => $url) {
                $origin = self::origin($url);
                if ($origin !== '' && strcasecmp($origin, $site) !== 0) {
                    $targets[$i] = $site . substr($url, strlen($origin));
                    $others[strtolower($origin)] = $origin;
                }
            }
            if ($others !== []) {
                fwrite($out, 'The sitemap lists pages on ' . implode(', ', $others) . "; checking them on {$site}.\n\n");
            }
        }

        $total = count($targets);
        $failed = 0;
        foreach (array_slice($targets, 0, $limit) as $url) {
            $problems = self::page($http, $url);
            $failed += $problems === [] ? 0 : 1;
            fwrite($out, $url . '  ' . ($problems === [] ? 'ok' : implode('; ', $problems)) . "\n");
        }

        $checked = min($total, $limit);
        fwrite($out, "\nChecked {$checked}" . ($checked < $total ? " of {$total}" : '') . " pages: {$failed} with problems.\n");
        return $failed === 0 ? 0 : 1;
    }

    /**
     * Problems of one page; none means it passed every check.
     *
     * @return list<string>
     */
    public static function page(HttpClient $http, string $url): array
    {
        $response = self::get($http, $url);
        if ($response['status'] !== 200) {
            return [$response['error'] ?? 'HTTP ' . $response['status']];
        }

        $problems = [];
        if (str_contains(strtolower(self::header($response['headers'], 'X-Robots-Tag')), 'noindex')) {
            $problems[] = 'noindex in X-Robots-Tag';
        }

        $doc = \Dom\HTMLDocument::createFromString((string) $response['body'], LIBXML_NOERROR);
        if (trim($doc->title) === '') {
            $problems[] = 'no title';
        }

        $description = null;
        foreach ($doc->querySelectorAll('meta[name]') as $meta) {
            $name = strtolower((string) $meta->getAttribute('name'));
            $content = trim((string) $meta->getAttribute('content'));
            if ($name === 'description') {
                $description ??= $content;
            } elseif ($name === 'robots' && str_contains(strtolower($content), 'noindex')) {
                $problems[] = 'noindex in meta robots';
            }
        }
        if ((string) $description === '') {
            $problems[] = 'no meta description';
        }

        $canonical = false;
        foreach ($doc->querySelectorAll('link[rel][href]') as $link) {
            $rel = preg_split('/\s+/', strtolower((string) $link->getAttribute('rel'))) ?: [];
            $canonical = $canonical || (in_array('canonical', $rel, true) && trim((string) $link->getAttribute('href')) !== '');
        }
        if (!$canonical) {
            $problems[] = 'no canonical';
        }

        $h1 = $doc->querySelectorAll('h1')->length;
        if ($h1 !== 1) {
            $problems[] = $h1 . ' h1';
        }

        foreach ($doc->querySelectorAll('script[type]') as $script) {
            if (strtolower(trim((string) $script->getAttribute('type'))) === 'application/ld+json') {
                json_decode((string) $script->textContent);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $problems[] = 'JSON-LD does not parse: ' . json_last_error_msg();
                }
            }
        }

        return $problems;
    }

    /** @return list<string>|string the page addresses, or why there are none */
    private static function sitemapPages(HttpClient $http, string $sitemap): array|string
    {
        $response = self::get($http, $sitemap);
        if ($response['status'] !== 200) {
            return $response['error'] ?? 'HTTP ' . $response['status'];
        }

        $doc = new \DOMDocument();
        if (!@$doc->loadXML((string) $response['body'], LIBXML_NONET)) {
            return 'not XML';
        }
        $pages = [];
        foreach ($doc->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'url') as $url) {
            foreach ($url->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'loc') as $loc) {
                $pages[] = trim($loc->textContent);
            }
        }
        return $pages !== [] ? $pages : 'no <url> entries';
    }

    /** @return array{status: int, headers: array<string, string>, error: string|null, body: mixed} */
    private static function get(HttpClient $http, string $url): array
    {
        return $http->get($url, null, ['decodeJson' => false, 'log' => false]);
    }

    /** 'scheme://host[:port]' of $url, '' when it has none. */
    private static function origin(string $url): string
    {
        return preg_match('~^[a-z][a-z0-9+.-]*://[^/?#]*~i', $url, $m) ? $m[0] : '';
    }

    /** @param array<string, string> $headers */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return '';
    }
}
