<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use Voltz\PortfolioMd\Domain\ContentType;

/**
 * Links from one document to another, rewritten into links of the site.
 *
 * In the content repository a document links to another by its file,
 * `../projects/<slug>.md` (content/README.md): readable on GitHub, checked
 * by the validator, meaningless on the site. Once rendered, such a link
 * becomes the path of that document on the site (ContentType::path()),
 * `/projets/<slug>/`, which holds whatever the host is. An optional
 * fragment is kept. Every other link (absolute, external, an anchor, a
 * file that is not a document) is left alone.
 *
 * Works on the HTML the Markdown parser produced, whose attributes are
 * well formed and quoted with double quotes.
 */
final class RelativeLinks
{
    /**
     * `../<folder>/<slug>.md`, optionally `./`-prefixed, with an optional
     * `#fragment`, as the value of an `href` attribute (not of an attribute
     * whose name merely ends in `href`).
     */
    private const PATTERN = '~(?<=href=")(?<![\w-]href=")(?:\./)?\.\./(articles|projects|pages)/([a-z0-9]+(?:-[a-z0-9]+)*)\.md(#[^"]*)?(?=")~';

    private function __construct()
    {
    }

    public static function rewrite(string $html): string
    {
        return (string) preg_replace_callback(self::PATTERN, static function (array $match): string {
            $type = ContentType::fromFolder($match[1]);
            if ($type === null) {
                return $match[0];
            }

            return $type->path($match[2]) . ($match[3] ?? '');
        }, $html);
    }
}
