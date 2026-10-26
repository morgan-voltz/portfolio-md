<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * A competency referential of the UHA 4.0 curriculum ("4.0.1", "4.0.2"):
 * the parent term of the `skill` taxonomy, with its competencies as children.
 */
final readonly class Referential
{
    /**
     * @param list<Skill> $skills
     */
    public function __construct(
        public string $code,
        public string $slug,
        public string $name,
        public string $description,
        public array $skills,
    ) {
    }
}
