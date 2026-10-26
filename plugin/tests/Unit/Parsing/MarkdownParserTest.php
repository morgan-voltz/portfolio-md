<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Parsing\HighlightedCodeRenderer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;

#[CoversClass(MarkdownParser::class)]
#[CoversClass(HighlightedCodeRenderer::class)]
final class MarkdownParserTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    #[Test]
    public function renders_gfm_tables_with_their_alignment(): void
    {
        $html = $this->parser->render("| Nom | Valeur |\n|---|:-:|\n| a | 1 |\n");

        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('<th>Nom</th>', $html);
        self::assertStringContainsString('<th align="center">Valeur</th>', $html);
        self::assertStringContainsString('<td align="center">1</td>', $html);
    }

    #[Test]
    public function renders_task_lists_strikethrough_and_autolinks(): void
    {
        $html = $this->parser->render("- [x] fait\n- [ ] à faire\n\n~~barré~~ https://example.com\n");

        self::assertStringContainsString('checked="" disabled="" type="checkbox"> fait', $html);
        self::assertStringContainsString('disabled="" type="checkbox"> à faire', $html);
        self::assertStringContainsString('<del>barré</del>', $html);
        self::assertStringContainsString('<a href="https://example.com">https://example.com</a>', $html);
    }

    #[Test]
    public function highlights_fenced_code_in_a_known_language(): void
    {
        $html = $this->parser->render("```php\n<?php\necho \$a;\n```\n");

        self::assertStringStartsWith('<pre><code class="hljs language-php">', $html);
        self::assertStringContainsString('<span class="hljs-', $html);
        self::assertStringContainsString('&lt;?php', $html);
        self::assertStringNotContainsString('<?php', $html);
    }

    #[Test]
    public function lowercases_the_language_and_ignores_the_other_info_words(): void
    {
        $html = $this->parser->render("```PHP title=\"x\"\necho 1;\n```\n");

        self::assertStringStartsWith('<pre><code class="hljs language-php">', $html);
    }

    #[Test]
    public function keeps_the_class_but_adds_no_colour_for_an_unknown_language(): void
    {
        self::assertSame(
            "<pre><code class=\"language-nosuchlang\">x &lt; y\n</code></pre>\n",
            $this->parser->render("```nosuchlang\nx < y\n```\n"),
        );
    }

    #[Test]
    public function leaves_code_without_language_plain(): void
    {
        self::assertSame("<pre><code>a &lt; b\n</code></pre>\n", $this->parser->render("```\na < b\n```\n"));
        self::assertSame("<pre><code>indented\n</code></pre>\n", $this->parser->render("    indented\n"));
    }

    #[Test]
    public function treats_a_hostile_info_string_as_no_language(): void
    {
        self::assertSame("<pre><code>x\n</code></pre>\n", $this->parser->render("```\"><script>\nx\n```\n"));
    }

    #[Test]
    public function escapes_raw_html_instead_of_passing_it_through(): void
    {
        $html = $this->parser->render("<script>alert(1)</script>\n\nun <b>gras</b>\n");

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('un &lt;b&gt;gras&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    #[Test]
    public function neutralises_unsafe_links(): void
    {
        $html = $this->parser->render("[clique](javascript:alert(1)) ![x](data:text/html;base64,AAAA)\n");

        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('data:text/html', $html);
        self::assertStringContainsString('clique', $html);
    }

    #[Test]
    public function preserves_backslashes_and_unicode(): void
    {
        $html = $this->parser->render("Chemin `C:\\dossier` et é — →\n\n```text\nC:\\dossier\\fichier\n```\n");

        self::assertStringContainsString('<code>C:\\dossier</code>', $html);
        self::assertStringContainsString('é — →', $html);
        self::assertStringContainsString("C:\\dossier\\fichier\n", $html);
    }

    #[Test]
    public function keeps_soft_breaks_as_newlines(): void
    {
        self::assertSame("<p>ligne un\nligne deux</p>\n", $this->parser->render("ligne un\nligne deux\n"));
    }
}
