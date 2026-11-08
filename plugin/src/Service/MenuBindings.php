<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Language;

/**
 * Port of the readiness grid (ADR-022, point 2) towards the menus of the
 * site: whether a language has a menu of its own bound to the navigation.
 * Polylang keeps one menu per language and per location
 * (Storage\PolylangMenuBindings); the unit tests keep a list.
 */
interface MenuBindings
{
    public function hasMenu(Language $language): bool;
}
