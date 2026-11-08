<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\CatalogPage;
use Voltz\PortfolioMd\Service\CatalogQuery;
use Voltz\PortfolioMd\Service\DocumentTag;
use Voltz\PortfolioMd\Service\GroupResolver;
use Voltz\PortfolioMd\Service\PublishedDocument;
use Voltz\PortfolioMd\Tests\Support\Documents;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentCatalog;

/**
 * What a reader of a language gets from a catalog (ADR-021), played on the
 * in-memory catalog: its rules are those of the WordPress store, which
 * rest-smoke.php checks against a real site. This is the specification the
 * route tests then rely on.
 */
#[CoversClass(PublishedDocument::class)]
#[UsesClass(GroupResolver::class)]
final class CatalogLanguageResolutionTest extends TestCase
{
    private InMemoryContentCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryContentCatalog();
        $this->catalog->add(
            Documents::project(['id' => 1, 'slug' => 'momocad', 'date' => Documents::utc('2026-09-01 00:00:00')]),
            Documents::project(['id' => 2, 'slug' => 'momocad-de', 'lang' => Language::De, 'translationKey' => 'momocad', 'date' => Documents::utc('2026-11-01 00:00:00')]),
            Documents::project(['id' => 3, 'slug' => 'vefta', 'date' => Documents::utc('2026-10-01 00:00:00')]),
            Documents::project(['id' => 4, 'slug' => 'vefta-en', 'lang' => Language::En, 'translationKey' => 'vefta', 'date' => Documents::utc('2026-08-01 00:00:00')]),
        );
    }

    #[Test]
    public function a_list_is_anchored_on_the_french_documents_and_served_in_the_language_asked(): void
    {
        $french = $this->catalog->list(ContentType::Project, new CatalogQuery(language: Language::Fr));
        $german = $this->catalog->list(ContentType::Project, new CatalogQuery(language: Language::De));
        $english = $this->catalog->list(ContentType::Project, new CatalogQuery(language: Language::En));

        // The order is the French documents' (vefta is the newer one), whatever the dates of the translations.
        self::assertSame(['vefta', 'momocad'], self::slugs($french));
        self::assertSame([false, false], self::fallbacks($french));
        // A German reader gets the German sheet, and the English one of vefta along the chain de -> en -> fr.
        self::assertSame(['vefta-en', 'momocad-de'], self::slugs($german));
        self::assertSame([true, false], self::fallbacks($german));
        self::assertSame(['de', 'de'], array_map(static fn (PublishedDocument $d): string => $d->requestedLanguage->value, $german->items));
        self::assertSame(['vefta-en', 'momocad'], self::slugs($english));
        self::assertSame([false, true], self::fallbacks($english));
    }

    #[Test]
    public function the_totals_and_the_pages_are_the_same_in_every_language(): void
    {
        $frenchSecond = $this->catalog->list(ContentType::Project, new CatalogQuery(page: 2, perPage: 1, language: Language::Fr));
        $germanSecond = $this->catalog->list(ContentType::Project, new CatalogQuery(page: 2, perPage: 1, language: Language::De));

        self::assertSame(2, $frenchSecond->total);
        self::assertSame(2, $germanSecond->total);
        self::assertSame(['momocad'], self::slugs($frenchSecond));
        self::assertSame(['momocad-de'], self::slugs($germanSecond));
    }

    #[Test]
    public function the_filters_apply_to_the_french_document_not_to_the_translation_served(): void
    {
        $this->catalog->add(
            Documents::project(['id' => 5, 'slug' => 'atlas', 'tags' => [new DocumentTag('rust', 'Rust')]]),
            Documents::project(['id' => 6, 'slug' => 'atlas-de', 'lang' => Language::De, 'translationKey' => 'atlas', 'tags' => [new DocumentTag('php', 'PHP')]]),
        );

        $rust = $this->catalog->list(ContentType::Project, new CatalogQuery(tag: 'rust', language: Language::De));
        $php = $this->catalog->list(ContentType::Project, new CatalogQuery(tag: 'php', language: Language::De));

        self::assertSame(['atlas-de'], self::slugs($rust));
        self::assertSame([], self::slugs($php));
    }

    #[Test]
    public function a_document_without_french_original_is_in_no_list_but_is_served_under_its_slug(): void
    {
        $this->catalog->add(Documents::project(['id' => 7, 'slug' => 'waise-de', 'lang' => Language::De, 'translationKey' => 'waise']));

        self::assertNotContains('waise-de', self::slugs($this->catalog->list(ContentType::Project, new CatalogQuery(language: Language::De))));
        self::assertNotContains('waise-de', self::slugs($this->catalog->list(ContentType::Project, new CatalogQuery(language: Language::Fr))));

        $served = $this->catalog->find(ContentType::Project, 'waise-de', Language::Fr);

        self::assertNotNull($served);
        self::assertSame('waise-de', $served->slug);
        self::assertSame(Language::De, $served->lang);
        self::assertTrue($served->isFallback);
        self::assertSame(Language::Fr, $served->requestedLanguage);
        self::assertSame(['de' => 'waise-de'], $served->translations);
    }

    #[Test]
    public function a_detail_serves_the_member_of_the_group_named_by_the_slug(): void
    {
        $german = $this->catalog->find(ContentType::Project, 'momocad', Language::De);
        $frenchFromGerman = $this->catalog->find(ContentType::Project, 'momocad-de', Language::Fr);
        $englishFallback = $this->catalog->find(ContentType::Project, 'momocad', Language::En);

        self::assertSame('momocad-de', $german?->slug);
        self::assertFalse($german?->isFallback);
        self::assertSame(['fr' => 'momocad', 'de' => 'momocad-de'], $german?->translations);
        self::assertSame('momocad', $frenchFromGerman?->slug);
        self::assertFalse($frenchFromGerman?->isFallback);
        self::assertSame('momocad', $englishFallback?->slug);
        self::assertTrue($englishFallback?->isFallback);
        self::assertSame(Language::En, $englishFallback?->requestedLanguage);
    }

    #[Test]
    public function an_unknown_slug_is_null_whatever_the_language(): void
    {
        self::assertNull($this->catalog->find(ContentType::Project, 'nope', Language::De));
        self::assertNull($this->catalog->find(ContentType::Article, 'momocad', Language::Fr));
    }

    #[Test]
    public function an_article_and_a_project_sharing_a_key_are_two_groups(): void
    {
        $this->catalog->add(Documents::article(['id' => 8, 'slug' => 'momocad']));

        $article = $this->catalog->find(ContentType::Article, 'momocad', Language::De);

        self::assertSame(ContentType::Article, $article?->type);
        self::assertSame('momocad', $article?->slug);
        self::assertTrue($article?->isFallback);
        self::assertSame(['fr' => 'momocad'], $article?->translations);
    }

    #[Test]
    public function when_two_documents_share_a_language_the_oldest_wins_but_a_named_one_is_served_as_itself(): void
    {
        $this->catalog->add(Documents::project(['id' => 9, 'slug' => 'vefta-en-copy', 'lang' => Language::En, 'translationKey' => 'vefta']));

        $list = $this->catalog->list(ContentType::Project, new CatalogQuery(language: Language::En));
        $named = $this->catalog->find(ContentType::Project, 'vefta-en-copy', Language::En);

        // The older post (id 4) wins the substitution in the list; the copy, asked by its slug, is served as itself.
        self::assertSame(['vefta-en', 'momocad'], self::slugs($list));
        self::assertSame('vefta-en-copy', $named?->slug);
        self::assertSame(['fr' => 'vefta', 'en' => 'vefta-en-copy'], $named?->translations);
    }

    #[Test]
    public function the_sitemap_lists_every_language_with_its_key(): void
    {
        $entries = $this->catalog->sitemap();

        self::assertSame(['momocad', 'momocad-de', 'vefta', 'vefta-en'], array_map(static fn ($e): string => $e->slug, $entries));
        self::assertSame(['fr', 'de', 'fr', 'en'], array_map(static fn ($e): string => $e->lang->value, $entries));
        self::assertSame(['momocad', 'momocad', 'vefta', 'vefta'], array_map(static fn ($e): string => $e->translationKey, $entries));
    }

    /** @return list<string> */
    private static function slugs(CatalogPage $page): array
    {
        return array_map(static fn (PublishedDocument $document): string => $document->slug, $page->items);
    }

    /** @return list<bool> */
    private static function fallbacks(CatalogPage $page): array
    {
        return array_map(static fn (PublishedDocument $document): bool => $document->isFallback, $page->items);
    }
}
