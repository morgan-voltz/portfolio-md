<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use RuntimeException;

/**
 * Phase three of the pipeline (docs/architecture/01-plugin-php.md §4.3):
 * standard HTML → HTML wrapped in Gutenberg block delimiters, the form
 * `post_content` must take for the block editor to recognise it.
 *
 * The HTML is parsed with DOMDocument and walked element by element. Each
 * block-level element becomes a core block, serialised the way Gutenberg
 * itself serialises it (wrapper classes included), so that the editor
 * validates the blocks instead of flagging "unexpected content":
 *
 *   h1…h6         → core/heading    (`level` attribute when not 2)
 *   p             → core/paragraph  (core/image when it only holds an image)
 *   ul / ol       → core/list       one core/list-item per li, nested lists included
 *   pre > code    → core/code       highlighting classes moved to the pre
 *   blockquote    → core/quote      its content becomes inner blocks
 *   table         → core/table      GFM alignment turned into Gutenberg classes
 *   hr            → core/separator
 *   anything else → core/html       raw HTML, still editable in the editor
 *
 * A list the editor could not represent (task-list checkboxes, several
 * paragraphs or a code block inside one item) falls back to core/html as a
 * whole rather than being flattened. Pure PHP: no WordPress function here.
 */
final class GutenbergTransformer
{
    /** Elements allowed inside a core/list-item next to the nested lists. */
    private const INLINE_TAGS = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'del', 'dfn', 'em', 'i', 'img', 'ins', 'kbd',
        'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr',
    ];

    /** Elements of a table whose whitespace-only text nodes carry no meaning. */
    private const TABLE_STRUCTURE_TAGS = ['table', 'thead', 'tbody', 'tfoot', 'tr'];

    public function transform(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        return implode("\n\n", $this->blocks(self::load($html)));
    }

    /**
     * Parses the fragment inside a wrapper element and returns that wrapper.
     * The XML declaration is how DOMDocument learns the fragment is UTF-8.
     */
    private static function load(string $html): DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        foreach ($document->childNodes as $node) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        throw new RuntimeException('DOMDocument produced no root element.');
    }

    /**
     * @return list<string>
     */
    private function blocks(DOMNode $parent): array
    {
        $blocks = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $blocks[] = $this->block($child);
            } elseif ($child instanceof DOMText && trim($child->wholeText) !== '') {
                // Bare text at block level never comes out of the Markdown parser; keep it readable anyway.
                $blocks[] = self::wrap('paragraph', [], '<p>' . trim(self::serialize($child)) . '</p>');
            }
        }

        return $blocks;
    }

    private function block(DOMElement $element): string
    {
        return match ($element->tagName) {
            'p' => $this->paragraph($element),
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => self::heading($element),
            'ul', 'ol' => self::isEditableList($element) ? $this->list($element) : self::html($element),
            'pre' => self::code($element),
            'blockquote' => $this->quote($element),
            'table' => self::table($element),
            'hr' => self::wrap('separator', [], '<hr class="wp-block-separator has-alpha-channel-opacity"/>'),
            default => self::html($element),
        };
    }

    private function paragraph(DOMElement $paragraph): string
    {
        $only = self::onlyElementChild($paragraph);
        if ($only?->tagName === 'img') {
            return self::image($only, null);
        }
        if ($only?->tagName === 'a') {
            $linked = self::onlyElementChild($only);
            if ($linked?->tagName === 'img') {
                return self::image($linked, $only->getAttribute('href'));
            }
        }

        return self::wrap('paragraph', [], '<p>' . self::inner($paragraph) . '</p>');
    }

    private static function heading(DOMElement $heading): string
    {
        $tag = $heading->tagName;
        $level = (int) substr($tag, 1);

        return self::wrap(
            'heading',
            $level === 2 ? [] : ['level' => $level],
            "<{$tag} class=\"wp-block-heading\">" . self::inner($heading) . "</{$tag}>",
        );
    }

    private static function image(DOMElement $img, ?string $href): string
    {
        $tag = '<img src="' . self::attribute($img->getAttribute('src')) . '" alt="' . self::attribute($img->getAttribute('alt')) . '"';
        if ($img->hasAttribute('title')) {
            $tag .= ' title="' . self::attribute($img->getAttribute('title')) . '"';
        }
        $tag .= '/>';

        $attributes = [];
        if ($href !== null && $href !== '') {
            $tag = '<a href="' . self::attribute($href) . '">' . $tag . '</a>';
            $attributes['linkDestination'] = 'custom';
        }

        return self::wrap('image', $attributes, '<figure class="wp-block-image">' . $tag . '</figure>');
    }

    /**
     * True when every item holds inline content, at most one paragraph
     * (a "loose" Markdown list) and nested lists that pass the same test.
     * Task-list checkboxes are inputs, which the editor cannot keep in a
     * list item.
     */
    private static function isEditableList(DOMElement $list): bool
    {
        if ($list->getElementsByTagName('input')->length > 0) {
            return false;
        }

        foreach ($list->childNodes as $item) {
            if (!$item instanceof DOMElement) {
                continue;
            }
            if ($item->tagName !== 'li') {
                return false;
            }

            $paragraphs = 0;
            foreach ($item->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                if ($child->tagName === 'ul' || $child->tagName === 'ol') {
                    if (!self::isEditableList($child)) {
                        return false;
                    }
                } elseif ($child->tagName === 'p') {
                    if (++$paragraphs > 1) {
                        return false;
                    }
                } elseif (!in_array($child->tagName, self::INLINE_TAGS, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function list(DOMElement $list): string
    {
        $tag = $list->tagName;
        $attributes = $tag === 'ol' ? ['ordered' => true] : [];
        $open = "<{$tag} class=\"wp-block-list\"";
        if ($tag === 'ol' && $list->hasAttribute('start') && $list->getAttribute('start') !== '1') {
            $start = (int) $list->getAttribute('start');
            $attributes['start'] = $start;
            $open .= " start=\"{$start}\"";
        }
        $open .= '>';

        $items = [];
        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === 'li') {
                $items[] = $this->listItem($child);
            }
        }

        return self::wrap('list', $attributes, $open . implode("\n\n", $items) . "</{$tag}>");
    }

    /**
     * Gutenberg stores an item as `<li>inline content<!-- wp:list -->…<!-- /wp:list --></li>`:
     * the text first, the nested lists right after it, no whitespace in between.
     */
    private function listItem(DOMElement $item): string
    {
        $inline = '';
        $nested = '';
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement && ($child->tagName === 'ul' || $child->tagName === 'ol')) {
                $nested .= $this->list($child);
            } elseif ($child instanceof DOMElement && $child->tagName === 'p') {
                $inline .= self::inner($child);
            } else {
                $inline .= self::serialize($child);
            }
        }

        return self::wrap('list-item', [], '<li>' . trim($inline) . $nested . '</li>');
    }

    private static function code(DOMElement $pre): string
    {
        $code = self::onlyElementChild($pre);
        if ($code?->tagName !== 'code') {
            return self::html($pre);
        }

        $classes = trim($code->getAttribute('class'));
        $attributes = $classes === '' ? [] : ['className' => $classes];
        $preClass = trim('wp-block-code ' . $classes);

        // Gutenberg escapes "[" in code blocks so that WordPress never reads a shortcode in them.
        $content = str_replace('[', '&#91;', self::inner($code));

        return self::wrap('code', $attributes, '<pre class="' . self::attribute($preClass) . '"><code>' . $content . '</code></pre>');
    }

    private function quote(DOMElement $quote): string
    {
        return self::wrap(
            'quote',
            [],
            '<blockquote class="wp-block-quote">' . implode("\n\n", $this->blocks($quote)) . '</blockquote>',
        );
    }

    private static function table(DOMElement $table): string
    {
        foreach (['th', 'td'] as $tag) {
            /** @var DOMElement $cell */
            foreach (iterator_to_array($table->getElementsByTagName($tag)) as $cell) {
                if (!$cell->hasAttribute('align')) {
                    continue;
                }
                $align = strtolower($cell->getAttribute('align'));
                $cell->removeAttribute('align');
                if (in_array($align, ['left', 'center', 'right'], true)) {
                    $cell->setAttribute('class', trim($cell->getAttribute('class') . ' has-text-align-' . $align));
                }
            }
        }
        self::stripStructuralWhitespace($table);

        return self::wrap('table', ['hasFixedLayout' => false], '<figure class="wp-block-table">' . self::serialize($table) . '</figure>');
    }

    /**
     * The Markdown parser puts newlines between the rows and sections of a
     * table; Gutenberg writes none. They mean nothing, so they go.
     */
    private static function stripStructuralWhitespace(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMText && trim($child->wholeText) === '') {
                $element->removeChild($child);
            } elseif ($child instanceof DOMElement && in_array($child->tagName, self::TABLE_STRUCTURE_TAGS, true)) {
                self::stripStructuralWhitespace($child);
            }
        }
    }

    private static function html(DOMElement $element): string
    {
        return self::wrap('html', [], self::serialize($element));
    }

    /**
     * The single element child of a node, when it has exactly one child
     * besides whitespace; null otherwise.
     */
    private static function onlyElementChild(DOMNode $node): ?DOMElement
    {
        $found = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->wholeText) === '') {
                continue;
            }
            if (!$child instanceof DOMElement || $found !== null) {
                return null;
            }
            $found = $child;
        }

        return $found;
    }

    /**
     * @param array<string, scalar> $attributes
     */
    private static function wrap(string $name, array $attributes, string $content): string
    {
        $json = $attributes === [] ? '' : ' ' . self::encodeAttributes($attributes);

        return "<!-- wp:{$name}{$json} -->\n{$content}\n<!-- /wp:{$name} -->";
    }

    /**
     * Same escapes as Gutenberg's own serializer: nothing inside a block
     * comment may close it or read as markup.
     *
     * @param array<string, scalar> $attributes
     */
    private static function encodeAttributes(array $attributes): string
    {
        $json = json_encode($attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return str_replace(
            ['--', '<', '>', '&', '\\"'],
            ['\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022'],
            $json,
        );
    }

    private static function attribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function serialize(DOMNode $node): string
    {
        $html = $node->ownerDocument?->saveHTML($node);

        return is_string($html) ? $html : '';
    }

    private static function inner(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= self::serialize($child);
        }

        return $html;
    }
}
