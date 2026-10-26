<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * One competency of a referential (content/skills.yaml, ADR-014).
 *
 * `code` is the stable identifier referenced by project sheets (for example
 * "4.0.1/FR-13"); `slug` is the WordPress term slug under the referential's
 * parent term; `name` is the official wording.
 */
final readonly class Skill
{
    public function __construct(
        public string $code,
        public string $slug,
        public string $name,
        public string $description,
    ) {
    }
}
