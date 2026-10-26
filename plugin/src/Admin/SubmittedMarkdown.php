<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Admin;

/**
 * What a browser hands back from a <textarea> is not quite the text that
 * was pasted into it: form submission turns every line break into CRLF,
 * and the final newline every file of content/ ends with may be missing.
 * The hash of ADR-005 covers the document byte for byte, so without this
 * normalisation a document saved from the admin would never match its Git
 * file again, and the next `wp portfolio import` would report a conflict
 * for a text that did not change.
 */
final class SubmittedMarkdown
{
    private function __construct()
    {
    }

    public static function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($text !== '' && !str_ends_with($text, "\n")) {
            $text .= "\n";
        }

        return $text;
    }
}
