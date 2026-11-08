<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * The names of one technology tag (ADR-024): its slug, the one the sheets
 * cite, its French name, the one the WordPress term carries, and the
 * wordings of the other languages, stored as term metas. A tag with the
 * same name in every language has no translations: the French name serves
 * everywhere, as a technology name does.
 */
final readonly class TechTagName
{
    /**
     * @param array<string, string> $translations language value => wording, for the languages other than French
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $translations = [],
    ) {
    }

    /**
     * @return array<string, string> every wording the tag has, French first
     */
    public function names(): array
    {
        return [Language::default()->value => $this->name] + $this->translations;
    }
}
