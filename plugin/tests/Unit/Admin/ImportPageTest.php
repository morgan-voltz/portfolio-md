<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Voltz\PortfolioMd\Admin\ImportPage;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\StoredContent;
use Voltz\PortfolioMd\Tests\Support\FixedClock;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentRepository;
use Voltz\PortfolioMd\Tests\Support\InMemorySkillTermGateway;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

/**
 * The paste page with the real import service, an in-memory store and
 * stubbed WordPress functions: the form, the submission, the feedback.
 */
#[CoversClass(ImportPage::class)]
final class ImportPageTest extends TestCase
{
    private const DOCUMENT = "---\ntitle: \"Article\"\nslug: \"article\"\ntype: \"article\"\n---\n\n# Article\n\nCorps.\n";

    private InMemoryContentRepository $repository;

    private ImportPage $page;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        WordPressSpy::$userCanResult = true;
        WordPressSpy::$nonceValid = true;
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->repository = new InMemoryContentRepository();
        $this->page = new ImportPage(new ContentImportService(
            new FrontmatterParser(),
            new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
            $this->repository,
            new InMemorySkillTermGateway(),
            new ConflictResolver(),
            FixedClock::at('2026-10-26T18:30:00'),
        ));
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    #[Test]
    public function hooks_the_menu(): void
    {
        $this->page->hook();

        self::assertCount(1, WordPressSpy::hooksOn('action', 'admin_menu'));
    }

    #[Test]
    public function adds_its_page_to_the_plugin_menu_and_listens_for_submissions(): void
    {
        $this->page->addMenu();

        self::assertCount(1, WordPressSpy::$menuPages);
        self::assertSame('portfolio-md-import', WordPressSpy::$menuPages[0]['slug']);
        self::assertSame('edit_others_posts', WordPressSpy::$menuPages[0]['capability']);
        self::assertSame('dashicons-media-text', WordPressSpy::$menuPages[0]['icon']);
        self::assertSame('portfolio-md-import', WordPressSpy::$submenuPages[0]['parent']);
        self::assertSame('portfolio-md-import', WordPressSpy::$submenuPages[0]['slug']);
        self::assertSame('edit_others_posts', WordPressSpy::$submenuPages[0]['capability']);
        self::assertCount(1, WordPressSpy::hooksOn('action', 'load-toplevel_page_portfolio-md-import'));
    }

    #[Test]
    public function shows_an_empty_form_on_a_plain_visit(): void
    {
        $this->page->handleSubmission();
        $html = $this->render();

        self::assertSame([], WordPressSpy::$verifiedNonces);
        self::assertStringContainsString('name="portfolio_md_nonce" value="nonce-for-portfolio_md_import"', $html);
        self::assertStringContainsString("<textarea name=\"markdown\"", $html);
        self::assertStringContainsString(">\n</textarea>", $html);
        self::assertStringContainsString('name="mode" value="import"', $html);
        self::assertStringContainsString('name="mode" value="check"', $html);
        self::assertStringNotContainsString('class="notice', $html);
    }

    #[Test]
    public function checks_a_document_without_writing_anything(): void
    {
        $this->post(['markdown' => wp_slash(self::DOCUMENT), 'mode' => 'check']);

        $this->page->handleSubmission();
        $html = $this->render();

        self::assertSame([], $this->repository->saved);
        self::assertStringContainsString('notice-success', $html);
        self::assertStringContainsString('Vérification : article/article serait créé.', $html);
        self::assertStringNotContainsString('Modifier', $html);
    }

