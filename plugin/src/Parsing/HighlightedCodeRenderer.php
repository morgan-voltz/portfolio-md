<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use DomainException;
use Highlight\Highlighter;
use InvalidArgumentException;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;
use Stringable;

/**
 * Renders code blocks with server-side syntax highlighting
 * (docs/architecture/01-plugin-php.md §4.2).
 *
 * Registered with a higher priority than the core renderers of FencedCode
 * and IndentedCode, so that it replaces them. A fenced block whose info
 * string names a language known to highlight.php comes out as
 * `<pre><code class="hljs language-php">…spans…</code></pre>`, the markup
 * highlight.js produces in the browser; the theme only has to ship a
 * highlight.js stylesheet. An unknown language keeps its `language-*` class
 * with plain, escaped text; a block without language is plain as well.
 *
 * The highlighter loads its language definitions on first use, so building
 * the parser stays cheap for requests that never render Markdown.
 */
final class HighlightedCodeRenderer implements NodeRendererInterface
{
    /** Info words accepted as a language name; anything else means "no language". */
    private const LANGUAGE_PATTERN = '/^[a-z0-9][a-z0-9_+#.-]{0,39}$/';

    private ?Highlighter $highlighter = null;

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): Stringable
    {
        if (!$node instanceof FencedCode && !$node instanceof IndentedCode) {
            throw new InvalidArgumentException('Incompatible node type: ' . $node::class);
        }

        $code = $node->getLiteral();
        $language = $node instanceof FencedCode ? self::language($node) : null;

        if ($language === null) {
            return self::block([], Xml::escape($code));
        }

        try {
            $highlighted = $this->highlighter()->highlight($language, $code);
        } catch (DomainException) {
            // Unknown language: keep the class for the stylesheet or a client-side fallback.
            return self::block(['class' => 'language-' . $language], Xml::escape($code));
        }

        return self::block(['class' => 'hljs language-' . $language], (string) $highlighted->value);
    }

    private static function language(FencedCode $node): ?string
    {
        $words = $node->getInfoWords();
        $word = strtolower(trim($words[0] ?? ''));

        return preg_match(self::LANGUAGE_PATTERN, $word) === 1 ? $word : null;
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function block(array $attributes, string $html): HtmlElement
    {
        return new HtmlElement('pre', [], new HtmlElement('code', $attributes, $html));
    }

    private function highlighter(): Highlighter
    {
        return $this->highlighter ??= new Highlighter();
    }
}
