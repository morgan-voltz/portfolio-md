<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * One competency of a referential (content/skills.yaml, ADR-014).
 *
 * `code` is the stable identifier referenced by project sheets (for example
 * "4.0.1/FR-13"); `slug` is the WordPress term slug under the referential's
 * parent term; `name` is the French wording, the name of the term; and
 * `translations` holds the wording of the other languages of the site,
 * keyed by language value (`de`, `en`), which the taxonomy stores as term
 * metas (lot 4 of ADR-020). Whether the French wording is the school's own
 * is a property of the referential; a translation never is.
 */
final readonly class Skill
{
    /**
     * @param array<string, string> $translations language value => wording, for the languages other than French
     */
    public function __construct(
        public string $code,
        public string $slug,
        public string $name,
        public string $description,
        public array $translations = [],
    ) {
    }
}
