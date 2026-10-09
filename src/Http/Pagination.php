<?php

declare(strict_types=1);

namespace Golovanov\Weft\Http;

use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Seo\Seo;

/**
 * Page numbers for a list split over ?page=N. Data only: the page draws its own links.
 *
 *   $pages = Pagination::fromRequest($request, count($posts), 10);
 *   foreach (array_slice($posts, $pages->offset, $pages->limit) as $post) { ... }
 *   if ($pages->next !== null) echo '<a href="' . e($pages->next) . '">Next</a>';
 *
 * Page 1 has no ?page: ?page=1 is a 301 to the address without it (other parameters kept). A
 * value that is not a page number (1, 2, ... without leading zeros) or past the last page is a
 * 404 (Abort: the 404 page, or the JSON 404 in an api/ endpoint). An empty list has page 1. Page 2 and later get a title suffix
 * (app.seo.page_suffix) and their own canonical, ?page=N.
 */
final class Pagination
{
    private function __construct(
        /** Current page, from 1. */
        public readonly int $page,
        /** Number of pages, at least 1. */
        public readonly int $pages,
        /** Number of items in the whole list. */
        public readonly int $total,
        /** Items to skip and to show on this page. */
        public readonly int $offset,
        public readonly int $limit,
        /** Site-relative links (base URI and locale prefix included); null on the first / last page. */
        public readonly ?string $prev,
        public readonly ?string $next,
        /** The request's query without page: kept in links to other pages. */
        private readonly string $query,
    ) {
    }

    /** @throws Abort a 301 for ?page=1, a 404 for a page that does not exist */
    public static function fromRequest(Request $request, int $total, int $perPage): self
    {
        if ($total < 0 || $perPage < 1) {
            throw new \InvalidArgumentException('Pagination needs $total >= 0 and $perPage >= 1');
        }

        // The query as sent, minus page=..., so other parameters keep their spelling and order.
        $start = strpos($request->uri, '?');
        $raw = $start === false ? '' : explode('#', substr($request->uri, $start + 1), 2)[0];
        $kept = [];
        $values = [];
        foreach ($raw === '' ? [] : explode('&', $raw) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            if (urldecode($key) === 'page') {
                $values[] = $value === null ? '' : urldecode($value);
            } elseif ($pair !== '') {
                $kept[] = $pair;
            }
        }
        $query = implode('&', $kept);
        $pages = max(1, intdiv($total + $perPage - 1, $perPage));

        $page = 1;
        if ($values !== []) {
            $value = end($values);
            if (preg_match('/^[1-9][0-9]{0,8}$/', $value) !== 1 || (int) $value > $pages) {
                throw Abort::notFound();
            }
            $page = (int) $value;
            if ($page === 1) {
                throw Abort::redirect(self::link($page, $query));
            }
        }

        Seo::set(['page' => $page]);

        return new self(
            page: $page,
            pages: $pages,
            total: $total,
            offset: ($page - 1) * $perPage,
            limit: $perPage,
            prev: $page > 1 ? self::link($page - 1, $query) : null,
            next: $page < $pages ? self::link($page + 1, $query) : null,
            query: $query,
        );
    }

    /** Site-relative link to page $page of this list (no ?page for page 1). */
    public function url(int $page): string
    {
        return self::link($page, $this->query);
    }

    private static function link(int $page, string $query): string
    {
        $params = array_filter([$query, $page > 1 ? 'page=' . $page : '']);
        return Url::link(Url::currentPath()) . ($params !== [] ? '?' . implode('&', $params) : '');
    }
}
