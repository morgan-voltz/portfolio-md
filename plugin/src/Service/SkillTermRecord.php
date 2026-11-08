<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * What the synchronizer needs to know about an existing term of the `skill`
 * taxonomy, whatever stores it: the term itself, its code, and since lot 4
 * of ADR-020 the wordings of the other languages and whether the French
 * one is the school's own, all three kept as term metas.
 */
final readonly class SkillTermRecord
{
    /**
     * @param array<string, string> $translations language value => wording, for the languages other than French
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public string $description,
        public int $parentId,
        public ?string $code,
        public array $translations = [],
        public bool $official = false,
    ) {
    }
}
