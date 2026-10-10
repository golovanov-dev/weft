<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Support\Sanitize;

/**
 * SEO under the hood.
 *
 * A page sets a few values (title, description, maybe an image or JSON-LD); the engine renders
 * a complete, correct <head>: title (with template), meta description, canonical, robots,
 * Open Graph, Twitter card, hreflang alternates for every locale, and JSON-LD blocks.
 *
 * Defaults come from config('app.seo'); pages override only what they need:
 *
 *   Seo::set(['title' => 'About', 'description' => '...']);
 *   Seo::jsonLd([...]);            // optional extra structured data (or a Schema:: builder)
 *   echo Seo::render();            // in the layout <head>
 */
final class Seo
{
    /** @var array<string, mixed> */
    private static array $data = [];

    /** @var array<int, array<string, mixed>> */
    private static array $jsonLd = [];

    private static bool $booted = false;

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$data = [
            'title' => null,
            'description' => (string) Config::get('app.seo.description', ''),
            'canonical' => null,               // null: from the matched route (none on a 404)
            'robots' => (string) Config::get('app.seo.robots', 'index, follow'),
            'image' => (string) Config::get('app.seo.og_image', ''),
            'image_alt' => null,               // null = not set by the page, see render()
            'image_width' => (string) Config::get('app.seo.og_image_width', ''),
            'image_height' => (string) Config::get('app.seo.og_image_height', ''),
            'type' => 'website',
            'alternates' => true,              // emit hreflang links
            'page' => 1,                       // set by Pagination: ?page=N in canonical, suffix in title
            'page_suffix' => (string) Config::get('app.seo.page_suffix', ' — Page %d'),
        ];
        // Default site-wide structured data (e.g. WebSite / Person) from config.
        $default = Config::get('app.seo.json_ld', []);
        if (is_array($default) && $default !== []) {
            // Allow either a single schema or a list of them.
            if (isset($default['@context']) || isset($default['@type'])) {
                self::$jsonLd[] = $default;
            } else {
                foreach ($default as $schema) {
                    if (is_array($schema)) {
                        self::$jsonLd[] = $schema;
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $attrs */
    public static function set(array $attrs): void
    {
        self::boot();
        self::$data = array_merge(self::$data, $attrs);
    }

    public static function title(string $title): void
    {
        self::set(['title' => $title]);
    }

    public static function description(string $description): void
    {
        self::set(['description' => $description]);
    }

    public static function noindex(): void
    {
        self::set(['robots' => 'noindex, nofollow']);
    }

    /**
     * @internal The Kernel drops what a page had set when the page ends with Abort::notFound().
     */
    public static function reset(): void
    {
        self::$booted = false;
        self::$data = [];
        self::$jsonLd = [];
    }

    /** @param array<string, mixed> $schema */
    public static function jsonLd(array $schema): void
    {
        self::boot();
        self::$jsonLd[] = $schema;
    }

    public static function documentTitle(): string
    {
        self::boot();
        $title = self::$data['title'];
        $default = (string) Config::get('app.seo.default_title', (string) Config::get('app.seo.site_name', ''));
        // Page 2 and later of a list: "Blog — Page 2", before the title template. A suffix without
        // %d gets the number after a space, so pages 2, 3, ... never share one title.
        $page = (int) self::$data['page'];
        $suffix = '';
        if ($page >= 2) {
            $suffix = (string) self::$data['page_suffix'];
            $suffix = str_contains($suffix, '%d') ? str_replace('%d', (string) $page, $suffix) : $suffix . ' ' . $page;
        }
        if ($title === null || $title === '') {
            return $default . $suffix;
        }
        $template = (string) Config::get('app.seo.title_template', '%s');
        $title = $title . $suffix;
        return str_contains($template, '%s') ? sprintf($template, $title) : $title;
    }

    public static function render(): string
    {
        self::boot();
        $e = static fn(mixed $v): string => Sanitize::output($v);

        $title = self::documentTitle();
        $description = (string) self::$data['description'];
        // Default canonical, og:url and hreflang come from the route the Kernel matched, never from
        // the raw request URI; a 404 has no route and gets none of them unless the page sets one.
        // A page that answers 404 itself (a dynamic page with an unknown slug) counts as a 404.
        $path = http_response_code() === 404 ? null : Url::routePath();
        // The canonical a page set is always absolute, as og:url must be: a link from url() gets
        // the scheme and host (Url::full()).
        $set = (string) self::$data['canonical'];
        $canonical = $set !== '' ? Url::full($set) : ($path !== null ? Url::absolute($path) : '');
        // Page 2 and later of a list is its own canonical page: page=N goes onto the canonical the
        // page set (in place of a page= it had, before a #fragment) or onto the route, which has
        // no other parameters; hreflang always comes from the route, with the same ?page=N.
        $page = (int) self::$data['page'];
        if ($path !== null && $page >= 2) {
            $canonical = self::withPage($canonical, $page);
            $path .= '?page=' . $page;
        }
        $robots = (string) self::$data['robots'];
        $image = (string) self::$data['image'];
        $type = (string) self::$data['type'];
        $siteName = (string) Config::get('app.seo.site_name', '');

        $out = [];
        $out[] = '<title>' . $e($title) . '</title>';
        if ($description !== '') {
            $out[] = '<meta name="description" content="' . $e($description) . '" />';
        }
        $out[] = '<meta name="robots" content="' . $e($robots) . '" />';
        if ($canonical !== '') {
            $out[] = '<link rel="canonical" href="' . $e($canonical) . '" />';
        }

        // Open Graph.
        $out[] = '<meta property="og:type" content="' . $e($type) . '" />';
        $out[] = '<meta property="og:title" content="' . $e($title) . '" />';
        if ($description !== '') {
            $out[] = '<meta property="og:description" content="' . $e($description) . '" />';
        }
        if ($canonical !== '') {
            $out[] = '<meta property="og:url" content="' . $e($canonical) . '" />';
        }
        if ($siteName !== '') {
            $out[] = '<meta property="og:site_name" content="' . $e($siteName) . '" />';
        }
        if ($image !== '') {
            $out[] = '<meta property="og:image" content="' . $e(self::absoluteImage($image)) . '" />';

            $imageAlt = self::$data['image_alt'];
            if ($imageAlt === null) {
                // The configured alt describes the configured image. A page pointing og:image
                // somewhere else without giving its own alt gets none, rather than a caption
                // written for a different picture.
                $imageAlt = $image === (string) Config::get('app.seo.og_image', '')
                    ? (string) Config::get('app.seo.og_image_alt', '')
                    : '';
            }
            if ((string) $imageAlt !== '') {
                $out[] = '<meta property="og:image:alt" content="' . $e($imageAlt) . '" />';
            }

            // Dimensions let a scraper lay out the large card before it has fetched the image.
            // Both or neither: one on its own tells a scraper nothing it can use. Configure them
            // only if every OG image on the site is rendered at that size, and let a page with a
            // differently sized image override or clear them.
            $width = (string) self::$data['image_width'];
            $height = (string) self::$data['image_height'];
            if ($width !== '' && $height !== '') {
                $out[] = '<meta property="og:image:width" content="' . $e($width) . '" />';
                $out[] = '<meta property="og:image:height" content="' . $e($height) . '" />';
            }
        }

        // Twitter.
        $out[] = '<meta name="twitter:card" content="' . ($image !== '' ? 'summary_large_image' : 'summary') . '" />';
        $twitter = (string) Config::get('app.seo.twitter', '');
        if ($twitter !== '') {
            $out[] = '<meta name="twitter:site" content="' . $e($twitter) . '" />';
        }

        // hreflang alternates.
        if (self::$data['alternates'] && Locale::enabled() && $path !== null) {
            foreach (Locale::supported() as $locale) {
                $out[] = '<link rel="alternate" hreflang="' . $e($locale) . '" href="' . $e(Url::absolute($path, $locale)) . '" />';
            }
            $out[] = '<link rel="alternate" hreflang="x-default" href="' . $e(Url::absolute($path, Locale::default())) . '" />';
        }

        // JSON-LD.
        foreach (self::$jsonLd as $schema) {
            $schema = array_merge(['@context' => 'https://schema.org'], $schema);
            // JSON_HEX_TAG writes < and > as \u003C and \u003E: a value holding "</script>" or "<!--"
            // cannot end the script element early.
            $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_HEX_TAG);
            if ($json !== false) {
                $out[] = '<script type="application/ld+json">' . $json . '</script>';
            }
        }

        return implode("\n  ", $out) . "\n";
    }

    /** $url with page=N in its query, in place of any page= it had, and before its #fragment. */
    private static function withPage(string $url, int $page): string
    {
        [$url, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
        $params = array_filter(
            explode('&', $query),
            static fn(string $pair): bool => $pair !== '' && urldecode(explode('=', $pair, 2)[0]) !== 'page',
        );
        $params[] = 'page=' . $page;
        return $base . '?' . implode('&', $params) . ($fragment !== null ? '#' . $fragment : '');
    }

    private static function absoluteImage(string $image): string
    {
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }
        return Url::origin() . Url::asset($image);
    }
}
