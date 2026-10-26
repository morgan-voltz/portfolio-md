<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Meta;

/**
 * Writes a post meta from PHP without losing backslashes.
 *
 * update_post_meta() runs wp_unslash() (stripslashes) on the value before
 * sanitizing and storing it, a leftover of the magic-quotes era that
 * WordPress keeps for backward compatibility. Written naively, a Markdown
 * source such as "\*not italic\*" or "C:\path" would silently lose its
 * backslashes, and the stored text would no longer match its SHA-256 hash.
 * WordPress's own REST layer compensates with wp_slash() before calling
 * update_metadata(); this helper does the same for plugin code.
 *
 * Every meta written from PHP (the Markdown pipeline above all) must go
 * through here rather than calling update_post_meta() directly.
 */
final class MetaWriter
{
    private function __construct()
    {
    }

    /**
     * Stores $value under $metaKey for the post, with the sanitize_callback
     * registered for that key applied by WordPress on the way.
     *
     * Returns what update_post_meta() returns: the meta id on first insert,
     * true on update, false on failure or when the value is unchanged.
     */
    public static function update(int $postId, string $metaKey, mixed $value): int|bool
    {
        return update_post_meta($postId, $metaKey, wp_slash($value));
    }
}
