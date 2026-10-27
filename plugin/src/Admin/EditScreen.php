<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Admin;

use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;
use WP_Post;

/**
 * The edit screen of an article or a project, pared down to what the
 * Markdown does not own (01-plugin-php.md §6.1).
 *
 * The document is the source of the title, the slug, the date, the terms
 * and the public metas of these posts: the pipeline rewrites them from it
 * at every save. Every native control that would let the same things be
 * edited next to the document is therefore taken away, so that nothing
 * drifts from the Markdown behind its back. The block editor is turned off
 * (the classic screen hosts the Markdown box), the title field and the
 * content editor are not shown, the permalink editor is blanked, the slug
 * and taxonomy boxes are removed, and Quick Edit and Bulk Edit are
 * withdrawn from the lists. The publish box (status, visibility, date),
 * the author box, the featured image of a project, the revisions and the
 * custom fields box stay.
 *
 * The title field and the content editor are removed from the edit screens
 * only, not from the post types: their REST representation keeps its
 * `title` and `content` fields.
 *
 * A native page gets the same treatment when it holds a document
 * (MarkdownPosts), post by post: the block editor is turned off for that
 * page, its screen loses the title field, the content editor, the
 * permalink editor and the slug box, and Quick Edit. Pages made by hand
 * keep everything, and so does the list's Bulk Edit, shared by both kinds.
 */
final class EditScreen
{
    private const POST_TYPES = [PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT];

    /** Post type features whose screen controls the document replaces. */
    private const SCREEN_FEATURES = ['editor', 'title'];

    /** Core meta boxes that duplicate frontmatter fields, with the context they are registered in. */
    private const META_BOXES = [
        'slugdiv' => 'normal',
        'tagsdiv-' . TaxonomyRegistrar::TECH_TAG => 'side',
        'tagsdiv-' . TaxonomyRegistrar::PROJECT_STATUS => 'side',
        TaxonomyRegistrar::SKILL . 'div' => 'side',
    ];

    public function hook(): void
    {
        add_filter('use_block_editor_for_post_type', [$this, 'useBlockEditor'], 10, 2);
        add_filter('use_block_editor_for_post', [$this, 'useBlockEditorForPost'], 10, 2);
        add_filter('get_sample_permalink_html', [$this, 'permalinkHtml'], 10, 5);
        add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
        // Hierarchical types (pages) go through their own row actions filter.
        add_filter('page_row_actions', [$this, 'rowActions'], 10, 2);
        add_action('load-post.php', [$this, 'trimCurrentScreen']);
        add_action('load-post-new.php', [$this, 'trimCurrentScreen']);
        add_action('add_meta_boxes', [$this, 'removeMetaBoxes'], 20, 2);

        foreach (self::POST_TYPES as $postType) {
            add_filter("bulk_actions-edit-{$postType}", [$this, 'bulkActions']);
        }
    }

    public static function handles(string $postType): bool
    {
        return in_array($postType, self::POST_TYPES, true);
    }

    /** Filter `use_block_editor_for_post_type`: the classic screen hosts the Markdown box. */
    public function useBlockEditor(bool $useBlockEditor, string $postType): bool
    {
        return self::handles($postType) ? false : $useBlockEditor;
    }

    /** Filter `use_block_editor_for_post`: a page that holds a document is edited as its document. */
    public function useBlockEditorForPost(bool $useBlockEditor, WP_Post $post): bool
    {
        return MarkdownPosts::editsDocument($post) ? false : $useBlockEditor;
    }

    /**
     * The post an edit screen request is about: `post.php?post=N` when the
     * screen is shown, `post_ID` when its form comes back, 0 for a new post.
     */
    public static function requestedPostId(): int
    {
        $id = $_GET['post'] ?? $_POST['post_ID'] ?? 0;

        return is_scalar($id) ? (int) $id : 0;
    }

    /**
     * Filter `get_sample_permalink_html`: the slug comes from the document
     * and is not edited under the title (which the screen no longer shows;
     * the filter also covers the ajax path that refreshes that box).
     */
    public function permalinkHtml(string $html, int $postId, ?string $newTitle, ?string $newSlug, ?WP_Post $post): string
    {
        return $post !== null && MarkdownPosts::editsDocument($post) ? '' : $html;
    }

    /**
     * Filters `post_row_actions` and `page_row_actions`: Quick Edit would
     * change the title, the slug, the date or the terms behind the
     * document's back.
     *
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function rowActions(array $actions, WP_Post $post): array
    {
        if (MarkdownPosts::editsDocument($post)) {
            unset($actions['inline hide-if-no-js']);
        }

        return $actions;
    }

    /**
     * Filter `bulk_actions-edit-{type}`: Bulk Edit would change terms and status in bulk.
     *
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function bulkActions(array $actions): array
    {
        unset($actions['edit']);

        return $actions;
    }

    /** Actions `load-post.php` and `load-post-new.php`. */
    public function trimCurrentScreen(): void
    {
        $screen = get_current_screen();
        $postType = is_object($screen) && isset($screen->post_type) ? (string) $screen->post_type : '';

        $this->trimScreen($postType, self::requestedPostId());
    }

    /**
     * The title and the content are the document's job. Removing the
     * supports here, once the screen is known, leaves the post type itself
     * untouched (for a page, the next request has them back); WordPress
     * then keeps the stored title when the form comes back without one.
     */
    public function trimScreen(string $postType, int $postId = 0): void
    {
        if (!MarkdownPosts::editsDocumentOf($postType, $postId)) {
            return;
        }

        foreach (self::SCREEN_FEATURES as $feature) {
            remove_post_type_support($postType, $feature);
        }
    }

    /**
     * Action `add_meta_boxes`, after core registered its own boxes. A page
     * that holds a document loses its slug box only: it has no taxonomy of
     * ours, and its attributes (parent, template, order) are not the
     * document's.
     */
    public function removeMetaBoxes(string $postType, ?WP_Post $post = null): void
    {
        if (self::handles($postType)) {
            foreach (self::META_BOXES as $id => $context) {
                remove_meta_box($id, $postType, $context);
            }

            return;
        }

        if ($post instanceof WP_Post && MarkdownPosts::editsDocument($post)) {
            remove_meta_box('slugdiv', $postType, 'normal');
        }
    }
}
