<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Admin\MarkdownPosts;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;
use WP_Post;

/**
 * Which posts the document edits: the plugin's types always, a page when it
 * holds a document, nothing else.
 */
#[CoversClass(MarkdownPosts::class)]
final class MarkdownPostsTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressSpy::reset();
        WordPressSpy::$postMetaValues[9][InternalMetaRegistrar::MD_HASH] = str_repeat('d', 64);
    }

    #[Test]
    public function a_post_holds_a_document_when_its_hash_is_stored(): void
    {
        self::assertTrue(MarkdownPosts::holdsDocument(9));
        self::assertFalse(MarkdownPosts::holdsDocument(10));
    }

    #[Test]
    public function the_document_edits_the_plugin_types_always_and_a_page_that_holds_one(): void
    {
        self::assertTrue(MarkdownPosts::editsDocument(new WP_Post(['ID' => 10, 'post_type' => 'article'])));
        self::assertTrue(MarkdownPosts::editsDocument(new WP_Post(['ID' => 10, 'post_type' => 'project'])));
        self::assertTrue(MarkdownPosts::editsDocument(new WP_Post(['ID' => 9, 'post_type' => 'page'])));
        self::assertFalse(MarkdownPosts::editsDocument(new WP_Post(['ID' => 10, 'post_type' => 'page'])));
        self::assertFalse(MarkdownPosts::editsDocument(new WP_Post(['ID' => 9, 'post_type' => 'post'])));
    }

    #[Test]
    public function a_request_without_post_only_matches_the_plugin_types(): void
    {
        self::assertTrue(MarkdownPosts::editsDocumentOf('article', 0));
        self::assertFalse(MarkdownPosts::editsDocumentOf('page', 0));
        self::assertTrue(MarkdownPosts::editsDocumentOf('page', 9));
        self::assertFalse(MarkdownPosts::editsDocumentOf('page', 10));
    }

    #[Test]
    public function reads_the_stored_language_and_tells_a_translation_apart(): void
    {
        WordPressSpy::$postMetaValues[9][InternalMetaRegistrar::LANG] = 'de';
        WordPressSpy::$postMetaValues[10][InternalMetaRegistrar::LANG] = 'fr';
        WordPressSpy::$postMetaValues[11][InternalMetaRegistrar::LANG] = 'xx';

        self::assertSame(Language::De, MarkdownPosts::storedLanguage(9));
        self::assertTrue(MarkdownPosts::isTranslation(9));
        self::assertSame(Language::Fr, MarkdownPosts::storedLanguage(10));
        self::assertFalse(MarkdownPosts::isTranslation(10));
        self::assertNull(MarkdownPosts::storedLanguage(11), 'a value the plugin never wrote is no language');
        self::assertNull(MarkdownPosts::storedLanguage(12), 'a post made by hand stores no language');
        self::assertFalse(MarkdownPosts::isTranslation(12));
    }
}
