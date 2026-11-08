<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Storage\WordPressPublishedLanguagesStore;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(WordPressPublishedLanguagesStore::class)]
final class WordPressPublishedLanguagesStoreTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressSpy::reset();
    }

    #[Test]
    public function hooks_the_normalization_of_every_write_and_the_sitemap_cache_clearing_on_creation_and_update(): void
    {
        $store = new WordPressPublishedLanguagesStore();
        $store->register();

        $hooks = array_map(static fn (array $hook): array => [$hook['type'], $hook['hook'], $hook['callback']], WordPressSpy::$hooks);
        self::assertSame([
            ['filter', 'pre_update_option_portfolio_md_published_languages', [$store, 'normalize']],
            ['action', 'add_option_portfolio_md_published_languages', [$store, 'clearSitemapCache']],
            ['action', 'update_option_portfolio_md_published_languages', [$store, 'clearSitemapCache']],
        ], $hooks);
    }

    #[Test]
    public function reads_the_option_as_it_is_and_null_when_absent(): void
    {
        $store = new WordPressPublishedLanguagesStore();

        self::assertNull($store->read());

        WordPressSpy::$options[WordPressPublishedLanguagesStore::OPTION] = 'de,en';

        self::assertSame('de,en', $store->read());
    }

    #[Test]
    public function writes_a_normalized_list_to_the_option(): void
    {
        (new WordPressPublishedLanguagesStore())->write(['en', 'fr', 'xx']);

        self::assertSame(['fr', 'en'], WordPressSpy::$options[WordPressPublishedLanguagesStore::OPTION]);
    }

    #[Test]
    public function the_filter_normalizes_what_anyone_writes(): void
    {
        self::assertSame(['fr', 'de'], (new WordPressPublishedLanguagesStore())->normalize(['de', 'DE', 'xx']));
        self::assertSame(['fr'], (new WordPressPublishedLanguagesStore())->normalize('garbage'));
    }

    #[Test]
    public function clearing_the_sitemap_cache_calls_what_it_was_given_once_per_write(): void
    {
        $clears = 0;
        $store = new WordPressPublishedLanguagesStore(static function () use (&$clears): void {
            $clears++;
        });

        $store->clearSitemapCache();
        $store->clearSitemapCache();

        self::assertSame(2, $clears);
    }

    #[Test]
    public function the_default_clearing_survives_the_absence_of_yoast(): void
    {
        self::assertFalse(class_exists(\WPSEO_Sitemaps_Cache::class), 'the unit suite runs without Yoast');

        (new WordPressPublishedLanguagesStore())->clearSitemapCache();

        self::assertTrue(true, 'no error, nothing to clear');
    }

    #[Test]
    public function the_service_and_the_store_agree(): void
    {
        $store = new WordPressPublishedLanguagesStore();
        $languages = new PublishedLanguages($store);

        self::assertSame(['fr'], array_map(static fn ($language) => $language->value, $languages->all()));

        $languages->publish(\Voltz\PortfolioMd\Domain\Language::De);

        self::assertSame(['fr', 'de'], WordPressSpy::$options[WordPressPublishedLanguagesStore::OPTION]);
    }
}
