<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Storage\PolylangMenuBindings;

/**
 * Whether a language has a menu, read in Polylang's nav_menus option as it
 * stores it: theme slug => location => language => menu id.
 */
#[CoversClass(PolylangMenuBindings::class)]
final class PolylangMenuBindingsTest extends TestCase
{
    private static function bindings(mixed $navMenus, string $stylesheet = 'portfolio-md-child'): PolylangMenuBindings
    {
        return new PolylangMenuBindings(static fn (): mixed => $navMenus, static fn (): string => $stylesheet);
    }

    #[Test]
    public function a_language_has_a_menu_when_the_active_theme_binds_one_to_the_primary_location(): void
    {
        $bindings = self::bindings(['portfolio-md-child' => ['primary' => ['fr' => 186, 'de' => 187]]]);

        self::assertTrue($bindings->hasMenu(Language::Fr));
        self::assertTrue($bindings->hasMenu(Language::De));
        self::assertFalse($bindings->hasMenu(Language::En), 'no entry, no menu');
    }

    #[Test]
    public function a_zero_or_junk_entry_is_no_menu(): void
    {
        $bindings = self::bindings(['portfolio-md-child' => ['primary' => ['de' => 0, 'en' => 'none']]]);

        self::assertFalse($bindings->hasMenu(Language::De));
        self::assertFalse($bindings->hasMenu(Language::En));
    }

    #[Test]
    public function the_bindings_of_another_theme_do_not_count(): void
    {
        $bindings = self::bindings(['generatepress' => ['primary' => ['de' => 187]]]);

        self::assertFalse($bindings->hasMenu(Language::De), 'the option is keyed by the active stylesheet');
    }

    #[Test]
    public function without_polylang_or_without_the_option_nothing_has_a_menu(): void
    {
        self::assertFalse(self::bindings(null)->hasMenu(Language::Fr));
        self::assertFalse(self::bindings('not an array')->hasMenu(Language::Fr));
        self::assertFalse(self::bindings([])->hasMenu(Language::Fr));
    }

    #[Test]
    public function the_default_readers_answer_without_polylang(): void
    {
        self::assertFalse((new PolylangMenuBindings())->hasMenu(Language::De));
    }
}
