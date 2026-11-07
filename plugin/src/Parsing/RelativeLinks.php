<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;

/**
 * Links from one document to another, rewritten into links of the site.
 *
 * In the content repository a document links to another by its file,
 * `../projects/<slug>.md` for a document of the same language or
 * `../../<lang>/projects/<slug>.md` for one of another language
 * (content/README.md): readable on GitHub, checked by the validator,
 * meaningless on the site. Once rendered, such a link becomes the path of
 * that document on the site (ContentType::path()), `/fr/projets/<slug>/`,
 * prefixed with the language of the document or the language the link
 * names (ADR-020). An optional fragment is kept. Every other link
 * (absolute, external, an anchor, a file that is not a document, a
 * language the site does not have) is left alone.
 *
 * Works on the HTML the Markdown parser produced, whose attributes are
 * well formed and quoted with double quotes.
 */
final class RelativeLinks
{
    /**
     * `../<folder>/<slug>.md` or `../../<lang>/<folder>/<slug>.md`, optionally
     * `./`-prefixed, with an optional `#fragment`, as the value of an `href`
     * attribute (not of an attribute whose name merely ends in `href`).
     * Groups: 1 the language named by the link (empty when it names none),
     * 2 the folder, 3 the slug, 4 the fragment.
     */
    private const PATTERN = '~(?<=href=")(?<![\w-]href=")(?:\./)?\.\./(?:\.\./([a-z]{2})/)?(articles|projects|pages)/([a-z0-9]+(?:-[a-z0-9]+)*)\.md(#[^"]*)?(?=")~';

    private function __construct()
    {
    }

    /**
     * @param Language $lang the language of the document being rendered, which its
     *                       same-language links inherit
     */
    public static function rewrite(string $html, Language $lang): string
    {
        return (string) preg_replace_callback(self::PATTERN, static function (array $match) use ($lang): string {
            $type = ContentType::fromFolder($match[2]);
            $target = ($match[1] ?? '') === '' ? $lang : Language::tryFrom($match[1]);
            if ($type === null || $target === null) {
                return $match[0];
            }

            return $type->path($match[3], $target) . ($match[4] ?? '');
        }, $html);
    }
}
