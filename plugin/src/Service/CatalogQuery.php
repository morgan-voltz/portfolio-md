<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use InvalidArgumentException;
use Voltz\PortfolioMd\Domain\Patterns;
use Voltz\PortfolioMd\Domain\ProjectStatus;

/**
 * What a reader asks of a collection: which page, how long, narrowed by
 * which terms. The REST layer builds it from the query string, after the
 * REST server has validated the parameters against the route's schema; the
 * guards here are the last line, and the one the unit tests see.
 *
 * The filters are the terms of the three taxonomies: a tag (articles and
 * projects), a project status and a skill (projects). A filter is a term
 * slug; a slug that matches no term gives an empty page, not an error.
 */
final readonly class CatalogQuery
{
    public const DEFAULT_PER_PAGE = 10;

    /** The cap of the native REST API, for the same reason: a page is a page. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?string $tag = null,
        public ?ProjectStatus $status = null,
        public ?string $skill = null,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException("The page must be 1 or more, {$page} given.");
        }
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new InvalidArgumentException('The page size must be between 1 and ' . self::MAX_PER_PAGE . ", {$perPage} given.");
        }
        foreach (['tag' => $tag, 'skill' => $skill] as $filter => $slug) {
            if ($slug !== null && preg_match(Patterns::SLUG, $slug) !== 1) {
                throw new InvalidArgumentException("The {$filter} filter must be a term slug, \"{$slug}\" given.");
            }
        }
    }

    /** Number of documents before the first one of this page. */
    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** Whether at least one filter narrows the collection. */
    public function isFiltered(): bool
    {
        return $this->tag !== null || $this->status !== null || $this->skill !== null;
    }
}
