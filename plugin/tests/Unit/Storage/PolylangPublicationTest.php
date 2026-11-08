<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Storage\PolylangPublication;
use Voltz\PortfolioMd\Tests\Support\InMemoryPublishedLanguagesStore;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

/**
 * What Polylang must not announce about a closed language (ADR-022, point
 * 3): each filter with the language closed, then open.
 */
#[CoversClass(PolylangPublication::class)]
final class PolylangPublicationTest extends TestCase
{
    private InMemoryPublishedLanguagesStore $store;
    private PolylangPublication $publication;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        $this->store = new InMemoryPublishedLanguagesStore(['fr']);
        $this->publication = new PolylangPublication(new PublishedLanguages($this->store));
    }

    private static function language(string $slug): object
    {
        return (object) ['slug' => $slug, 'name' => strtoupper($slug)];
    }

    #[Test]
    public function hooks_the_four_polylang_filters_with_their_arguments(): void
    {
        $this->publication->register();

        $hooks = [];
        foreach (WordPressSpy::$hooks as $hook) {
            $hooks[$hook['hook']] = [$hook['type'], $hook['args']];
        }
        self::assertSame([
            'pll_languages_for_browser_preferences' => ['filter', 1],
            'pll_preferred_language' => ['filter', 2],
            'pll_translation_url' => ['filter', 2],
            'pll_rel_hreflang_attributes' => ['filter', 1],
        ], $hooks);
    }

    #[Test]
    public function the_browser_is_only_matched_against_the_open_languages(): void
    {
        $languages = [self::language('fr'), self::language('de'), self::language('en')];

        self::assertSame(['fr'], array_column($this->publication->browserLanguages($languages), 'slug'));

        $this->store->value = ['fr', 'de'];

        self::assertSame(['fr', 'de'], array_column($this->publication->browserLanguages($languages), 'slug'));
    }

    #[Test]
    public function a_language_the_plugin_does_not_know_is_left_to_polylang_and_junk_is_dropped(): void
    {
        $kept = $this->publication->browserLanguages([self::language('fr'), self::language('es'), 'not an object', (object) ['name' => 'no slug']]);

        self::assertSame(['fr', 'es'], array_column($kept, 'slug'));
    }

    #[Test]
    public function a_cookie_or_browser_value_naming_a_closed_language_is_refused(): void
    {
        self::assertFalse($this->publication->preferredLanguage('de', 'de'));
        self::assertSame('fr', $this->publication->preferredLanguage('fr', false));
        self::assertFalse($this->publication->preferredLanguage(false, false), 'nothing found stays nothing');
        self::assertSame('es', $this->publication->preferredLanguage('es', false), 'unknown to the plugin, Polylang decides');

        $this->store->value = ['fr', 'de'];

        self::assertSame('de', $this->publication->preferredLanguage('de', 'de'));
    }

    #[Test]
    public function the_translation_url_of_a_closed_language_is_emptied_whatever_the_page(): void
    {
        self::assertSame('', $this->publication->translationUrl('http://example.test/de/projets/momocad-de/', 'de'));
        self::assertSame('', $this->publication->translationUrl('http://example.test/de/', 'de'), 'the current language too, when it is closed');
        self::assertSame('http://example.test/fr/projets/momocad/', $this->publication->translationUrl('http://example.test/fr/projets/momocad/', 'fr'));
        self::assertSame('http://example.test/es/', $this->publication->translationUrl('http://example.test/es/', 'es'));
        self::assertSame('http://example.test/x/', $this->publication->translationUrl('http://example.test/x/', null));

        $this->store->value = ['fr', 'de'];

        self::assertSame('http://example.test/de/', $this->publication->translationUrl('http://example.test/de/', 'de'));
    }

    #[Test]
    public function x_default_points_to_the_french_page_when_polylang_did_not_set_one(): void
    {
        $set = ['fr' => 'http://example.test/fr/projets/momocad/', 'de' => 'http://example.test/de/projets/momocad-de/'];

        self::assertSame($set + ['x-default' => 'http://example.test/fr/projets/momocad/'], $this->publication->hreflangs($set));
    }

    #[Test]
    public function polylangs_own_x_default_on_the_home_is_kept(): void
    {
        $set = ['fr' => 'http://example.test/fr/', 'de' => 'http://example.test/de/', 'x-default' => 'http://example.test/'];

        self::assertSame($set, $this->publication->hreflangs($set));
    }

    #[Test]
    public function no_x_default_without_a_french_entry(): void
    {
        $set = ['de' => 'http://example.test/de/x/', 'en' => 'http://example.test/en/x/'];

        self::assertSame($set, $this->publication->hreflangs($set));
    }
}
