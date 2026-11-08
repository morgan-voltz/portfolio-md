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

    /** @var callable(): mixed */
    private $navMenus;

    /** @var callable(): string */
    private $stylesheet;

    /**
     * @param callable(): mixed|null  $navMenus   Polylang's `nav_menus` option as stored (theme => location => language => menu id); Polylang's own when null, nothing without it
     * @param callable(): string|null $stylesheet the slug of the active theme; `get_option('stylesheet')` when null
     */
    public function __construct(?callable $navMenus = null, ?callable $stylesheet = null)
    {
        $this->navMenus = $navMenus ?? static fn (): mixed => function_exists('PLL') && is_object(PLL()) && isset(PLL()->options) ? PLL()->options['nav_menus'] : null;
        $this->stylesheet = $stylesheet ?? static fn (): string => (string) get_option('stylesheet');
    }

    public function hasMenu(Language $language): bool
    {
        $navMenus = ($this->navMenus)();
        if (!is_array($navMenus)) {
            return false;
        }

        $menu = $navMenus[($this->stylesheet)()][self::LOCATION][$language->value] ?? 0;

        return is_numeric($menu) && (int) $menu > 0;
    }
}
