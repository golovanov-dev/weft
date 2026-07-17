<?php

declare(strict_types=1);

namespace App\Services;

use Golovanov\Weft\Cache\Cache;
use Golovanov\Weft\I18n\Locale;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Reads documentation chapters from Markdown files and renders them to HTML.
 *
 * This is the business logic of the docs site: pages only ask for data and print it. It also
 * shows two things Weft sites do a lot:
 *   - Cache::remember() around expensive work (Markdown parsing) — safe even with caching off;
 *   - content as files, so a "database" is not needed for a simple site.
 *
 * Chapter files live in content/<locale>/<slug>.md and start with a small front matter block:
 *
 *   ---
 *   title: Getting Started
 *   description: Install Weft and get a site running in five minutes.
 *   order: 1
 *   ---
 */
final class DocsService
{
    private const CACHE_TTL = 3600;

    private string $dir;

    public function __construct(?string $locale = null)
    {
        $locale = $locale ?? Locale::current();
        $this->dir = APP_ROOT . '/content/' . $locale;
    }

    /**
     * All chapters, ordered, without the rendered body.
     *
     * @return array<int, array{slug: string, title: string, description: string, order: int}>
     */
    public function all(): array
    {
        $files = glob($this->dir . '/*.md') ?: [];

        // The cache key carries the newest mtime, so editing a file invalidates it automatically.
        $stamp = 0;
        foreach ($files as $file) {
            $stamp = max($stamp, (int) filemtime($file));
        }

        return Cache::remember('docs:index:' . basename($this->dir) . ':' . $stamp, self::CACHE_TTL, function () use ($files): array {
            $chapters = [];
            foreach ($files as $file) {
                $parsed = $this->parse($file);
                $chapters[] = [
                    'slug' => basename($file, '.md'),
                    'title' => $parsed['meta']['title'] ?? basename($file, '.md'),
                    'description' => $parsed['meta']['description'] ?? '',
                    'order' => (int) ($parsed['meta']['order'] ?? 999),
                ];
            }
            usort($chapters, static fn(array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']]);
            return $chapters;
        });
    }

    /**
     * A single chapter with its rendered HTML body, or null if the slug does not exist.
     *
     * @return array{slug: string, title: string, description: string, order: int, html: string}|null
     */
    public function find(string $slug): ?array
    {
        if (preg_match('/^[a-z0-9][a-z0-9\-]*$/', $slug) !== 1) {
            return null;   // slug comes from the URL — keep it to a safe shape
        }

        $file = $this->dir . '/' . $slug . '.md';
        if (!is_file($file)) {
            return null;
        }

        $stamp = (int) filemtime($file);

        return Cache::remember('docs:page:' . $slug . ':' . $stamp, self::CACHE_TTL, function () use ($file, $slug): array {
            $parsed = $this->parse($file);
            return [
                'slug' => $slug,
                'title' => $parsed['meta']['title'] ?? $slug,
                'description' => $parsed['meta']['description'] ?? '',
                'order' => (int) ($parsed['meta']['order'] ?? 999),
                'html' => $this->render($parsed['body']),
            ];
        });
    }

    /**
     * Previous/next chapters for in-page navigation.
     *
     * @return array{prev: ?array<string, mixed>, next: ?array<string, mixed>}
     */
    public function neighbours(string $slug): array
    {
        $all = $this->all();
        foreach ($all as $i => $chapter) {
            if ($chapter['slug'] === $slug) {
                return ['prev' => $all[$i - 1] ?? null, 'next' => $all[$i + 1] ?? null];
            }
        }
        return ['prev' => null, 'next' => null];
    }

    /**
     * Routes for sitemap.xml — the docs site's URLs come from content, not from a static list.
     *
     * @return array<int, array{path: string, changefreq: string, priority: string}>
     */
    public function sitemapRoutes(): array
    {
        $routes = [
            ['path' => '/', 'changefreq' => 'weekly', 'priority' => '1.0'],
            ['path' => '/docs', 'changefreq' => 'weekly', 'priority' => '0.9'],
        ];
        foreach ($this->all() as $chapter) {
            $routes[] = ['path' => '/docs/' . $chapter['slug'], 'changefreq' => 'monthly', 'priority' => '0.8'];
        }
        return $routes;
    }

    /**
     * Split the front matter block from the Markdown body.
     *
     * Deliberately tiny: a few "key: value" lines is all the docs need, and it keeps the example
     * free of a YAML dependency.
     *
     * @return array{meta: array<string, string>, body: string}
     */
    private function parse(string $file): array
    {
        $raw = (string) file_get_contents($file);
        $meta = [];

        if (preg_match('/^---\R(.*?)\R---\R(.*)$/s', $raw, $m) === 1) {
            foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
                $pos = strpos($line, ':');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                $meta[$key] = trim($value, "\"'");
            }
            $raw = $m[2];
        }

        return ['meta' => $meta, 'body' => $raw];
    }

    private function render(string $markdown): string
    {
        $environment = new Environment([
            'html_input' => 'escape',          // the docs are trusted, but escaping raw HTML costs nothing
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new AutolinkExtension());

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }
}
