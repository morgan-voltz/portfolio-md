<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\MenuBindings;

/**
 * MenuBindings backed by a list of languages, for the unit tests of the
 * readiness grid.
 */
final class InMemoryMenuBindings implements MenuBindings
{
    /**
     * @param list<Language> $withMenu
     */
    public function __construct(public array $withMenu = [])
    {
    }

    public function hasMenu(Language $language): bool
    {
        return in_array($language, $this->withMenu, true);
    }
}
