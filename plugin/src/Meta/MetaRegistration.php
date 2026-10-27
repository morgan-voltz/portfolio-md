<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Meta;

use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;

/**
 * Helpers shared by the three meta registrars.
 *
 * Kept deliberately tiny: a list of the post types the plugin owns, a loop
 * to register one meta on several post types, and the authorization callback
 * used by every meta that must stay editable through the REST API.
 */
final class MetaRegistration
{
    /**
     * Post types that carry the plugin's metas. Article-only or project-only
     * metas use `PostTypeRegistrar::ARTICLE` / `::PROJECT` directly.
     */
    public const PORTFOLIO_POST_TYPES = [PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT];

    /**
     * Post types that can hold a Markdown document: the plugin's two, and
     * the native page (content/pages). They carry the internal metas and
     * the SEO description; the other public metas stay on the plugin's own
     * types, where they mean something.
     */
    public const MARKDOWN_POST_TYPES = [PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT, PostTypeRegistrar::PAGE];

    private function __construct()
    {
    }

    /**
     * Registers the same meta definition on each given post type.
     *
     * Registering per post type (rather than passing '' as post type, which
     * means "every post type") keeps the metas off native posts, and off
     * pages for the metas that pages do not carry.
     *
     * @param list<string>         $postTypes
     * @param array<string, mixed> $args      Arguments for `register_post_meta()`.
     */
    public static function registerForPostTypes(array $postTypes, string $metaKey, array $args): void
    {
        foreach ($postTypes as $postType) {
            register_post_meta($postType, $metaKey, $args);
        }
    }

    /**
     * `auth_callback` for metas that users may edit through Gutenberg or REST.
     *
     * All plugin meta keys start with an underscore, which WordPress treats as
     * "protected". For protected keys `register_meta()` defaults the
     * `auth_callback` to `__return_false`, so without this callback the metas
     * would be readable in REST but rejected on write for everyone, including
     * administrators. The rule restored here is the natural one: whoever may
     * edit the post may edit its metas.
     *
     * WordPress calls the callback with six arguments; only the first four
     * are needed and PHP tolerates the extra ones.
     */
    public static function authorizeEditPost(bool $allowed, string $metaKey, int $postId, int $userId): bool
    {
        return user_can($userId, 'edit_post', $postId);
    }
}
