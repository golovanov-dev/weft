<?php

declare(strict_types=1);

namespace Golovanov\Weft\Seo;

use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Observability\ErrorHandler;

/**
 * JSON-LD builders: each checks the fields a search engine needs and adds the block through
 * Seo::jsonLd(). Fields use schema.org names; '@type' may name a subtype (BlogPosting, Restaurant).
 *
 * Required fields (Google rich results; schema.org for Organization and WebSite):
 *   organization()   name, url
 *   localBusiness()  name, address
 *   webSite()        name, url
 *   article()        headline (Google requires none; a block without it says nothing)
 *   product()        name, and offers, review or aggregateRating; each offer price, priceCurrency
 *   faq()            at least one question with an answer
 *   breadcrumbs()    at least one crumb; every crumb a name, and a URL except the last one
 *                    (absolute, or a link from url() — only the scheme and host are added)
 *
 * A missing (null, '' or []) required field: in DEBUG an InvalidArgumentException; otherwise the
 * block is left out, a line goes to errors/, and the builder returns false.
 */
final class Schema
{
    /** @param array<string, mixed> $fields */
    public static function organization(array $fields): bool
    {
        return self::add('Organization', $fields, ['name', 'url']);
    }

    /** @param array<string, mixed> $fields */
    public static function localBusiness(array $fields): bool
    {
        return self::add('LocalBusiness', $fields, ['name', 'address']);
    }

    /** @param array<string, mixed> $fields */
    public static function webSite(array $fields): bool
    {
        return self::add('WebSite', $fields, ['name', 'url']);
    }

    /** @param array<string, mixed> $fields */
    public static function article(array $fields): bool
    {
        return self::add('Article', $fields, ['headline']);
    }

    /**
     * offers: one offer or a list of them, as schema.org Offer fields ('@type' defaults to Offer).
     *
     * @param array<string, mixed> $fields
     */
    public static function product(array $fields): bool
    {
        if (self::blank($fields['offers'] ?? null) && self::blank($fields['review'] ?? null) && self::blank($fields['aggregateRating'] ?? null)) {
            return self::invalid('Product', 'offers, review or aggregateRating');
        }
        if (!self::blank($fields['offers'] ?? null)) {
            $offers = is_array($fields['offers']) && array_is_list($fields['offers']) ? $fields['offers'] : [$fields['offers']];
            foreach ($offers as $i => $offer) {
                $offer = is_array($offer) ? $offer : [];
                foreach (['price', 'priceCurrency'] as $key) {
                    if (self::blank($offer[$key] ?? null)) {
                        return self::invalid('Product', 'offers[' . $i . '].' . $key);
                    }
                }
                $offers[$i] = array_merge(['@type' => 'Offer'], $offer);
            }
            $fields['offers'] = count($offers) === 1 ? $offers[0] : $offers;
        }
        return self::add('Product', $fields, ['name']);
    }

    /**
     * FAQPage from [question => answer] or a list of [question, answer] pairs.
     *
     * @param array<array-key, mixed> $questions
     */
    public static function faq(array $questions): bool
    {
        $entities = [];
        foreach (self::pairs($questions) as $i => [$question, $answer]) {
            if (self::blank($question) || self::blank($answer)) {
                return self::invalid('FAQPage', 'question or answer ' . ($i + 1));
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }
        return self::add('FAQPage', ['mainEntity' => $entities], ['mainEntity']);
    }

    /**
     * BreadcrumbList from [name => url] or a list of [name, url] pairs, from the top down. A URL is
     * absolute or a site link as url() returns it (base URI and locale prefix already in it): it gets
     * the scheme and host of app.url.base and nothing else, so url('/blog') in the ru locale is
     * https://example.com/ru/blog. The last crumb, the current page, may have no URL.
     *
     * @param array<array-key, mixed> $crumbs
     */
    public static function breadcrumbs(array $crumbs): bool
    {
        $pairs = self::pairs($crumbs);
        $items = [];
        foreach ($pairs as $i => [$name, $url]) {
            $last = $i === count($pairs) - 1;
            if (self::blank($name) || (!$last && self::blank($url))) {
                return self::invalid('BreadcrumbList', 'name or URL of crumb ' . ($i + 1));
            }
            $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name];
            if (!self::blank($url)) {
                $url = (string) $url;
                $item['item'] = Url::full($url);
            }
            $items[] = $item;
        }
        return self::add('BreadcrumbList', ['itemListElement' => $items], ['itemListElement']);
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $required
     */
    private static function add(string $type, array $fields, array $required): bool
    {
        $fields = array_merge(['@type' => $type], $fields);
        foreach ($required as $key) {
            if (self::blank($fields[$key] ?? null)) {
                return self::invalid((string) $fields['@type'], $key);
            }
        }
        Seo::jsonLd($fields);
        return true;
    }

    /** @return list<array{0: mixed, 1: mixed}> */
    private static function pairs(array $items): array
    {
        $pairs = [];
        foreach ($items as $key => $value) {
            $pairs[] = array_is_list($items) && is_array($value) ? [$value[0] ?? null, $value[1] ?? null] : [(string) $key, $value];
        }
        return $pairs;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function invalid(string $type, string $field): bool
    {
        $message = sprintf('JSON-LD %s left out: missing %s', $type, $field);
        if (ErrorHandler::debug()) {
            throw new \InvalidArgumentException($message);
        }
        // Where: the template that called the builder.
        $caller = ['file' => '', 'line' => 0];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['file'] ?? __FILE__) !== __FILE__) {
                $caller = $frame;
                break;
            }
        }
        ErrorHandler::log('schema', 0, $message, (string) $caller['file'], (int) ($caller['line'] ?? 0));
        return false;
    }
}
