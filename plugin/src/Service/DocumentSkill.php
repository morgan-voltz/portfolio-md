<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use InvalidArgumentException;
use Voltz\PortfolioMd\Domain\Language;

/**
 * A competency a project demonstrates, as a reader sees it: the term slug
 * it may filter on, the official code of the referential (ADR-014) and its
 * wording in every language the taxonomy stores (01-plugin-php.md §7.3).
 *
 * The French wording is the name of the term and is always present; the
 * others are the term metas `wp portfolio skills sync` writes from the
 * grid (lot 4 of ADR-020). label() picks the wording of a language along
 * the fallback chain of that language, French last, so that a term without
 * a translation (made by hand, or not synchronized since the deployment)
 * shows its French wording rather than nothing. Only the French wording
 * can be official: a translation is never the school's own text.
 */
final readonly class DocumentSkill
{
    /**
     * @param non-empty-array<string, string> $names    language value => wording, the French one included
     * @param bool                            $official whether the French wording is the referential's own
     */
    public function __construct(
        public string $slug,
        public string $code,
        public array $names,
        public bool $official = false,
    ) {
        $french = $names[Language::default()->value] ?? '';
        if (!is_string($french) || $french === '') {
            throw new InvalidArgumentException("The skill \"{$code}\" has no French wording.");
        }
    }

    /**
     * The language of the wording a reader of $language gets: the first of
     * the fallback chain of that language that has one.
     */
    public function wordingLanguage(Language $language): Language
    {
        foreach ($language->fallbackChain() as $candidate) {
            if (($this->names[$candidate->value] ?? '') !== '') {
                return $candidate;
            }
        }

        return Language::default();
    }

    public function label(Language $language): string
    {
        return $this->names[$this->wordingLanguage($language)->value];
    }

    /**
     * Whether the wording a reader of $language gets is the referential's
     * own: only the French one can be, and only when its referential says so.
     */
    public function isOfficial(Language $language): bool
    {
        return $this->official && $this->wordingLanguage($language)->isDefault();
    }
}
