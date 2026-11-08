<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use InvalidArgumentException;
use Voltz\PortfolioMd\Domain\Language;

/**
 * Which languages of the site are open to the public (ADR-022, point 1):
 * the only reader of the option `portfolio_md_published_languages`, and the
 * only writer besides a direct `wp option update`, which the store
 * normalizes the same way on its way in.
 *
 * A closed language is served but not announced (ADR-022, point 3): the
 * Polylang and Yoast adapters ask this service before announcing anything.
 * French, the language of the site, is always open: it is the end of every
 * fallback chain and the anchor of every list, and cannot be closed.
 */
final class PublishedLanguages
{
    public function __construct(private readonly PublishedLanguagesStore $store)
    {
    }

    /**
     * Whatever is stored, made a clean list: only the languages of the site,
     * French always present, each once, in the order of Language::cases().
     * Anything else (a string, a language the site does not have, null) is
     * dropped, so that a hand-written option can never break the site.
     *
     * @return list<string>
     */
    public static function normalize(mixed $raw): array
    {
        $kept = [Language::default()->value => true];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (is_string($value) && Language::tryFrom($value) !== null) {
                $kept[$value] = true;
            }
        }

        return array_values(array_filter(Language::values(), static fn (string $slug): bool => isset($kept[$slug])));
    }

    /**
     * @return list<Language> the open languages, French first
     */
    public function all(): array
    {
        return array_map(static fn (string $slug): Language => Language::from($slug), self::normalize($this->store->read()));
    }

    public function isPublished(Language $language): bool
    {
        return in_array($language, $this->all(), true);
    }

    /** Opens a language; opening an open language changes nothing. */
    public function publish(Language $language): void
    {
        $slugs = self::normalize($this->store->read());
        $slugs[] = $language->value;
        $this->store->write(self::normalize($slugs));
    }

    /**
     * Closes a language; closing a closed language changes nothing.
     *
     * @throws InvalidArgumentException for the language of the site
     */
    public function unpublish(Language $language): void
    {
        if ($language->isDefault()) {
            throw new InvalidArgumentException("The language of the site, {$language->value}, cannot be closed.");
        }
        $this->store->write(self::normalize(array_values(array_diff(self::normalize($this->store->read()), [$language->value]))));
    }
}
