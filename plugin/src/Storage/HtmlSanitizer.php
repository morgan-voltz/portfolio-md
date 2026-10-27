<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Storage;

/**
 * The kses pass every derived HTML goes through before it is stored
 * (01-plugin-php.md §11): the last line of defence behind the Markdown
 * parser, which already escapes raw HTML.
 *
 * Shared by the repository, which applies it to what it writes, and by the
 * preview route, which applies it to what it shows, so that the preview
 * never displays a tag the store would drop. On real content the pass is a
 * no-op, which the import smoke test checks.
 */
final class HtmlSanitizer
{
    /**
     * The `post` context of kses, plus the checkbox of a GFM task list,
     * which that context does not know.
     */
    public static function sanitize(string $html): string
    {
        $allowed = wp_kses_allowed_html('post');
        $allowed['input'] = ['type' => true, 'checked' => true, 'disabled' => true];

        return wp_kses($html, $allowed);
    }

    private function __construct()
    {
    }
}
