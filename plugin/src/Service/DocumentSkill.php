<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * A competency a project demonstrates, as a reader sees it: the term slug
 * it may filter on, the official code of the referential (ADR-014) and the
 * official wording (01-plugin-php.md §7.3).
 */
final readonly class DocumentSkill
{
    public function __construct(
        public string $slug,
        public string $code,
        public string $label,
    ) {
    }
}
