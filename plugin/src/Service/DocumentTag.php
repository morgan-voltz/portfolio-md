<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Language;

/**
 * A technology tag of a published document as the catalog reads it: its
 * slug, its French name (the name of the term) and, since ADR-024, the
 * wordings of the other languages found in the term metas. The presenter
 * and the theme print the wording of the language of the page, falling
 * back along the chain of that language, French last, like a skill.
 */
final readonly class DocumentTag
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
     * The language whose wording label() returns for a reader of $language.
     * A tag without translation is a name that is the same everywhere (a
     * technology, ".NET" or "Rust"): it belongs to every language, so the
     * reader's own is returned and the theme marks nothing.
     */
    public function wordingLanguage(Language $language): Language
    {
        if ($this->translations === []) {
            return $language;
        }
        foreach ($language->fallbackChain() as $candidate) {
            if ($candidate->isDefault() || ($this->translations[$candidate->value] ?? '') !== '') {
                return $candidate;
            }
        }

        return Language::default();
    }

    public function label(Language $language): string
    {
        $wording = $this->wordingLanguage($language);

        return $this->translations[$wording->value] ?? $this->name;
    }
}
