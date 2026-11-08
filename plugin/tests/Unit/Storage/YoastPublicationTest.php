<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Storage\YoastPublication;
use Voltz\PortfolioMd\Tests\Support\Documents;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentCatalog;
use Voltz\PortfolioMd\Tests\Support\InMemoryPublishedLanguagesStore;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

/**
 * What Yoast must not index or list about a closed language (ADR-022,
 * point 4): robots, sitemap entries and the archive blocks of Polylang;
 * and the archive of a language without documents of its own, which is
 * served but not indexed (lot 5c).
 */
#[CoversClass(YoastPublication::class)]
final class YoastPublicationTest extends TestCase
{
    private const HOME = 'http://example.test';
    private const ROBOTS = 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1';

    private InMemoryPublishedLanguagesStore $store;
    private InMemoryContentCatalog $catalog;
    private string $currentLanguage = 'fr';
    private string $currentFilter = 'wpseo_sitemap_project_content';

    protected function setUp(): void
    {
        WordPressSpy::reset();
        $this->store = new InMemoryPublishedLanguagesStore(['fr']);
        // The site has French projects: their archive is never the empty one.
        $this->catalog = new InMemoryContentCatalog();
        $this->catalog->add(Documents::project());
    }

    private function publication(): YoastPublication
    {
        return new YoastPublication(
            new PublishedLanguages($this->store),
            $this->catalog,
            fn (): string => $this->currentLanguage,
            static fn (string $path): string => self::HOME . $path,
            fn (): string => $this->currentFilter,
        );
    }

    private static function presentationOfPost(int $id): object
    {
        return (object) ['model' => (object) ['object_type' => 'post', 'object_sub_type' => 'project', 'object_id' => $id]];
    }

    private static function presentationOfArchive(string $postType): object
    {
        return (object) ['model' => (object) ['object_type' => YoastPublication::POST_TYPE_ARCHIVE, 'object_sub_type' => $postType, 'object_id' => 0]];
    }

    #[Test]
    public function the_archive_of_an_open_language_without_documents_of_its_own_is_noindex_follow(): void
    {
        $this->store->value = ['fr', 'de'];
        $this->currentLanguage = 'de';

        self::assertSame('noindex, follow', $this->publication()->robots(self::ROBOTS, self::presentationOfArchive('project')), 'only French cards under /de/projets/');
        self::assertSame('noindex, follow', $this->publication()->robots(self::ROBOTS, self::presentationOfArchive('article')), 'no article at all');

        $this->catalog->add(Documents::project(['id' => 42, 'slug' => 'momocad-de', 'lang' => Language::De]));

        self::assertSame(self::ROBOTS, $this->publication()->robots(self::ROBOTS, self::presentationOfArchive('project')), 'the rule lifts itself at the first German project');
        self::assertSame('noindex, follow', $this->publication()->robots(self::ROBOTS, self::presentationOfArchive('article')), 'each kind counts its own documents');
    }

    #[Test]
    public function the_french_archive_with_french_documents_keeps_its_directives(): void
    {
        self::assertSame(self::ROBOTS, $this->publication()->robots(self::ROBOTS, self::presentationOfArchive('project')));
    }

    #[Test]
    public function a_closed_language_is_noindex_before_its_documents_are_counted(): void
    {
        $this->currentLanguage = 'de';
        $this->catalog->add(Documents::project(['id' => 42, 'slug' => 'momocad-de', 'lang' => Language::De]));

        self::assertSame('noindex, follow', $this->publication()->robots(self::ROBOTS, self::presentationOfArchive('project')));
        self::assertSame([], $this->catalog->countOwnCalls, 'a closed language is decided without asking the catalog');
    }

    #[Test]
    public function the_count_of_own_documents_only_concerns_the_archives_of_the_kinds_the_plugin_knows(): void
    {
        $this->store->value = ['fr', 'de'];
        $this->currentLanguage = 'de';
        $noDocument = new InMemoryContentCatalog();
        $publication = new YoastPublication(new PublishedLanguages($this->store), $noDocument, fn (): string => $this->currentLanguage, static fn (string $path): string => self::HOME . $path);

        self::assertSame(self::ROBOTS, $publication->robots(self::ROBOTS, (object) ['model' => (object) ['object_type' => 'home-page']]), 'the home is not an archive');
        self::assertSame(self::ROBOTS, $publication->robots(self::ROBOTS, self::presentationOfArchive('product')), 'an archive of a post type the plugin does not serve');
        self::assertSame(self::ROBOTS, $publication->robots(self::ROBOTS, (object) ['model' => (object) ['object_type' => 'term', 'object_sub_type' => 'tech_tag']]), 'a term archive is not a post type archive');
        self::assertSame(self::ROBOTS, $publication->robots(self::ROBOTS, (object) ['model' => (object) ['object_type' => YoastPublication::POST_TYPE_ARCHIVE]]), 'no sub type, nothing to count');
    }

