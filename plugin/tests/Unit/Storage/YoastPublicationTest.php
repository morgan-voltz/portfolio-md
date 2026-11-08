<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Storage\YoastPublication;
use Voltz\PortfolioMd\Tests\Support\InMemoryPublishedLanguagesStore;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

/**
 * What Yoast must not index or list about a closed language (ADR-022,
 * point 4): robots, sitemap entries and the archive blocks of Polylang.
 */
#[CoversClass(YoastPublication::class)]
final class YoastPublicationTest extends TestCase
{
    private const HOME = 'http://example.test';
    private const ROBOTS = 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1';

    private InMemoryPublishedLanguagesStore $store;
    private string $currentLanguage = 'fr';

    protected function setUp(): void
    {
        WordPressSpy::reset();
        $this->store = new InMemoryPublishedLanguagesStore(['fr']);
    }

    private function publication(): YoastPublication
    {
        return new YoastPublication(
            new PublishedLanguages($this->store),
            fn (): string => $this->currentLanguage,
            static fn (string $path): string => self::HOME . $path,
        );
    }

    private static function presentationOfPost(int $id): object
    {
        return (object) ['model' => (object) ['object_type' => 'post', 'object_sub_type' => 'project', 'object_id' => $id]];
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
            ['filter', 'wpseo_sitemap_article_content', [$publication, 'stripClosedArchives'], 20, 1],
            ['filter', 'wpseo_sitemap_project_content', [$publication, 'stripClosedArchives'], 20, 1],
            ['filter', 'wpseo_sitemap_post_content', [$publication, 'stripClosedArchives'], 20, 1],
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
        $block = static fn (string $loc): string => "<url>\n\t<loc>{$loc}</loc>\n\t<lastmod>2026-11-08T00:00:00+00:00</lastmod>\n\t<changefreq>daily</changefreq>\n\t<priority>1</priority>\n</url>\n";
        $xml = $block(self::HOME . '/de/projets/') . $block(self::HOME . '/en/projets/') . $block(self::HOME . '/fr/projets/');

        self::assertSame($block(self::HOME . '/fr/projets/'), $this->publication()->stripClosedArchives($xml));

        $this->store->value = ['fr', 'de'];

        self::assertSame($block(self::HOME . '/de/projets/') . $block(self::HOME . '/fr/projets/'), $this->publication()->stripClosedArchives($xml));
        self::assertSame('', $this->publication()->stripClosedArchives(''));
    }
}
