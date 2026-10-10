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

        $lines = ['# ' . (string) ($llms['title'] ?? Config::get('app.seo.site_name', ''))];
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
            $lines[] = '## ' . $name;
            $lines[] = '';
            foreach ((array) $links as $link) {
                $link = (array) $link;
                $url = (string) ($link['url'] ?? '');
                $url = preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) ? $url : Url::absolute($url);
                $line = '- [' . (string) ($link['title'] ?? $url) . '](' . $url . ')';
                if ((string) ($link['notes'] ?? '') !== '') {
                    $line .= ': ' . $link['notes'];
                }
                $lines[] = $line;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
