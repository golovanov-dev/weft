<?php

declare(strict_types=1);

namespace Golovanov\Weft\View;

/**
 * Plain-PHP view renderer. Templates are ordinary .php files; logic stays in services/handlers,
 * templates only present. Data is passed in and extracted into local scope; global helpers
 * (t(), e(), url(), seo(), ...) are available inside every template.
 */
final class View
{
    /** @var array<string, mixed> */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Render a template file to a string in an isolated scope.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $file, array $data = []): string
    {
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $file);
        }

        $render = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            include $__file;
            return (string) ob_get_clean();
        };

        return $render($file, array_merge(self::$shared, $data));
    }

    /**
     * Render a page and wrap it in a layout. The layout receives $content plus shared/page data.
     *
     * @param array<string, mixed> $data
     */
    public static function page(string $pageFile, ?string $layoutFile, array $data = []): string
    {
        $content = self::render($pageFile, $data);
        if ($layoutFile === null || !is_file($layoutFile)) {
            return $content;
        }
        return self::render($layoutFile, array_merge($data, ['content' => $content]));
    }
}
