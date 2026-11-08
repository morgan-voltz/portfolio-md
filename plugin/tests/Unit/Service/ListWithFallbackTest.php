<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Rest\Controller\CollectionController;
use Voltz\PortfolioMd\Rest\DocumentPresenter;
use Voltz\PortfolioMd\Service\CatalogQuery;
use Voltz\PortfolioMd\Service\ListItem;
use Voltz\PortfolioMd\Service\ListWithFallback;
use Voltz\PortfolioMd\Tests\Support\Documents;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentCatalog;
use WP_REST_Request;

/**
 * The list a template prints is the list the REST route serves (ADR-022,
 * point 6): same documents, same order, same fallback, through the same
 * catalog. The fixture holds three French projects, newest first p3, p2,
 * p1; a German version of p2 and an English version of p3 are added by
 * the cases that need them.
 */
#[CoversClass(ListWithFallback::class)]
#[CoversClass(ListItem::class)]
final class ListWithFallbackTest extends TestCase
{
    private InMemoryContentCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryContentCatalog();
        foreach ([1, 2, 3] as $n) {
            $this->catalog->add(Documents::project([
                'id' => $n,
                'slug' => "p{$n}",
                'translationKey' => "p{$n}",
                'title' => "Projet {$n}",
                'subtitle' => "Sous-titre {$n}",
                'link' => "http://example.test/fr/projets/p{$n}/",
                'date' => Documents::utc("2026-0{$n}-01 00:00:00"),
            ]));
        }
    }

    private function addGermanP2(): void
    {
        $this->catalog->add(Documents::project(['id' => 12, 'slug' => 'p2-de', 'translationKey' => 'p2', 'lang' => Language::De, 'title' => 'Projekt 2', 'subtitle' => 'Untertitel 2', 'link' => 'http://example.test/de/projets/p2-de/', 'date' => Documents::utc('2026-02-01 00:00:00')]));
    }

    private function addEnglishP3(): void
    {
        $this->catalog->add(Documents::project(['id' => 23, 'slug' => 'p3-en', 'translationKey' => 'p3', 'lang' => Language::En, 'title' => 'Project 3', 'subtitle' => 'Deck 3', 'link' => 'http://example.test/en/projets/p3-en/', 'date' => Documents::utc('2026-03-01 00:00:00')]));
    }

    #[Test]
    public function a_german_reader_gets_every_project_newest_french_first_each_in_the_nearest_language(): void
    {
        $this->addGermanP2();
        $this->addEnglishP3();

        $items = (new ListWithFallback($this->catalog))->items(ContentType::Project, Language::De);

        self::assertSame([23, 12, 1], array_map(static fn (ListItem $item): int => $item->postId, $items), 'p3 as English, p2 as German, p1 as French');
        self::assertSame(['en', 'de', 'fr'], array_map(static fn (ListItem $item): string => $item->lang->value, $items));
        self::assertSame([true, false, true], array_map(static fn (ListItem $item): bool => $item->isFallback, $items));
        self::assertSame(['de', 'de', 'de'], array_map(static fn (ListItem $item): string => $item->requestedLanguage->value, $items));
        self::assertSame(['http://example.test/en/projets/p3-en/', 'http://example.test/de/projets/p2-de/', 'http://example.test/fr/projets/p1/'], array_map(static fn (ListItem $item): string => $item->permalink, $items), 'a card links to the document served, in its own language');
        self::assertSame(['Project 3', 'Projekt 2', 'Projet 1'], array_map(static fn (ListItem $item): string => $item->title, $items));
    }

    #[Test]
    public function the_excerpt_is_the_subtitle_else_the_seo_description_else_nothing(): void
    {
        $catalog = new InMemoryContentCatalog();
        $catalog->add(
            Documents::project(['id' => 1, 'slug' => 'a', 'translationKey' => 'a', 'subtitle' => 'Un sous-titre', 'seoDescription' => 'Une description', 'date' => Documents::utc('2026-03-01 00:00:00')]),
            Documents::project(['id' => 2, 'slug' => 'b', 'translationKey' => 'b', 'subtitle' => '', 'seoDescription' => 'Une description', 'date' => Documents::utc('2026-02-01 00:00:00')]),
            Documents::project(['id' => 3, 'slug' => 'c', 'translationKey' => 'c', 'subtitle' => '', 'seoDescription' => '', 'date' => Documents::utc('2026-01-01 00:00:00')]),
        );

        $items = (new ListWithFallback($catalog))->items(ContentType::Project, Language::Fr);

        self::assertSame(['Un sous-titre', 'Une description', ''], array_map(static fn (ListItem $item): string => $item->excerpt, $items));
    }

    /**
     * @return iterable<string, array{Language, list<string>}>
     */
    public static function readers(): iterable
    {
        yield 'German, no translation' => [Language::De, []];
        yield 'German, one German and one English translation' => [Language::De, ['de', 'en']];
        yield 'German, every project translated' => [Language::De, ['de-all']];
        yield 'English, no translation' => [Language::En, []];
        yield 'English, one English translation' => [Language::En, ['en']];
        yield 'French' => [Language::Fr, ['de', 'en']];
    }

    /**
     * @param list<string> $translations
     */
    #[Test]
    #[DataProvider('readers')]
    public function the_list_of_the_site_and_the_list_of_the_api_hold_the_same_documents_in_the_same_order(Language $language, array $translations): void
    {
        if (in_array('de', $translations, true)) {
            $this->addGermanP2();
        }
        if (in_array('en', $translations, true)) {
            $this->addEnglishP3();
        }
        if (in_array('de-all', $translations, true)) {
            foreach ([1, 2, 3] as $n) {
                $this->catalog->add(Documents::project(['id' => 10 + $n, 'slug' => "p{$n}-de", 'translationKey' => "p{$n}", 'lang' => Language::De, 'link' => "http://example.test/de/projets/p{$n}-de/", 'date' => Documents::utc("2026-0{$n}-01 00:00:00")]));
            }
        }

        $site = (new ListWithFallback($this->catalog))->items(ContentType::Project, $language, 50);

        $request = new WP_REST_Request('GET', '/portfolio/v1/projects');
        $request->set_param('language', $language->value);
        $request->set_param('per_page', 50);
        $api = (new CollectionController(ContentType::Project, $this->catalog, new DocumentPresenter()))->list($request)->get_data();

        self::assertCount(3, $site);
        self::assertSame(array_column($api, 'id'), array_map(static fn (ListItem $item): int => $item->postId, $site));
        self::assertSame(array_column($api, 'link'), array_map(static fn (ListItem $item): string => $item->permalink, $site));
        self::assertSame(array_column($api, 'lang'), array_map(static fn (ListItem $item): string => $item->lang->value, $site));
        self::assertSame(array_column($api, 'is_fallback'), array_map(static fn (ListItem $item): bool => $item->isFallback, $site));
    }

    #[Test]
    public function the_limit_is_the_size_of_the_first_page_asked_of_the_catalog(): void
    {
        $items = (new ListWithFallback($this->catalog))->items(ContentType::Project, Language::En, 2);

        self::assertCount(2, $items);
        self::assertSame([3, 2], array_map(static fn (ListItem $item): int => $item->postId, $items));
        self::assertCount(1, $this->catalog->listCalls);
        $query = $this->catalog->listCalls[0]['query'];
        self::assertSame(ContentType::Project, $this->catalog->listCalls[0]['type']);
        self::assertSame([1, 2, Language::En, null, null, null], [$query->page, $query->perPage, $query->language, $query->tag, $query->status, $query->skill]);
    }

    #[Test]
    public function the_default_limit_holds_the_whole_portfolio_within_the_cap_of_a_page(): void
    {
        self::assertSame(50, ListWithFallback::DEFAULT_LIMIT);
        self::assertLessThanOrEqual(CatalogQuery::MAX_PER_PAGE, ListWithFallback::DEFAULT_LIMIT);
        self::assertSame([3, 2, 1], array_map(static fn (ListItem $item): int => $item->postId, (new ListWithFallback($this->catalog))->items(ContentType::Project, Language::Fr)));
    }

    #[Test]
    public function a_limit_outside_the_page_sizes_of_the_catalog_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ListWithFallback($this->catalog))->items(ContentType::Project, Language::Fr, CatalogQuery::MAX_PER_PAGE + 1);
    }

    #[Test]
    public function an_empty_collection_is_an_empty_list(): void
    {
        self::assertSame([], (new ListWithFallback(new InMemoryContentCatalog()))->items(ContentType::Article, Language::De));
    }
}
