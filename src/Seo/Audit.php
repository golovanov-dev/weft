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
 * Each page must answer 200 (redirects are not followed) with HTML, a title, a meta description,
 * a canonical link, exactly one h1, JSON-LD that parses, and no noindex (or none) for every bot
 * or for googlebot in meta robots, meta googlebot or X-Robots-Tag. A sitemap index is not read. Needs ext-dom.
 *
 * An address with an @ anywhere (it may hold a user name and password) is refused: Basic auth comes from the environment,
 * WEFT_AUDIT_AUTH=user:pass, so it is not in the command line, the shell history or a CI log, and the
 * header goes only to the origins given on the command line.
 */
final class Audit
{
    private const int LIMIT = 500;

    /** @var array<string, string> origin => Authorization header from WEFT_AUDIT_AUTH; set by run() */
    private static array $auth = [];

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
                if (str_contains($arg, '@') || self::origin($arg) === '') {
                    // Not printed: an @ anywhere, or an authority that is not a host[:port], may hide a password.
                    fwrite($err, "weft-seo-audit: an address must be http(s)://host[:port]/... without @ (write %40); a user name and password go in WEFT_AUDIT_AUTH=user:pass.\n" . $usage);
                    return 2;
                }
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
        $auth = (string) getenv('WEFT_AUDIT_AUTH');
        self::$auth = $auth === '' ? [] : array_fill_keys(
            array_map(static fn(string $url): string => strtolower(self::origin($url)), $targets),
            'Authorization: Basic ' . base64_encode($auth),
        );
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

        $type = self::header($response['headers'], 'Content-Type');
        if ($type !== '' && !str_contains(strtolower($type), 'html')) {
            return ['not HTML: Content-Type ' . $type];
        }

        $problems = [];
        if (self::noindex(self::header($response['headers'], 'X-Robots-Tag'))) {
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
            } elseif (in_array($name, ['robots', 'googlebot'], true) && self::noindex($content)) {
                $problems[] = 'noindex in meta ' . $name;
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
        if ($doc->documentElement?->localName === 'sitemapindex') {
            return 'a sitemap index, which the audit does not read; list the pages with --pages';
        }
        $pages = [];
        foreach ($doc->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'url') as $url) {
            foreach ($url->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'loc') as $loc) {
                if (trim($loc->textContent) !== '') {
                    $pages[] = trim($loc->textContent);
                }
            }
        }
        return $pages !== [] ? $pages : 'no <url> entries';
    }

    /** @return array{status: int, headers: array<string, string>, error: string|null, body: mixed} */
    private static function get(HttpClient $http, string $url): array
    {
        try {
            $auth = self::$auth[strtolower(self::origin($url))] ?? null;
            return $http->get($url, null, ['decodeJson' => false, 'log' => false, 'headers' => $auth === null ? [] : [$auth]]);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 0, 'headers' => [], 'error' => $e->getMessage(), 'body' => null];
        }
    }

    /**
     * Whether a robots meta or X-Robots-Tag value closes the page to Google: a noindex or none
     * directive for every bot or for googlebot ("bingbot: noindex" and "max-image-preview:none" do not).
     */
    private static function noindex(string $value): bool
    {
        $bot = '';
        foreach (explode(',', strtolower($value)) as $item) {
            // "<bot>: directives" names the bot of what follows; "max-snippet:0" and the like are directives.
            if (preg_match('/^\s*([a-z0-9_.-]+)\s*:(.*)$/s', $item, $m)) {
                if (in_array($m[1], ['max-snippet', 'max-image-preview', 'max-video-preview', 'unavailable_after'], true)) {
                    continue;
                }
                [$bot, $item] = [$m[1], $m[2]];
            }
            $words = preg_split('/\s+/', trim($item)) ?: [];
            if (in_array($bot, ['', 'googlebot'], true) && array_intersect($words, ['noindex', 'none']) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * 'scheme://host[:port]' of $url, '' when it has none or its authority is not a host[:port]
     * (a user name or password).
     */
    private static function origin(string $url): string
    {
        return preg_match('~^[a-z][a-z0-9+.-]*://(?:\[[0-9a-f:.]+\]|[^/?#@:\[\]]+)(?::[0-9]*)?(?=[/?#]|$)~iD', $url, $m) ? $m[0] : '';
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
