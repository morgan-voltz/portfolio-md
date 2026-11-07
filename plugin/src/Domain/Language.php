<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * Language of a document, from its `lang` frontmatter key (ADR-020).
 *
 * Three languages, French being the default: the language the site was born
 * in, and the end of every fallback chain. Articles and projects say their
 * language in their slug (`momocad`, `momocad-de`, `momocad-en`); pages have
 * a natural slug in each language (`cv`, `lebenslauf`, `resume`), which
 * ContentType::suffixesSlugs() tells apart.
 *
 * Pure domain code: no locale, no label, no fallback chain here. Those come
 * with the Polylang bridge, the REST contract and the site (lots 2, 3 and 5).
 */
enum Language: string
{
    case Fr = 'fr';
    case De = 'de';
    case En = 'en';

    /** The language of the site, the end of every fallback chain. */
    public static function default(): self
    {
        return self::Fr;
    }

    public function isDefault(): bool
    {
        return $this === self::default();
    }

    /** What an article or project slug ends with in this language: nothing for French, "-de", "-en". */
    public function slugSuffix(): string
    {
        return $this->isDefault() ? '' : '-' . $this->value;
    }

    /**
     * Whether the slug of an article or a project may belong to this language
     * (ADR-020): a German slug ends with "-de", an English one with "-en", and
     * a French slug ends with neither. Pages are not concerned: they have a
     * natural slug in each language.
     */
    public function acceptsSlug(string $slug): bool
    {
        if (!$this->isDefault()) {
            return str_ends_with($slug, $this->slugSuffix());
        }

        foreach (self::cases() as $language) {
            if (!$language->isDefault() && str_ends_with($slug, $language->slugSuffix())) {
                return false;
            }
        }

        return true;
    }

    /**
     * Backed values of every case, in declaration order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $language): string => $language->value, self::cases());
    }
}
