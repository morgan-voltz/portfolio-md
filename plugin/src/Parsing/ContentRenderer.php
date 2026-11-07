<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use Voltz\PortfolioMd\Domain\Language;

/**
 * Phases two and three of the pipeline in one call: Markdown body →
 * Gutenberg HTML, with the links between documents rewritten into links
 * of the site in between. It is what the import service depends on, and
 * the owner of the render version stored next to every post.
 */
final class ContentRenderer
{
    /**
     * Version of the rendering chain, written to `_portfolio_render_version`
     * (semver). Bump it whenever MarkdownParser or GutenbergTransformer
     * changes its output: content whose stored version differs is rendered
     * again at the next import even if its Markdown did not change.
     * 1.2.0: the links between documents carry the language prefix of the
     * site's URL tree (ADR-020).
     */
    public const VERSION = '1.2.0';

    public function __construct(
        private readonly MarkdownParser $parser,
        private readonly GutenbergTransformer $transformer,
    ) {
    }

    /**
     * @param Language $lang the language of the document: its links to other documents
     *                       are rewritten into the URL tree of that language
     */
    public function render(string $markdownBody, Language $lang): string
    {
        return $this->transformer->transform(RelativeLinks::rewrite($this->parser->render($markdownBody), $lang));
    }
}
