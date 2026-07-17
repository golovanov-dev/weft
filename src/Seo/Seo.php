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
 *   Seo::jsonLd([...]);            // optional extra structured data
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
            'canonical' => null,               // auto from current path when null
            'robots' => (string) Config::get('app.seo.robots', 'index, follow'),
            'image' => (string) Config::get('app.seo.og_image', ''),
            'type' => 'website',
            'alternates' => true,              // emit hreflang links
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
        if ($title === null || $title === '') {
            return $default;
        }
        $template = (string) Config::get('app.seo.title_template', '%s');
        return str_contains($template, '%s') ? sprintf($template, (string) $title) : (string) $title;
    }

    public static function render(): string
    {
        self::boot();
        $e = static fn(mixed $v): string => Sanitize::output($v);

        $title = self::documentTitle();
        $description = (string) self::$data['description'];
        $canonical = self::$data['canonical'] ?: Url::absolute(Url::currentPath());
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
        $out[] = '<link rel="canonical" href="' . $e($canonical) . '" />';

        // Open Graph.
        $out[] = '<meta property="og:type" content="' . $e($type) . '" />';
        $out[] = '<meta property="og:title" content="' . $e($title) . '" />';
        if ($description !== '') {
            $out[] = '<meta property="og:description" content="' . $e($description) . '" />';
        }
        $out[] = '<meta property="og:url" content="' . $e($canonical) . '" />';
        if ($siteName !== '') {
            $out[] = '<meta property="og:site_name" content="' . $e($siteName) . '" />';
        }
        if ($image !== '') {
            $out[] = '<meta property="og:image" content="' . $e(self::absoluteImage($image)) . '" />';
        }

        // Twitter.
        $out[] = '<meta name="twitter:card" content="' . ($image !== '' ? 'summary_large_image' : 'summary') . '" />';
        $twitter = (string) Config::get('app.seo.twitter', '');
        if ($twitter !== '') {
            $out[] = '<meta name="twitter:site" content="' . $e($twitter) . '" />';
        }

        // hreflang alternates.
        if (self::$data['alternates'] && Locale::enabled()) {
            $path = Url::currentPath();
            foreach (Locale::supported() as $locale) {
                $out[] = '<link rel="alternate" hreflang="' . $e($locale) . '" href="' . $e(Url::absolute($path, $locale)) . '" />';
            }
            $out[] = '<link rel="alternate" hreflang="x-default" href="' . $e(Url::absolute($path, Locale::default())) . '" />';
        }

        // JSON-LD.
        foreach (self::$jsonLd as $schema) {
            $schema = array_merge(['@context' => 'https://schema.org'], $schema);
            $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if ($json !== false) {
                $out[] = '<script type="application/ld+json">' . $json . '</script>';
            }
        }

        return implode("\n  ", $out) . "\n";
    }

    private static function absoluteImage(string $image): string
    {
        if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
            return $image;
        }
        return Url::origin() . '/' . ltrim($image, '/');
    }
}
