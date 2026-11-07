<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Admin;

use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use WP_Post;

/**
 * Which posts the Markdown document is the editor of.
 *
 * Articles and projects always: the document is their only source. Native
 * pages only when they hold a document (content/fr/pages, imported or
 * pasted): a page made by hand in the block editor, a contact page say,
 * stays a normal page. The mark of a held document is the stored hash,
 * written by the repository along with the document and by no user.
 */
final class MarkdownPosts
{
    private function __construct()
    {
    }

    /** Whether this post holds an imported document. */
    public static function holdsDocument(int $postId): bool
    {
        $hash = get_post_meta($postId, InternalMetaRegistrar::MD_HASH, true);

        return is_string($hash) && $hash !== '';
    }

    /**
     * The language the plugin stored for this post (ADR-020), or null for a
     * post made by hand or not imported since the plugin stores languages.
     */
    public static function storedLanguage(int $postId): ?Language
    {
        $lang = get_post_meta($postId, InternalMetaRegistrar::LANG, true);

        return is_string($lang) ? Language::tryFrom($lang) : null;
    }

    /**
     * Whether this post is a translation: a stored language that is not the
     * site's. Git is the only writer of translations (ADR-020), so the box
     * shows such a document without letting anyone save it.
     */
    public static function isTranslation(int $postId): bool
    {
        $lang = self::storedLanguage($postId);

        return $lang !== null && !$lang->isDefault();
    }

    /** Whether the Markdown box, and not the block editor, edits this post. */
    public static function editsDocument(WP_Post $post): bool
    {
        return self::editsDocumentOf($post->post_type, (int) $post->ID);
    }

    /**
     * Same, from a post type and an id: what an edit screen request gives
     * before the post is loaded (0 when the request creates the post).
     */
    public static function editsDocumentOf(string $postType, int $postId): bool
    {
        if (EditScreen::handles($postType)) {
            return true;
        }

        return $postType === PostTypeRegistrar::PAGE && $postId > 0 && self::holdsDocument($postId);
    }
}
