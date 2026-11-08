<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Language;

/**
 * One published document of a translation group, reduced to what the
 * resolution of ADR-021 needs: which post it is, in which language, under
 * which slug. Both catalogs (the WordPress store and the in-memory one of
 * the tests) describe their documents this way before asking GroupResolver
 * which one a reader gets.
 */
final readonly class GroupMember
{
    public function __construct(
        public int $id,
        public Language $lang,
        public string $slug,
    ) {
    }
}
