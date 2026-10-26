<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

/**
 * Phases two and three of the pipeline in one call: Markdown body →
 * Gutenberg HTML. It is what the import service depends on, and the owner
 * of the render version stored next to every post.
 */
final class ContentRenderer
{
    /**
     * Version of the rendering chain, written to `_portfolio_render_version`
     * (semver). Bump it whenever MarkdownParser or GutenbergTransformer
     * changes its output: content whose stored version differs is rendered
     * again at the next import even if its Markdown did not change.
     */
    public const VERSION = '1.0.0';

    public function __construct(
        private readonly MarkdownParser $parser,
        private readonly GutenbergTransformer $transformer,
    ) {
    }

    public function render(string $markdownBody): string
    {
        return $this->transformer->transform($this->parser->render($markdownBody));
    }
}
