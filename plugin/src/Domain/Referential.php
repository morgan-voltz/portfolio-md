<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * A competency referential of the UHA 4.0 curriculum ("4.0.1", "4.0.2"):
 * the parent term of the `skill` taxonomy, with its competencies as children.
 *
 * `official` says whether the French wordings of its competencies are the
 * school's own (the tracking sheet of 4.0.1) or a stand-in written from the
 * course material (4.0.2 until its sheet exists): the API relays it on every
 * competency, so that nothing is presented as official that is not
 * (ADR-020, lot 4).
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
        public bool $official,
    ) {
    }
}
