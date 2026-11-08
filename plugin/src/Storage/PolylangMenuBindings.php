<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\MenuBindings;

/**
 * MenuBindings over Polylang's `nav_menus` option (ADR-022, point 12): one
 * menu per language and per location, keyed by the active stylesheet, the
 * child theme. GeneratePress has one location, `primary`. Without Polylang,
 * or without an entry, a language has no menu. Reading Polylang here is
 * reading a setting of the site, not deciding the language of a document.
 */
final class PolylangMenuBindings implements MenuBindings
{
    public const LOCATION = 'primary';

    public function hasMenu(Language $language): bool
    {
        if (!function_exists('PLL') || !is_object(PLL()) || !isset(PLL()->options)) {
            return false;
        }

        $navMenus = PLL()->options['nav_menus'];
        if (!is_array($navMenus)) {
            return false;
        }

        $menu = $navMenus[(string) get_option('stylesheet')][self::LOCATION][$language->value] ?? 0;

        return (int) $menu > 0;
    }
}