    #[Test]
    public function hooks_robots_the_sitemap_entries_and_the_archive_blocks_after_polylang(): void
    {
        $publication = $this->publication();
        $publication->register();

        $hooks = array_map(static fn (array $hook): array => [$hook['type'], $hook['hook'], $hook['callback'], $hook['priority'], $hook['args']], WordPressSpy::$hooks);
        self::assertSame([
            ['filter', 'wpseo_robots', [$publication, 'robots'], 10, 2],
            ['filter', 'wpseo_sitemap_entry', [$publication, 'sitemapEntry'], 10, 3],
            ['filter', 'wpseo_sitemap_article_content', [$publication, 'stripUnindexedArchives'], 20, 1],
            ['filter', 'wpseo_sitemap_project_content', [$publication, 'stripUnindexedArchives'], 20, 1],
            ['filter', 'wpseo_sitemap_post_content', [$publication, 'stripUnindexedArchives'], 20, 1],
        ], $hooks);
    }

    #[Test]
    public function a_view_of_a_closed_language_is_noindex_follow(): void
    {
        $this->currentLanguage = 'de';

        self::assertSame('noindex, follow', $this->publication()->robots(self::ROBOTS, (object) ['model' => (object) ['object_type' => 'home-page']]));

        $this->store->value = ['fr', 'de'];

        self::assertSame(self::ROBOTS, $this->publication()->robots(self::ROBOTS, (object) ['model' => (object) ['object_type' => 'home-page']]));
    }

    #[Test]
    public function a_document_follows_its_stored_language_not_the_language_of_the_request(): void
    {
        WordPressSpy::$postMetaValues[7] = [InternalMetaRegistrar::LANG => 'de'];
        WordPressSpy::$postMetaValues[8] = [InternalMetaRegistrar::LANG => 'fr'];
        $this->currentLanguage = 'fr';

        self::assertSame('noindex, follow', $this->publication()->robots(self::ROBOTS, self::presentationOfPost(7)), 'a German document on a French request');

        $this->currentLanguage = 'de';

        self::assertSame(self::ROBOTS, $this->publication()->robots(self::ROBOTS, self::presentationOfPost(8)), 'a French document on a German request');
    }

    #[Test]
    public function a_post_without_stored_language_follows_the_language_of_the_request(): void
    {
        $this->currentLanguage = 'de';

        self::assertSame('noindex, follow', $this->publication()->robots(self::ROBOTS, self::presentationOfPost(9)));
    }

    #[Test]
    public function a_request_without_language_leaves_the_directives_alone(): void
    {
        $this->currentLanguage = '';

        self::assertSame(self::ROBOTS, $this->publication()->robots(self::ROBOTS, null));
        self::assertFalse($this->publication()->robots(false, null));
    }

    #[Test]
    public function a_sitemap_entry_under_the_prefix_of_a_closed_language_is_dropped(): void
    {
        $publication = $this->publication();
        $german = ['loc' => self::HOME . '/de/projets/momocad-de/', 'mod' => '2026-11-08'];
        $french = ['loc' => self::HOME . '/fr/projets/momocad/', 'mod' => '2026-11-08'];

        self::assertSame([], $publication->sitemapEntry($german, 'post', null));
        self::assertSame($french, $publication->sitemapEntry($french, 'post', null));
        self::assertSame(['mod' => 'x'], $publication->sitemapEntry(['mod' => 'x'], 'post', null), 'no loc, nothing to judge');
        self::assertSame('not an array', $publication->sitemapEntry('not an array', 'post', null));
        self::assertSame(['loc' => self::HOME . '/fr/de/x/'], $publication->sitemapEntry(['loc' => self::HOME . '/fr/de/x/'], 'post', null), 'the prefix is matched at the start, not anywhere');

        $this->store->value = ['fr', 'de'];

        self::assertSame($german, $this->publication()->sitemapEntry($german, 'post', null));
    }

