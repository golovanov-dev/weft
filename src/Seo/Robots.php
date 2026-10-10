<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\Config\Config;
use Golovanov\Weft\I18n\Url;

/**
 * robots.txt generator. Blocks everything in DEBUG or with app.seo.robots_allow off, allows all
 * otherwise, and then advertises /sitemap.xml when app.sitemap is set: a non-empty list or a
 * callable. The callable is not called here: robots.txt is fetched on every crawl, and a generator
 * that throws would turn it into a 500, which crawlers read as "the whole site is closed".
 *
 * app.seo.robots_groups adds a group per entry: `agents` (user-agent tokens or names of PRESETS),
 * `allow` and `disallow` (paths). A crawler obeys only the group that names it, so every group
 * also gets app.seo.robots_disallow.
 */
final class Robots
{
    /**
     * Crawlers of AI services by what they fetch for, as their operators document them:
     * `ai-search` builds the service's search index, `ai-user` fetches a page a user asked about,
     * `ai-training` collects training data (Google-Extended and Applebot-Extended are tokens only).
     */
    public const array PRESETS = [
        'ai-search' => ['OAI-SearchBot', 'Claude-SearchBot', 'PerplexityBot', 'YandexAdditional'],
        'ai-user' => ['ChatGPT-User', 'Claude-User', 'Perplexity-User'],
        'ai-training' => ['GPTBot', 'ClaudeBot', 'Google-Extended', 'Applebot-Extended', 'CCBot'],
    ];

    /**
     * Names in the groups' `agents` that look like a preset (`ai-…`, `ai_…`, any case) and are not
     * one: a typo would become a user-agent no crawler has. robots.txt lists them as they are.
     *
     * @return list<string>
     */
    public static function unknownPresets(): array
    {
        $unknown = [];
        foreach ((array) Config::get('app.seo.robots_groups', []) as $group) {
            foreach ((array) (((array) $group)['agents'] ?? []) as $agent) {
                $agent = (string) $agent;
                if (preg_match('/^ai[-_]/i', $agent) && !isset(self::PRESETS[$agent])) {
                    $unknown[] = $agent;
                }
            }
        }
        return array_values(array_unique($unknown));
    }

    public static function text(): string
    {
        $debug = (bool) Config::env('DEBUG', false);
        $allow = !$debug && (bool) Config::get('app.seo.robots_allow', true);
        $disallow = array_map('strval', (array) Config::get('app.seo.robots_disallow', []));

        $lines = ['User-agent: *'];
        $lines[] = $allow ? 'Allow: /' : 'Disallow: /';
        foreach ($disallow as $path) {
            $lines[] = 'Disallow: ' . $path;
        }

        // A closed site is closed for every crawler: a group would reopen it for the bots it names.
        if ($allow) {
            foreach ((array) Config::get('app.seo.robots_groups', []) as $group) {
                $lines = [...$lines, ...self::group((array) $group, $disallow)];
            }
        }

        $sitemap = Config::get('app.sitemap', []);
        if ($allow && (is_callable($sitemap) || (array) $sitemap !== [])) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . Url::origin() . Url::asset('sitemap.xml');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $group
     * @param list<string> $disallow paths closed to every crawler
     * @return list<string> the group's lines with a blank line before it, or none without agents
     */
    private static function group(array $group, array $disallow): array
    {
        $agents = [];
        foreach ((array) ($group['agents'] ?? []) as $agent) {
            $agents = [...$agents, ...(self::PRESETS[(string) $agent] ?? [(string) $agent])];
        }
        if ($agents === []) {
            return [];
        }

        $lines = [''];
        foreach (array_unique($agents) as $agent) {
            $lines[] = 'User-agent: ' . $agent;
        }
        $rules = [];
        foreach ((array) ($group['allow'] ?? []) as $path) {
            $rules[] = 'Allow: ' . $path;
        }
        foreach ([...(array) ($group['disallow'] ?? []), ...$disallow] as $path) {
            $rules[] = 'Disallow: ' . $path;
        }
        // A group without rules would end at the next User-agent line and merge into that group.
        return [...$lines, ...($rules === [] ? ['Allow: /'] : array_unique($rules))];
    }
}
