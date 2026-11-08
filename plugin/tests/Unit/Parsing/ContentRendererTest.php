<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
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
        $document = $this->frontmatter->parse((string) file_get_contents(self::CONTENT_DIR . '/fr/projects/momocad.md'));

        $html = $this->renderer->render($document->body, Language::Fr);

        self::assertStringStartsWith("<!-- wp:paragraph -->\n<p>MomoCad est mon projet fil rouge", $html);
        self::assertStringNotContainsString('<h1 ', $html, 'the title comes from the frontmatter, the body must not repeat it');
        self::assertStringContainsString("<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Le problème</h2>", $html);
        self::assertStringContainsString('<!-- wp:table {"hasFixedLayout":false} -->', $html);
        self::assertStringContainsString('<!-- wp:list -->', $html);
        self::assertStringContainsString('<p>MomoCad est mon projet fil rouge', $html);
        self::assertStringNotContainsString('<!-- wp:html -->', $html);
    }

    #[Test]
    public function the_home_and_contact_pages_keep_their_mail_and_site_links_and_resolve_the_page_links(): void
    {
        $contact = $this->renderer->render($this->frontmatter->parse((string) file_get_contents(self::CONTENT_DIR . '/fr/pages/contact.md'))->body, Language::Fr);
        $home = $this->renderer->render($this->frontmatter->parse((string) file_get_contents(self::CONTENT_DIR . '/fr/pages/accueil.md'))->body, Language::Fr);

        self::assertStringContainsString('href="mailto:bonjour@morganvoltz.dev"', $contact, 'the mailto link of the Contact page survives the renderer');
        self::assertStringContainsString('href="https://github.com/morgan-voltz"', $contact);
        self::assertStringContainsString('href="/fr/cv/"', $contact, '../pages/cv.md resolves to the French page');
        self::assertSame(1, substr_count($home, 'href="/fr/projets/"'), 'the absolute link to the archive is kept as is, not prefixed again');
        self::assertStringNotContainsString('/fr/fr/', $home . $contact);
        foreach (['/fr/a-propos/', '/fr/cv/', '/fr/contact/'] as $page) {
            self::assertStringContainsString('href="' . $page . '"', $home, "the home page links {$page}");
        }
    }

    #[Test]
    public function every_content_sheet_maps_to_known_blocks(): void
    {
        $files = glob(self::CONTENT_DIR . '/fr/{projects,pages}/*.md', GLOB_BRACE);
        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $html = $this->renderer->render($this->frontmatter->parse((string) file_get_contents($file))->body, Language::Fr);

            self::assertStringNotContainsString('<!-- wp:html -->', $html, basename($file) . ' needs the raw HTML fallback');
            self::assertGreaterThan(5, substr_count($html, '<!-- /wp:'), basename($file) . ' renders too few blocks');
            self::assertStringNotContainsString('href="../', $html, basename($file) . ' keeps a link to a file of the repository');
            self::assertStringNotContainsString('<h1 ', $html, basename($file) . ' repeats its title as a heading');
        }
    }

    #[Test]
    public function the_cv_page_links_to_the_project_sheets_of_the_site(): void
    {
        $html = $this->renderer->render($this->frontmatter->parse((string) file_get_contents(self::CONTENT_DIR . '/fr/pages/cv.md'))->body, Language::Fr);

        self::assertStringContainsString('href="/fr/projets/vefta/"', $html);
        self::assertStringContainsString('href="/fr/projets/momocad/"', $html);
        self::assertStringNotContainsString('.md"', $html);
    }

    #[Test]
    public function the_render_version_is_semver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', ContentRenderer::VERSION);
    }

    #[Test]
    public function render_uses_the_language_of_the_document_for_its_links(): void
    {
        $body = "Voir [Vefta](../projects/vefta.md) et [le CV anglais](../../en/pages/resume.md).\n";

        self::assertStringContainsString('href="/fr/projets/vefta/"', $this->renderer->render($body, Language::Fr));
        self::assertStringContainsString('href="/de/projets/vefta/"', $this->renderer->render($body, Language::De));
        self::assertStringContainsString('href="/en/resume/"', $this->renderer->render($body, Language::De));
    }

    #[Test]
    public function the_render_version_is_pinned_to_what_the_pipeline_stores(): void
    {
        // 1.2.0 since the links carry the language of the document (ADR-020, lot 1b), 1.3.0 since the SEO
        // description is also written to Yoast (ADR-022, point 13): a post written by an older version is
        // written again at its next import. Move this pin on purpose, with the version.
        self::assertSame('1.3.0', ContentRenderer::VERSION);
    }
}