    #[Test]
    public function the_prefix_is_the_whole_language_folder(): void
    {
        $entry = ['loc' => self::HOME . '/dev/tools/'];

        self::assertSame($entry, $this->publication()->sitemapEntry($entry, 'post', null), '/dev/ is not /de/');
    }

    #[Test]
    public function the_archive_blocks_of_closed_languages_are_removed_from_the_sitemap_xml(): void
    {
        $block = static fn (string $loc): string => "<url>\n\t<loc>{$loc}</loc>\n\t<changefreq>weekly</changefreq>\n</url>\n";
        $xml = $block(self::HOME . '/de/projets/') . $block(self::HOME . '/en/projets/') . $block(self::HOME . '/fr/projets/');

        self::assertSame($block(self::HOME . '/fr/projets/'), $this->publication()->stripUnindexedArchives($xml), 'German and English closed: their blocks go');

        $this->store->value = ['fr', 'de'];
        $this->catalog->add(Documents::project(['id' => 42, 'slug' => 'momocad-de', 'lang' => Language::De]));

        self::assertSame($block(self::HOME . '/de/projets/') . $block(self::HOME . '/fr/projets/'), $this->publication()->stripUnindexedArchives($xml), 'German open with a project of its own: its block stays, English goes');
        self::assertSame('', $this->publication()->stripUnindexedArchives(''), 'nothing appended, nothing to strip');
        self::assertSame('not xml', $this->publication()->stripUnindexedArchives('not xml'));
    }

    #[Test]
    public function the_archive_block_of_an_open_language_without_documents_of_its_own_leaves_the_sitemap_too(): void
    {
        $block = static fn (string $loc): string => "<url><loc>{$loc}</loc><changefreq>weekly</changefreq></url>\n";
        $this->store->value = ['fr', 'de', 'en'];
        $xml = $block(self::HOME . '/de/projets/') . $block(self::HOME . '/de/projets/zz/') . $block(self::HOME . '/en/projets/') . $block(self::HOME . '/fr/projets/');

        self::assertSame($block(self::HOME . '/de/projets/zz/') . $block(self::HOME . '/fr/projets/'), $this->publication()->stripUnindexedArchives($xml), 'open without own project: the archive block goes, exactly it, the French one stays');

        $this->catalog->add(Documents::project(['id' => 42, 'slug' => 'momocad-de', 'lang' => Language::De]));

        self::assertSame($block(self::HOME . '/de/projets/') . $block(self::HOME . '/de/projets/zz/') . $block(self::HOME . '/fr/projets/'), $this->publication()->stripUnindexedArchives($xml), 'the first German project brings the block back');

        $this->currentFilter = 'wpseo_sitemap_article_content';
        $articles = $block(self::HOME . '/de/articles/') . $block(self::HOME . '/fr/articles/');

        self::assertSame('', $this->publication()->stripUnindexedArchives($articles), 'no article at all: every archive of articles goes, the French one included');

        $this->catalog->add(Documents::article());

        self::assertSame($block(self::HOME . '/fr/articles/'), $this->publication()->stripUnindexedArchives($articles), 'each kind counts its own documents');

        $this->currentFilter = 'wpseo_sitemap_post_content';

        self::assertSame($xml, $this->publication()->stripUnindexedArchives($xml), 'the sitemap of a kind the plugin does not serve only loses closed languages');
    }

    #[Test]
    public function a_nofollow_the_site_owner_set_survives_the_noindex(): void
    {
        $this->currentLanguage = 'de';

        self::assertSame('noindex, nofollow', $this->publication()->robots('index, nofollow, max-snippet:-1', (object) ['model' => (object) ['object_type' => 'home-page']]), 'closed language');

        $this->store->value = ['fr', 'de'];

        self::assertSame('noindex, nofollow', $this->publication()->robots('index, nofollow', self::presentationOfArchive('project')), 'archive without own document');
        self::assertSame('noindex, follow', $this->publication()->robots('index, follow', self::presentationOfArchive('project')));
        self::assertSame('noindex, follow', $this->publication()->robots(null, self::presentationOfArchive('project')), 'anything but a string reads as follow');
    }
}
