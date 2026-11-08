<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * A term of the tech_tag taxonomy as the store holds it (ADR-024): its id,
 * slug, name (the French wording) and the wordings of the other languages
 * found in its metas.
 */
final readonly class TagTermRecord
{
    /**
     * @param array<string, string> $translations language value => wording
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public array $translations = [],
    ) {
    }
}
