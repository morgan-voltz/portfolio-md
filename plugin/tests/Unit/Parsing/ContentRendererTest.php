<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;

#[CoversClass(ContentRenderer::class)]
final class ContentRendererTest extends TestCase
{
    private const CONTENT_DIR = __DIR__ . '/../../../../content';

    private ContentRenderer $renderer;

    private FrontmatterParser $frontmatter;

    protected function setUp(): void
    {
        $this->renderer = new ContentRenderer(new MarkdownParser(), new GutenbergTransformer());
        $this->frontmatter = new FrontmatterParser();
    }

    #[Test]
    public function renders_the_momocad_sheet_as_semantic_blocks(): void
    {
        $document = $this->frontmatter->parse((string) file_get_contents(self::CONTENT_DIR . '/projects/momocad.md'));

        $html = $this->renderer->render($document->body);

        self::assertStringStartsWith("<!-- wp:paragraph -->\n<p>MomoCad est mon projet fil rouge", $html);
        self::assertStringNotContainsString('<h1 ', $html, 'the title comes from the frontmatter, the body must not repeat it');
        self::assertStringContainsString("<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Le problème</h2>", $html);
        self::assertStringContainsString('<!-- wp:table {"hasFixedLayout":false} -->', $html);
        self::assertStringContainsString('<!-- wp:list -->', $html);
        self::assertStringContainsString('<p>MomoCad est mon projet fil rouge', $html);
        self::assertStringNotContainsString('<!-- wp:html -->', $html);
    }

    #[Test]
    public function every_content_sheet_maps_to_known_blocks(): void
    {
        $files = glob(self::CONTENT_DIR . '/{projects,pages}/*.md', GLOB_BRACE);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $html = $this->renderer->render($this->frontmatter->parse((string) file_get_contents($file))->body);

            self::assertStringNotContainsString('<!-- wp:html -->', $html, basename($file) . ' needs the raw HTML fallback');
            self::assertGreaterThan(5, substr_count($html, '<!-- /wp:'), basename($file) . ' renders too few blocks');
            self::assertStringNotContainsString('href="../', $html, basename($file) . ' keeps a link to a file of the repository');
            self::assertStringNotContainsString('<h1 ', $html, basename($file) . ' repeats its title as a heading');
        }
    }

    #[Test]
    public function the_cv_page_links_to_the_project_sheets_of_the_site(): void
    {
        $html = $this->renderer->render($this->frontmatter->parse((string) file_get_contents(self::CONTENT_DIR . '/pages/cv.md'))->body);

        self::assertStringContainsString('href="/projets/vefta/"', $html);
        self::assertStringContainsString('href="/projets/momocad/"', $html);
        self::assertStringNotContainsString('.md"', $html);
    }

    #[Test]
    public function the_render_version_is_semver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', ContentRenderer::VERSION);
    }
}
