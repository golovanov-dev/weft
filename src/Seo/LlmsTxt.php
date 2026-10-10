<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Url;

/**
 * /llms.txt in the llmstxt.org format: an H1, a blockquote summary, optional text, then H2
 * sections listing links.
 *
 * Config 'app.llms' is an array or a callable returning one, called only when /llms.txt is
 * requested:
 *
 *   'llms' => [
 *       'title' => 'Acme',                    // default: app.seo.site_name
 *       'summary' => 'What the site is about, in one or two sentences.',
 *       'details' => "Any Markdown: who it is for, how to read it.",
 *       'sections' => [
 *           'Docs' => [['url' => '/start', 'title' => 'Getting started', 'notes' => 'Install and run']],
 *           'Optional' => [['url' => 'https://example.org/blog', 'title' => 'Blog']],
 *       ],
 *   ],
 *
 * A link without a scheme is a path of the site and becomes absolute, with BASE_URI and locale.
 */
final class LlmsTxt
{
    /** Whether the site configures llms.txt; without it /llms.txt is a 404. */
    public static function enabled(): bool
    {
        return Config::get('app.llms') !== null;
    }

    public static function text(): string
    {
        $llms = Config::get('app.llms', []);
        if (is_callable($llms)) {
            $llms = $llms();
        }
        $llms = (array) $llms;

        $lines = ['# ' . self::line($llms['title'] ?? Config::get('app.seo.site_name', ''))];
        if ((string) ($llms['summary'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = '> ' . str_replace("\n", "\n> ", trim((string) $llms['summary']));
        }
        if ((string) ($llms['details'] ?? '') !== '') {
            $lines[] = '';
            $lines[] = trim((string) $llms['details']);
        }

        foreach ((array) ($llms['sections'] ?? []) as $name => $links) {
            $lines[] = '';
            $lines[] = '## ' . self::line($name);
            $lines[] = '';
            foreach ((array) $links as $link) {
                $link = (array) $link;
                $url = self::line($link['url'] ?? '');
                $url = preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) ? $url : Url::absolute($url);
                $line = '- [' . self::line($link['title'] ?? $url) . '](' . $url . ')';
                $notes = self::line($link['notes'] ?? '');
                if ($notes !== '') {
                    $line .= ': ' . $notes;
                }
                $lines[] = $line;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * A heading, link or note on one line: a line break from the config (or from content the
     * callable read) would start a heading or list item of its own. summary and details are
     * multi-line on purpose.
     */
    private static function line(mixed $value): string
    {
        return trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', (string) $value));
    }
}
