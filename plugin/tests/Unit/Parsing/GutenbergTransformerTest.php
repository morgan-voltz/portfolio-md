<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;

/**
 * The expected strings are byte for byte what Gutenberg itself writes for
 * these blocks (WordPress 7.1): that is the whole point of the transformer.
 */
#[CoversClass(GutenbergTransformer::class)]
final class GutenbergTransformerTest extends TestCase
{
    private GutenbergTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new GutenbergTransformer();
    }

    #[Test]
    public function wraps_a_paragraph(): void
    {
        self::assertSame(
            "<!-- wp:paragraph -->\n<p>Hello <strong>world</strong></p>\n<!-- /wp:paragraph -->",
            $this->transformer->transform("<p>Hello <strong>world</strong></p>\n"),
        );
    }

    #[Test]
    public function separates_blocks_with_a_blank_line(): void
    {
        self::assertSame(
            "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->",
            $this->transformer->transform("<p>One</p>\n<p>Two</p>\n"),
        );
    }

    #[Test]
    public function maps_headings_with_their_level(): void
    {
        self::assertSame(
            "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Deux <code>x</code></h2>\n<!-- /wp:heading -->"
            . "\n\n<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Trois</h3>\n<!-- /wp:heading -->"
            . "\n\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Un</h1>\n<!-- /wp:heading -->",
            $this->transformer->transform("<h2>Deux <code>x</code></h2>\n<h3>Trois</h3>\n<h1>Un</h1>\n"),
        );
    }

    #[Test]
    public function maps_an_unordered_list_to_list_items(): void
    {
        self::assertSame(
            "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->"
            . "\n\n<!-- wp:list-item -->\n<li>Two <em>b</em></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
            $this->transformer->transform("<ul>\n<li>One</li>\n<li>Two <em>b</em></li>\n</ul>\n"),
        );
    }

    #[Test]
    public function maps_an_ordered_list_with_its_start(): void
    {
        self::assertSame(
            "<!-- wp:list {\"ordered\":true,\"start\":3} -->\n<ol class=\"wp-block-list\" start=\"3\"><!-- wp:list-item -->\n<li>Three</li>\n<!-- /wp:list-item --></ol>\n<!-- /wp:list -->",
            $this->transformer->transform("<ol start=\"3\">\n<li>Three</li>\n</ol>\n"),
        );
        self::assertStringStartsWith(
            "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">",
            $this->transformer->transform("<ol>\n<li>One</li>\n</ol>\n"),
        );
    }

    #[Test]
    public function nests_lists_inside_their_item(): void
    {
        self::assertSame(
            "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Parent<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>Child</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
            $this->transformer->transform("<ul>\n<li>Parent\n<ul>\n<li>Child</li>\n</ul>\n</li>\n</ul>\n"),
        );
    }

    #[Test]
    public function unwraps_the_paragraph_of_a_loose_list_item(): void
    {
        self::assertSame(
            "<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>One</li>\n<!-- /wp:list-item -->"
            . "\n\n<!-- wp:list-item -->\n<li>Two</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
            $this->transformer->transform("<ul>\n<li>\n<p>One</p>\n</li>\n<li>\n<p>Two</p>\n</li>\n</ul>\n"),
        );
    }

    #[Test]
    public function keeps_a_task_list_as_raw_html(): void
    {
        $html = $this->transformer->transform("<ul>\n<li><input checked=\"\" disabled=\"\" type=\"checkbox\"> Done</li>\n</ul>\n");

        self::assertStringStartsWith("<!-- wp:html -->\n<ul>", $html);
        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringEndsWith("</ul>\n<!-- /wp:html -->", $html);
    }

    #[Test]
    public function keeps_a_list_with_block_content_in_an_item_as_raw_html(): void
    {
        self::assertStringStartsWith(
            "<!-- wp:html -->\n<ul>",
            $this->transformer->transform("<ul>\n<li>\n<p>One</p>\n<p>Two</p>\n</li>\n</ul>\n"),
        );
        self::assertStringStartsWith(
            "<!-- wp:html -->\n<ul>",
            $this->transformer->transform("<ul>\n<li>\n<p>One</p>\n<pre><code>x</code></pre>\n</li>\n</ul>\n"),
        );
    }

    #[Test]
    public function maps_a_highlighted_code_block_with_its_classes_on_the_pre(): void
    {
        self::assertSame(
            "<!-- wp:code {\"className\":\"hljs language-php\"} -->\n<pre class=\"wp-block-code hljs language-php\"><code><span class=\"hljs-keyword\">echo</span> \$a&#91;0];\n</code></pre>\n<!-- /wp:code -->",
            $this->transformer->transform("<pre><code class=\"hljs language-php\"><span class=\"hljs-keyword\">echo</span> \$a[0];\n</code></pre>\n"),
        );
    }

    #[Test]
    public function maps_a_plain_code_block(): void
    {
        self::assertSame(
            "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>a &lt; b\n</code></pre>\n<!-- /wp:code -->",
            $this->transformer->transform("<pre><code>a &lt; b\n</code></pre>\n"),
        );
    }

    #[Test]
    public function escapes_the_first_bare_url_line_of_a_code_block_like_gutenberg(): void
    {
        self::assertSame(
            "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>curl -s https://example.org/api\nhttps:&#47;&#47;example.org/demo\nhttps://example.org/second\n</code></pre>\n<!-- /wp:code -->",
            $this->transformer->transform("<pre><code>curl -s https://example.org/api\nhttps://example.org/demo\nhttps://example.org/second\n</code></pre>\n"),
        );
    }

    #[Test]
    public function escapes_a_class_name_the_way_gutenberg_does(): void
    {
        // A double hyphen would close the HTML comment; Gutenberg writes it as two JSON escapes.
        $escapedHyphen = chr(92) . 'u002d';

        self::assertStringStartsWith(
            '<!-- wp:code {"className":"language-a' . $escapedHyphen . $escapedHyphen . 'b"} -->',
            $this->transformer->transform("<pre><code class=\"language-a--b\">x</code></pre>\n"),
        );
    }

    #[Test]
    public function maps_a_quote_with_inner_blocks(): void
    {
        self::assertSame(
            "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>Q</p>\n<!-- /wp:paragraph -->"
            . "\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>a</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list --></blockquote>\n<!-- /wp:quote -->",
            $this->transformer->transform("<blockquote>\n<p>Q</p>\n<ul>\n<li>a</li>\n</ul>\n</blockquote>\n"),
        );
    }

    #[Test]
    public function maps_a_lone_image_to_an_image_block(): void
    {
        self::assertSame(
            "<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"a.png\" alt=\"Alt &amp; co\" title=\"T\"/></figure>\n<!-- /wp:image -->",
            $this->transformer->transform("<p><img src=\"a.png\" alt=\"Alt &amp; co\" title=\"T\" /></p>\n"),
        );
    }

    #[Test]
    public function maps_a_lone_linked_image_to_an_image_block_with_a_link(): void
    {
        self::assertSame(
            "<!-- wp:image {\"linkDestination\":\"custom\"} -->\n<figure class=\"wp-block-image\"><a href=\"https://x.test/?a=1&amp;b=2\"><img src=\"a.png\" alt=\"\"/></a></figure>\n<!-- /wp:image -->",
            $this->transformer->transform("<p><a href=\"https://x.test/?a=1&amp;b=2\"><img src=\"a.png\" alt=\"\" /></a></p>\n"),
        );
    }

    #[Test]
    public function keeps_an_inline_image_in_its_paragraph(): void
    {
        self::assertSame(
            "<!-- wp:paragraph -->\n<p>Voir <img src=\"a.png\" alt=\"\"> ici</p>\n<!-- /wp:paragraph -->",
            $this->transformer->transform("<p>Voir <img src=\"a.png\" alt=\"\" /> ici</p>\n"),
        );
    }

    #[Test]
    public function maps_a_table_and_its_alignment(): void
    {
        $html = "<table>\n<thead>\n<tr>\n<th align=\"center\">H</th>\n<th>I</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td align=\"center\">1</td>\n<td>2</td>\n</tr>\n</tbody>\n</table>\n";

        self::assertSame(
            "<!-- wp:table {\"hasFixedLayout\":false} -->\n<figure class=\"wp-block-table\"><table><thead><tr><th class=\"has-text-align-center\" data-align=\"center\">H</th><th>I</th></tr></thead><tbody><tr><td class=\"has-text-align-center\" data-align=\"center\">1</td><td>2</td></tr></tbody></table></figure>\n<!-- /wp:table -->",
            $this->transformer->transform($html),
        );
    }

    #[Test]
    public function maps_a_rule_to_a_separator(): void
    {
        self::assertSame(
            "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->",
            $this->transformer->transform("<hr />\n"),
        );
    }

    #[Test]
    public function keeps_an_unknown_element_as_raw_html(): void
    {
        self::assertSame(
            "<!-- wp:html -->\n<div class=\"x\"><p>a</p></div>\n<!-- /wp:html -->",
            $this->transformer->transform("<div class=\"x\"><p>a</p></div>\n"),
        );
    }

    #[Test]
    public function preserves_unicode_and_entities(): void
    {
        self::assertSame(
            "<!-- wp:paragraph -->\n<p>Déjà &amp; «là» → fin &lt;3</p>\n<!-- /wp:paragraph -->",
            $this->transformer->transform("<p>Déjà &amp; «là» → fin &lt;3</p>\n"),
        );
    }

    #[Test]
    public function wraps_bare_text_in_a_paragraph(): void
    {
        self::assertSame(
            "<!-- wp:paragraph -->\n<p>hello</p>\n<!-- /wp:paragraph -->",
            $this->transformer->transform("hello\n"),
        );
    }

    #[Test]
    public function renders_nothing_for_an_empty_document(): void
    {
        self::assertSame('', $this->transformer->transform(''));
        self::assertSame('', $this->transformer->transform("  \n\n"));
    }
}
