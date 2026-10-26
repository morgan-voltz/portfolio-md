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
 * (the classic screen hosts the Markdown box), the content editor and the
 * permalink editor are not shown, the slug and taxonomy boxes are removed,
 * and Quick Edit and Bulk Edit are withdrawn from the lists. The publish
 * box (status, visibility, date), the author box, the featured image of a
 * project, the revisions and the custom fields box stay.
 *
 * The content editor is removed from the edit screens only, not from the
 * post types: their REST representation keeps its `content` field.
 */
final class EditScreen
{
    private const POST_TYPES = [PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT];

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
        add_filter('get_sample_permalink_html', [$this, 'permalinkHtml'], 10, 5);
        add_filter('enter_title_here', [$this, 'titlePlaceholder'], 10, 2);
        add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
        add_action('load-post.php', [$this, 'trimCurrentScreen']);
        add_action('load-post-new.php', [$this, 'trimCurrentScreen']);
        add_action('add_meta_boxes', [$this, 'removeMetaBoxes'], 20);

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

    /** Filter `get_sample_permalink_html`: the slug comes from the document and is not edited under the title. */
    public function permalinkHtml(string $html, int $postId, ?string $newTitle, ?string $newSlug, ?WP_Post $post): string
    {
        return $post !== null && self::handles($post->post_type) ? '' : $html;
    }

    /** Filter `enter_title_here`: the title field is filled from the document at each save. */
    public function titlePlaceholder(string $text, WP_Post $post): string
    {
        return self::handles($post->post_type)
            ? __('Le titre vient de la ligne « title » du document Markdown', 'portfolio-md')
            : $text;
    }

    /**
     * Filter `post_row_actions`: Quick Edit would change the title, the slug,
     * the date or the terms behind the document's back.
     *
     * @param array<string, string> $actions
     *
     * @return array<string, string>
     */
    public function rowActions(array $actions, WP_Post $post): array
    {
        if (self::handles($post->post_type)) {
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

        $this->trimScreen($postType);
    }

    /**
     * The content editor is the document's job. Removing the support here,
     * once the screen is known, leaves the post type itself untouched.
     */
    public function trimScreen(string $postType): void
    {
        if (!self::handles($postType)) {
            return;
        }

        remove_post_type_support($postType, 'editor');
    }

    /** Action `add_meta_boxes`, after core registered its own boxes. */
    public function removeMetaBoxes(string $postType): void
    {
        if (!self::handles($postType)) {
            return;
        }

        foreach (self::META_BOXES as $id => $context) {
            remove_meta_box($id, $postType, $context);
        }
    }
}
