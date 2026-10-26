<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\UnexpectedEncodingException;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Util\HtmlFilter;

/**
 * Phase two of the pipeline (docs/architecture/01-plugin-php.md §4.2):
 * Markdown body → standard HTML.
 *
 * GitHub Flavored Markdown (ADR-002): tables, task lists, strikethrough and
 * autolinks on top of CommonMark. Code blocks are highlighted on the server
 * by HighlightedCodeRenderer. Raw HTML written in the Markdown is escaped
 * rather than passed through (content/README.md forbids it, 01-plugin-php.md
 * §11 says why: the mistake stays visible instead of vanishing), and links
 * with unsafe schemes (javascript:, vbscript:, file:, data:) lose their href.
 *
 * Pure PHP, no WordPress. The HTML produced here knows nothing about
 * Gutenberg: that is GutenbergTransformer's job.
 *
 * The library refuses a body that is not valid UTF-8 (a stray Windows-1252
 * byte is enough); that refusal is translated into the pipeline's own
 * MalformedDocumentException so that callers see one family of errors.
 */
final class MarkdownParser
{
    /** A deeply nested document is a denial-of-service vector; real content stays far below. */
    private const MAX_NESTING_LEVEL = 100;

    /** Above the core renderers (priority 0), so that ours is asked first. */
    private const CODE_RENDERER_PRIORITY = 10;

    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => HtmlFilter::ESCAPE,
            'allow_unsafe_links' => false,
            'max_nesting_level' => self::MAX_NESTING_LEVEL,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());

        $codeRenderer = new HighlightedCodeRenderer();
        $environment->addRenderer(FencedCode::class, $codeRenderer, self::CODE_RENDERER_PRIORITY);
        $environment->addRenderer(IndentedCode::class, $codeRenderer, self::CODE_RENDERER_PRIORITY);

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * @throws MalformedDocumentException when the Markdown is not valid UTF-8
     */
    public function render(string $markdown): string
    {
        try {
            return $this->converter->convert($markdown)->getContent();
        } catch (UnexpectedEncodingException) {
            throw MalformedDocumentException::invalidEncoding();
        }
    }
}