    #[Test]
    public function imports_a_pasted_document_with_paste_as_its_source(): void
    {
        $this->post(['markdown' => wp_slash(self::DOCUMENT), 'mode' => 'import']);

        $this->page->handleSubmission();
        $html = $this->render();

        self::assertCount(1, $this->repository->saved);
        self::assertSame(ContentSource::Paste, $this->repository->lastSaved()->source);
        self::assertSame('publish', $this->repository->lastSaved()->postStatus);
        self::assertStringContainsString('notice-success', $html);
        self::assertStringContainsString('Créé : article/article (post 100).', $html);
        self::assertStringContainsString('href="http://example.test/wp-admin/post.php?post=100&#038;action=edit">Modifier</a>', $html);
        self::assertStringContainsString('href="http://example.test/?p=100">Voir</a>', $html);
        self::assertStringContainsString(">\n" . htmlspecialchars(self::DOCUMENT, ENT_QUOTES, 'UTF-8') . '</textarea>', $html);
    }

    #[Test]
    public function creates_a_draft_when_asked_and_remembers_the_box_ticked(): void
    {
        $this->post(['markdown' => wp_slash(self::DOCUMENT), 'mode' => 'import', 'draft' => '1']);

        $this->page->handleSubmission();
        $html = $this->render();

        self::assertSame('draft', $this->repository->lastSaved()->postStatus);
        self::assertStringContainsString("name=\"draft\" value=\"1\" checked='checked'", $html);
        self::assertStringNotContainsString('name="force"', $html);
    }

    #[Test]
    public function normalizes_the_line_endings_of_the_form_before_importing(): void
    {
        $this->post(['markdown' => wp_slash(str_replace("\n", "\r\n", self::DOCUMENT)), 'mode' => 'import']);

        $this->page->handleSubmission();

        self::assertSame(self::DOCUMENT, $this->repository->lastSaved()->markdown);
    }

    #[Test]
    public function reports_an_unchanged_document_as_information(): void
    {
        $this->repository->seed(ContentType::Article, 'article', new StoredContent(42, hash('sha256', self::DOCUMENT), ContentSource::Git, ContentRenderer::VERSION));
        $this->post(['markdown' => wp_slash(self::DOCUMENT), 'mode' => 'import']);

        $this->page->handleSubmission();
        $html = $this->render();

        self::assertSame([], $this->repository->saved);
        self::assertStringContainsString('notice-info', $html);
        self::assertStringContainsString('Inchangé : article/article (post 42)', $html);
    }

    #[Test]
    public function shows_a_refused_document_in_place_so_that_it_can_be_fixed(): void
    {
        $this->post(['markdown' => 'pas de frontmatter', 'mode' => 'import']);

        $this->page->handleSubmission();
        $html = $this->render();

        self::assertSame([], $this->repository->saved);
        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('Échec : ', $html);
        self::assertStringContainsString(">\npas de frontmatter\n</textarea>", $html);
    }

    #[Test]
    public function refuses_a_submission_whose_nonce_is_bad(): void
    {
        WordPressSpy::$nonceValid = false;
        $this->post(['markdown' => wp_slash(self::DOCUMENT), 'mode' => 'import']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wp_die');

        $this->page->handleSubmission();
    }

    #[Test]
    public function refuses_a_user_who_cannot_edit_the_posts_of_others(): void
    {
        WordPressSpy::$userCanResult = false;
        $this->post(['markdown' => wp_slash(self::DOCUMENT), 'mode' => 'import']);

        try {
            $this->page->handleSubmission();
            self::fail('The submission should have died.');
        } catch (RuntimeException $died) {
            self::assertStringContainsString('wp_die: Vous n’avez pas le droit', $died->getMessage());
        }

        self::assertSame([['edit_others_posts']], WordPressSpy::$currentUserCanCalls);
        self::assertSame([], $this->repository->saved);
    }

    /**
     * What WordPress leaves behind after the form was sent: the fields, slashed, plus the nonce.
     *
     * @param array<string, string> $fields
     */
    private function post(array $fields): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['portfolio_md_nonce' => 'nonce'] + $fields;
        $_REQUEST = $_POST;
    }

    private function render(): string
    {
        ob_start();
        $this->page->render();

        return (string) ob_get_clean();
    }
}
