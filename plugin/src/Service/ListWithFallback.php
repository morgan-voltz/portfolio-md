<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use InvalidArgumentException;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;

/**
 * The list a template prints (ADR-022, point 6): a thin facade on the
 * catalog, the same one the REST routes serve. It builds the query the
 * collection route would build for `?language=<lang>&per_page=<limit>` and
 * calls the same list(), so a page of cards on the site and a page of the
 * API hold the same documents in the same order, served along the same
 * fallback chain (ADR-021). Nothing about the query is written twice.
 */
final class ListWithFallback
{
    /** Cards on a page of the site (ADR-022, point 8): the whole portfolio fits, no pagination. */
    public const DEFAULT_LIMIT = 50;

    public function __construct(private readonly ContentCatalog $catalog)
    {
    }

    /**
     * The first $limit published documents of this kind as a reader of
     * $language gets them, newest French document first.
     *
     * @return list<ListItem>
     *
     * @throws InvalidArgumentException when $limit is outside 1..CatalogQuery::MAX_PER_PAGE
     */
    public function items(ContentType $type, Language $language, int $limit = self::DEFAULT_LIMIT): array
    {
        $page = $this->catalog->list($type, new CatalogQuery(perPage: $limit, language: $language));

        return array_map(ListItem::fromDocument(...), $page->items);
    }
}
