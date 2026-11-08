<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * Port of the publication of the languages (ADR-022) towards the place the
 * site keeps its settings: the WordPress option in production
 * (Storage\WordPressPublishedLanguagesStore), an array in the unit tests.
 * It hands back whatever is stored, as it is: Service\PublishedLanguages
 * makes sense of it.
 */
interface PublishedLanguagesStore
{
    /**
     * The stored value, whatever its shape; null when nothing is stored yet.
     */
    public function read(): mixed;

    /**
     * @param list<string> $slugs the language values to store, already normalized
     */
    public function write(array $slugs): void;
}
