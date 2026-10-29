<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * One page of a collection, with the size of the whole collection: enough
 * for a consumer to paginate without a second query.
 */
final readonly class CatalogPage
{
    /**
     * @param list<PublishedDocument> $items   the documents of this page, newest first
     * @param int                     $total   documents in the whole (filtered) collection
     * @param int                     $page    the page these items are, from 1
     * @param int                     $perPage the page size that was asked
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /** Pages in the whole collection; 0 when it is empty, like the native REST API. */
    public function totalPages(): int
    {
        return (int) ceil($this->total / $this->perPage);
    }
}
