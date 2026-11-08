<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Rest;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Rest\DocumentPresenter;
use Voltz\PortfolioMd\Service\DocumentSkill;
use Voltz\PortfolioMd\Service\SitemapEntry;
use Voltz\PortfolioMd\Tests\Support\Documents;

/**
 * The JSON of 01-plugin-php.md §7.3, key by key and in order: this is the
 * contract the C# gateway binds to.
 */
#[CoversClass(DocumentPresenter::class)]
final class DocumentPresenterTest extends TestCase
{
    private DocumentPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new DocumentPresenter();
    }

    #[Test]
    public function an_article_is_presented_in_the_order_of_the_contract_with_the_markdown_last(): void
    {
        $article = Documents::article();

        self::assertSame([
            'id' => 42,
            'type' => 'article',
            'slug' => 'rust-ownership-intuition',
            'lang' => 'fr',
            'translation_key' => 'rust-ownership-intuition',
            'translations' => ['fr' => 'rust-ownership-intuition'],
            'is_fallback' => false,
            'requested_language' => 'fr',
            'title' => 'Pourquoi Rust a changé ma façon de penser la mémoire',
            'link' => 'http://example.test/articles/rust-ownership-intuition/',
            'date' => '2026-04-21T22:00:00Z',
            'updated' => '2026-04-22T10:30:00Z',
            'tags' => [['slug' => 'rust', 'name' => 'Rust'], ['slug' => 'systems', 'name' => 'Systems']],
            'subtitle' => 'Une intuition visuelle du borrow checker',
            'seo_description' => 'Une intuition visuelle du borrow checker.',
            'reading_time' => 8,
            'featured' => false,
            'markdown' => $article->markdown,
        ], $this->presenter->present($article));
    }

    #[Test]
    public function a_project_adds_its_fields_between_the_common_ones_and_the_markdown(): void
    {
        $project = Documents::project();

        self::assertSame([
            'id' => 23,
            'type' => 'project',
            'slug' => 'momocad',
            'lang' => 'fr',
            'translation_key' => 'momocad',
            'translations' => ['fr' => 'momocad'],
            'is_fallback' => false,
            'requested_language' => 'fr',
            'title' => 'MomoCad',
            'link' => 'http://example.test/projets/momocad/',
            'date' => '2026-09-30T22:00:00Z',
            'updated' => '2026-10-27T13:13:00Z',
            'tags' => [['slug' => 'csharp', 'name' => 'C#'], ['slug' => 'avalonia', 'name' => 'Avalonia']],
            'subtitle' => null,
            'seo_description' => 'Un éditeur de plans.',
            'reading_time' => null,
            'featured' => true,
            'status' => ['slug' => 'en-cours', 'label' => 'En cours'],
            'skills' => [
                ['slug' => '4-0-2-e01', 'code' => '4.0.2/E01', 'label' => 'Concevoir une architecture', 'official' => false],
                ['slug' => '4-0-2-e07', 'code' => '4.0.2/E07', 'label' => 'Tester', 'official' => false],
            ],
            'is_fil_rouge' => true,
            'role' => 'Développeur unique',
            'period_start' => '2026-09-01',
            'period_end' => null,
            'repo_url' => 'https://gitlab.example/momocad',
            'demo_url' => null,
            'client' => null,
            'markdown' => $project->markdown,
        ], $this->presenter->present($project));
    }

    #[Test]
    public function a_page_carries_the_common_fields_only(): void
    {
        $page = Documents::page();

        self::assertSame([
            'id' => 195,
            'type' => 'page',
            'slug' => 'a-propos',
            'lang' => 'fr',
            'translation_key' => 'a-propos',
            'translations' => ['fr' => 'a-propos'],
            'is_fallback' => false,
            'requested_language' => 'fr',
            'title' => 'À propos',
            'link' => 'http://example.test/a-propos/',
            'date' => '2026-10-25T22:00:00Z',
            'updated' => '2026-10-27T12:07:00Z',
            'seo_description' => 'Qui je suis.',
            'markdown' => $page->markdown,
        ], $this->presenter->present($page));
    }

    #[Test]
    public function the_labels_of_a_project_follow_the_language_the_reader_asked(): void
    {
        $skills = [
            new DocumentSkill('fr-13', '4.0.1/FR-13', ['fr' => 'Développer des architectures n-tiers', 'de' => 'Mehrschichtige Architekturen entwickeln', 'en' => 'Develop n-tier architectures'], official: true),
            new DocumentSkill('e07', '4.0.2/E07', ['fr' => 'Exposer le domaine par une API documentée', 'de' => 'Die Domäne über eine dokumentierte API bereitstellen', 'en' => 'Expose the domain through a documented API']),
        ];

        $french = $this->presenter->present(Documents::project(['skills' => $skills, 'requestedLanguage' => Language::Fr]));
        $german = $this->presenter->present(Documents::project(['skills' => $skills, 'requestedLanguage' => Language::De]));
        $english = $this->presenter->present(Documents::project(['skills' => $skills, 'requestedLanguage' => Language::En]));

        self::assertSame(['slug' => 'en-cours', 'label' => 'En cours'], $french['status']);
        self::assertSame(['slug' => 'en-cours', 'label' => 'In Arbeit'], $german['status']);
        self::assertSame(['slug' => 'en-cours', 'label' => 'In progress'], $english['status']);

        self::assertSame([
            ['slug' => 'fr-13', 'code' => '4.0.1/FR-13', 'label' => 'Développer des architectures n-tiers', 'official' => true],
            ['slug' => 'e07', 'code' => '4.0.2/E07', 'label' => 'Exposer le domaine par une API documentée', 'official' => false],
        ], $french['skills'], 'in French, the wording of the tracking sheet is official, the stand-in of 4.0.2 is not');
        self::assertSame([
            ['slug' => 'fr-13', 'code' => '4.0.1/FR-13', 'label' => 'Mehrschichtige Architekturen entwickeln', 'official' => false],
            ['slug' => 'e07', 'code' => '4.0.2/E07', 'label' => 'Die Domäne über eine dokumentierte API bereitstellen', 'official' => false],
        ], $german['skills'], 'a translation is never official');
        self::assertSame('Develop n-tier architectures', $english['skills'][0]['label']);
        self::assertFalse($english['skills'][0]['official']);
    }

    #[Test]
    public function the_labels_follow_the_requested_language_even_on_a_french_fallback(): void
    {
        // A German reader served the French sheet (ADR-021): the document stays French, the vocabulary around it is German.
        $fallback = Documents::project([
            'skills' => [new DocumentSkill('fr-13', '4.0.1/FR-13', ['fr' => 'Développer des architectures n-tiers', 'de' => 'Mehrschichtige Architekturen entwickeln'], official: true)],
            'isFallback' => true,
            'requestedLanguage' => Language::De,
        ]);

        $data = $this->presenter->present($fallback);

        self::assertSame('fr', $data['lang']);
        self::assertTrue($data['is_fallback']);
        self::assertSame('de', $data['requested_language']);
        self::assertSame('In Arbeit', $data['status']['label']);
        self::assertSame('Mehrschichtige Architekturen entwickeln', $data['skills'][0]['label']);
        self::assertFalse($data['skills'][0]['official']);
    }

    #[Test]
    public function a_skill_without_translation_shows_its_french_wording_with_its_own_officiality(): void
    {
        $handMade = Documents::project([
            'skills' => [new DocumentSkill('fr-13', '4.0.1/FR-13', ['fr' => 'Développer des architectures n-tiers'], official: true)],
            'requestedLanguage' => Language::De,
        ]);

        $data = $this->presenter->present($handMade);

        self::assertSame(['slug' => 'fr-13', 'code' => '4.0.1/FR-13', 'label' => 'Développer des architectures n-tiers', 'official' => true], $data['skills'][0]);
    }

    #[Test]
    public function empty_optional_texts_and_a_zero_reading_time_are_null(): void
    {
        $data = $this->presenter->present(Documents::article(['subtitle' => '', 'seoDescription' => '', 'readingTime' => 0, 'tags' => []]));

        self::assertNull($data['subtitle']);
        self::assertNull($data['seo_description']);
        self::assertNull($data['reading_time']);
        self::assertSame([], $data['tags']);
    }

    #[Test]
    public function a_project_without_status_or_period_says_so_with_nulls(): void
    {
        $data = $this->presenter->present(Documents::project(['status' => null, 'skills' => [], 'periodStart' => null, 'role' => '', 'repoUrl' => '']));

        self::assertNull($data['status']);
        self::assertSame([], $data['skills']);
        self::assertNull($data['period_start']);
        self::assertNull($data['role']);
        self::assertNull($data['repo_url']);
    }

    #[Test]
    public function instants_are_printed_in_utc_whatever_the_timezone_they_were_read_in(): void
    {
        $paris = new DateTimeImmutable('2026-10-27 00:00:00', new DateTimeZone('Europe/Paris'));

        $data = $this->presenter->present(Documents::article(['date' => $paris, 'updated' => $paris->setTimezone(new DateTimeZone('America/New_York'))]));

        self::assertSame('2026-10-26T23:00:00Z', $data['date']);
        self::assertSame('2026-10-26T23:00:00Z', $data['updated']);
    }

    #[Test]
    public function a_translation_says_its_language_its_key_and_the_slugs_of_its_group(): void
    {
        $german = Documents::project(['id' => 24, 'slug' => 'momocad-de', 'lang' => Language::De, 'translationKey' => 'momocad', 'link' => 'http://example.test/de/projets/momocad-de/'])
            ->withTranslation(['fr' => 'momocad', 'de' => 'momocad-de'], false, Language::De);

        $data = $this->presenter->present($german);

        self::assertSame(['id', 'type', 'slug', 'lang', 'translation_key', 'translations', 'is_fallback', 'requested_language', 'title'], array_slice(array_keys($data), 0, 9));
        self::assertSame('de', $data['lang']);
        self::assertSame('momocad', $data['translation_key']);
        self::assertSame(['fr' => 'momocad', 'de' => 'momocad-de'], $data['translations']);
        self::assertFalse($data['is_fallback']);
        self::assertSame('de', $data['requested_language']);
        self::assertSame('http://example.test/de/projets/momocad-de/', $data['link']);
    }

    #[Test]
    public function a_fallback_is_flagged_and_keeps_the_language_it_is_served_in(): void
    {
        $data = $this->presenter->present(Documents::project()->withTranslation(['fr' => 'momocad'], true, Language::De));

        self::assertSame('fr', $data['lang']);
        self::assertTrue($data['is_fallback']);
        self::assertSame('de', $data['requested_language']);
        self::assertSame(['fr' => 'momocad'], $data['translations']);
    }

    #[Test]
    public function the_translations_always_hold_the_document_itself_in_the_order_of_the_site(): void
    {
        $unordered = $this->presenter->present(Documents::project(['translations' => ['en' => 'momocad-en', 'de' => 'momocad-de']]));
        $bare = $this->presenter->present(Documents::project(['translations' => []]));

        self::assertSame(['fr' => 'momocad', 'de' => 'momocad-de', 'en' => 'momocad-en'], $unordered['translations']);
        self::assertSame(['fr' => 'momocad'], $bare['translations']);
        // A map with at least one key encodes as a JSON object, never as the empty list a consumer could not bind.
        self::assertSame('{"fr":"momocad"}', json_encode($bare['translations']));
    }

    #[Test]
    public function the_sitemap_has_one_list_per_collection_even_when_empty(): void
    {
        $entries = [
            new SitemapEntry(ContentType::Project, 'momocad', Documents::utc('2026-10-27 13:13:00'), Language::Fr, 'momocad'),
            new SitemapEntry(ContentType::Article, 'rust', Documents::utc('2026-04-22 10:30:00'), Language::Fr, 'rust'),
            new SitemapEntry(ContentType::Project, 'momocad-de', Documents::utc('2026-10-27 13:14:00'), Language::De, 'momocad'),
        ];

        self::assertSame([
            'articles' => [['slug' => 'rust', 'updated' => '2026-04-22T10:30:00Z', 'lang' => 'fr', 'translation_key' => 'rust']],
            'projects' => [
                ['slug' => 'momocad', 'updated' => '2026-10-27T13:13:00Z', 'lang' => 'fr', 'translation_key' => 'momocad'],
                ['slug' => 'momocad-de', 'updated' => '2026-10-27T13:14:00Z', 'lang' => 'de', 'translation_key' => 'momocad'],
            ],
            'pages' => [],
        ], $this->presenter->presentSitemap($entries));

        self::assertSame(['articles' => [], 'projects' => [], 'pages' => []], $this->presenter->presentSitemap([]));
    }
}
