<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Admin\EditorMetaBox;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ContentRecord;
use Voltz\PortfolioMd\Service\ContentRepository;
use Voltz\PortfolioMd\Service\StoredContent;
use Voltz\PortfolioMd\Tests\Support\FixedClock;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentRepository;
use Voltz\PortfolioMd\Tests\Support\InMemorySkillTermGateway;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;
use WP_Post;

/**
 * The Markdown box with the real import service, an in-memory store and
 * stubbed WordPress functions: what it shows, what it saves, what it
 * refuses to touch.
 */
#[CoversClass(EditorMetaBox::class)]
final class EditorMetaBoxTest extends TestCase
{
    private const POST_ID = 5;

    private const DOCUMENT = "---\ntitle: \"Article\"\nslug: \"article\"\ntype: \"article\"\ntags: [php]\n---\n\n# Article\n\nUn `C:\\chemin`.\n";

    private InMemoryContentRepository $repository;

    private EditorMetaBox $box;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        WordPressSpy::$userCanResult = true;
        WordPressSpy::$nonceValid = true;
        $_POST = [];

        $this->repository = new InMemoryContentRepository();
        $this->box = new EditorMetaBox(self::service($this->repository));
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    #[Test]
    public function hooks_the_box_and_the_save_on_both_post_types(): void
    {
        $this->box->hook();

        $names = array_map(static fn (array $hook): string => $hook['type'] . ':' . $hook['hook'], WordPressSpy::$hooks);
        self::assertSame([
            'action:add_meta_boxes_article',
            'action:save_post_article',
            'action:add_meta_boxes_project',
            'action:save_post_project',
        ], $names);
        self::assertSame(2, WordPressSpy::hooksOn('action', 'save_post_project')[0]['args']);
    }

    #[Test]
    public function registers_the_box_high_in_the_main_column(): void
    {
        $this->box->addMetaBox(self::post(type: 'project'));

        self::assertCount(1, WordPressSpy::$metaBoxes);
        self::assertSame('portfolio-md-source', WordPressSpy::$metaBoxes[0]['id']);
        self::assertSame('project', WordPressSpy::$metaBoxes[0]['screen']);
        self::assertSame('normal', WordPressSpy::$metaBoxes[0]['context']);
        self::assertSame('high', WordPressSpy::$metaBoxes[0]['priority']);
    }

    #[Test]
    public function shows_the_stored_document_escaped_with_a_nonce_for_this_post(): void
    {
        WordPressSpy::$postMetaValues[self::POST_ID][InternalMetaRegistrar::MD_SOURCE] = self::DOCUMENT;

        $html = $this->render(self::post());

        self::assertStringContainsString('name="portfolio_md_nonce" value="nonce-for-portfolio_md_save_5"', $html);
        self::assertStringContainsString('<textarea name="portfolio_md_source" id="portfolio_md_source"', $html);
        self::assertStringContainsString(' data-portfolio-md-editor="1">', $html);
        self::assertStringContainsString(">\n" . htmlspecialchars(self::DOCUMENT, ENT_QUOTES, 'UTF-8') . '</textarea>', $html);
        self::assertStringContainsString('C:\\chemin', $html);
        self::assertStringNotContainsString('notice-error', $html);
    }

    #[Test]
    public function offers_a_template_of_the_right_type_to_a_post_without_document(): void
    {
        $project = $this->render(self::post(type: 'project'));
        $article = $this->render(self::post());

        self::assertStringContainsString('type: &quot;project&quot;', $project);
        self::assertStringContainsString('status: &quot;en-cours&quot;', $project);
        self::assertStringContainsString('type: &quot;article&quot;', $article);
        self::assertStringNotContainsString('status:', $article);
    }

    #[Test]
    public function dates_the_template_in_the_timezone_of_the_site(): void
    {
        WordPressSpy::$now = (new \DateTimeImmutable('2026-10-26T23:30:00Z'))->getTimestamp();

        WordPressSpy::$timezone = new \DateTimeZone('Pacific/Kiritimati');
        self::assertStringContainsString('date: 2026-10-27', $this->render(self::post(type: 'project')));

        WordPressSpy::$timezone = new \DateTimeZone('Pacific/Niue');
        self::assertStringContainsString('date: 2026-10-26', $this->render(self::post(type: 'project')));
    }

    #[Test]
    public function the_template_parses_once_its_title_and_slug_are_filled(): void
    {
        $filled = str_replace(['title: ""', 'slug: ""'], ['title: "Projet"', 'slug: "projet"'], EditorMetaBox::templateFor(ContentType::Project, '2026-10-26'));

        $document = (new FrontmatterParser())->parse($filled);

        self::assertSame(ContentType::Project, $document->frontmatter->type);
        self::assertSame('projet', $document->frontmatter->slug);
        self::assertSame('2026-10-26', $document->frontmatter->date?->format('Y-m-d'));
        self::assertSame([], $document->frontmatter->skills);
    }

    #[Test]
    public function shows_a_refused_text_with_its_reason_once_then_the_stored_document_again(): void
    {
        WordPressSpy::$postMetaValues[self::POST_ID][InternalMetaRegistrar::MD_SOURCE] = self::DOCUMENT;
        WordPressSpy::$transients[self::feedbackKey()] = ['message' => 'Document: no frontmatter.', 'markdown' => "pas de frontmatter\n"];

        $first = $this->render(self::post());

        self::assertStringContainsString('notice-error', $first);
        self::assertStringContainsString('Document: no frontmatter.', $first);
        self::assertStringContainsString(">\npas de frontmatter\n</textarea>", $first);
        self::assertStringNotContainsString('title: &quot;Article&quot;', $first);
        self::assertArrayNotHasKey(self::feedbackKey(), WordPressSpy::$transients);

        $second = $this->render(self::post());

        self::assertStringNotContainsString('notice-error', $second);
        self::assertStringContainsString('title: &quot;Article&quot;', $second);
    }

    #[Test]
    public function ignores_a_save_that_does_not_come_from_the_box(): void
    {
        $this->submit(self::DOCUMENT, nonce: null);

        $this->box->save(self::POST_ID, self::post());

        self::assertSame([], $this->repository->saved);
        self::assertSame([], WordPressSpy::$verifiedNonces);
    }

    #[Test]
    public function ignores_a_save_whose_nonce_is_refused(): void
    {
        WordPressSpy::$nonceValid = false;
        $this->submit(self::DOCUMENT);

        $this->box->save(self::POST_ID, self::post());

        self::assertSame([], $this->repository->saved);
        self::assertSame('portfolio_md_save_5', WordPressSpy::$verifiedNonces[0]['action']);
    }

    #[Test]
    public function ignores_auto_drafts_and_trashed_posts(): void
    {
        $this->submit(self::DOCUMENT);

        $this->box->save(self::POST_ID, self::post(status: 'auto-draft'));
        $this->box->save(self::POST_ID, self::post(status: 'trash'));

        self::assertSame([], $this->repository->saved);
        self::assertSame([], WordPressSpy::$verifiedNonces);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function ignores_an_autosave(): void
    {
        define('DOING_AUTOSAVE', true);
        $this->submit(self::DOCUMENT);

        $this->box->save(self::POST_ID, self::post());

        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function refuses_a_user_who_cannot_edit_the_post(): void
    {
        WordPressSpy::$userCanResult = false;
        $this->submit(self::DOCUMENT);

        $this->box->save(self::POST_ID, self::post());

        self::assertSame([], $this->repository->saved);
        self::assertSame([['edit_post', self::POST_ID]], WordPressSpy::$currentUserCanCalls);
    }

    #[Test]
    public function writes_the_submitted_document_into_the_post_as_an_admin_write(): void
    {
        $this->repository->seed(ContentType::Article, 'coquille', new StoredContent(self::POST_ID, '', null, ''));
        $this->submit(str_replace("\n", "\r\n", self::DOCUMENT));

        $this->box->save(self::POST_ID, self::post());

        self::assertCount(1, $this->repository->saved);
        $record = $this->repository->lastSaved();
        self::assertSame(self::POST_ID, $record->id);
        self::assertSame(ContentSource::Admin, $record->source);
        self::assertSame(self::DOCUMENT, $record->markdown);
        self::assertSame(hash('sha256', self::DOCUMENT), $record->hash);
        self::assertNotNull($this->repository->findBySlug(ContentType::Article, 'article'));
        self::assertSame([], WordPressSpy::$transients);
        self::assertSame([], WordPressSpy::$updatedPosts);
    }

    #[Test]
    public function an_unchanged_document_writes_nothing(): void
    {
        $this->repository->seed(ContentType::Article, 'article', new StoredContent(self::POST_ID, hash('sha256', self::DOCUMENT), ContentSource::Admin, ContentRenderer::VERSION, documentSlug: 'article'));
        $this->submit(self::DOCUMENT);

        $this->box->save(self::POST_ID, self::post());

        self::assertSame([], $this->repository->saved);
        self::assertSame([], WordPressSpy::$transients);
    }

    #[Test]
    public function keeps_a_refused_text_and_drafts_a_post_that_holds_no_document(): void
    {
        $this->repository->seed(ContentType::Article, 'coquille', new StoredContent(self::POST_ID, '', null, ''));
        $this->submit('pas de frontmatter');

        $this->box->save(self::POST_ID, self::post(status: 'publish'));

        self::assertSame([], $this->repository->saved);
        self::assertArrayHasKey(self::feedbackKey(), WordPressSpy::$transients);
        self::assertNotSame('', WordPressSpy::$transients[self::feedbackKey()]['message']);
        self::assertSame("pas de frontmatter\n", WordPressSpy::$transients[self::feedbackKey()]['markdown']);
        self::assertSame([['ID' => self::POST_ID, 'post_status' => 'draft']], WordPressSpy::$updatedPosts);
    }

    #[Test]
    public function leaves_the_status_of_a_post_that_holds_a_document_alone_when_refusing(): void
    {
        WordPressSpy::$postMetaValues[self::POST_ID][InternalMetaRegistrar::MD_HASH] = 'abc';
        $this->repository->seed(ContentType::Article, 'ancien', new StoredContent(self::POST_ID, 'abc', ContentSource::Git, ContentRenderer::VERSION, documentSlug: 'ancien'));
        $this->submit(self::DOCUMENT);

        $this->box->save(self::POST_ID, self::post());

        self::assertSame([], $this->repository->saved);
        self::assertStringContainsString('holds a document whose slug is "ancien" and keeps it', WordPressSpy::$transients[self::feedbackKey()]['message']);
        self::assertSame([], WordPressSpy::$updatedPosts);
    }

    #[Test]
    public function names_the_post_that_owns_a_slug_only_to_a_user_who_may_edit_it(): void
    {
        $this->repository->seed(ContentType::Article, 'article', new StoredContent(42, str_repeat('0', 64), ContentSource::Git, ContentRenderer::VERSION, documentSlug: 'article'));
        $this->repository->seed(ContentType::Article, 'coquille', new StoredContent(self::POST_ID, '', null, ''));
        $this->submit(self::DOCUMENT);

        WordPressSpy::$capabilityResults['edit_post:42'] = false;
        $this->box->save(self::POST_ID, self::post());
        self::assertStringNotContainsString('42', WordPressSpy::$transients[self::feedbackKey()]['message']);
        self::assertStringContainsString('déjà pris par un autre contenu', WordPressSpy::$transients[self::feedbackKey()]['message']);

        WordPressSpy::$capabilityResults['edit_post:42'] = true;
        $this->box->save(self::POST_ID, self::post());
        self::assertStringContainsString('already belongs to post 42', WordPressSpy::$transients[self::feedbackKey()]['message']);
        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function is_not_re_entered_by_the_save_it_triggers(): void
    {
        $repository = new class() implements ContentRepository {
            public ?EditorMetaBox $box = null;

            public int $saves = 0;

            public function findBySlug(ContentType $type, string $slug): ?StoredContent
            {
                return null;
            }

            public function findById(ContentType $type, int $postId): ?StoredContent
            {
                return new StoredContent($postId, '', null, '');
            }

            public function save(ContentRecord $record): int
            {
                $this->saves++;
                // WordPress fires save_post again from inside wp_update_post().
                $this->box?->save((int) $record->id, new WP_Post(['ID' => (int) $record->id, 'post_type' => 'article']));

                return (int) $record->id;
            }
        };
        $box = new EditorMetaBox(self::service($repository));
        $repository->box = $box;
        $this->submit(self::DOCUMENT);

        $box->save(self::POST_ID, self::post());

        self::assertSame(1, $repository->saves);
    }

    private static function service(ContentRepository $repository): ContentImportService
    {
        return new ContentImportService(
            new FrontmatterParser(),
            new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
            $repository,
            new InMemorySkillTermGateway(),
            new ConflictResolver(),
            FixedClock::at('2026-10-26T18:30:00'),
        );
    }

    private static function post(string $status = 'publish', string $type = 'article'): WP_Post
    {
        return new WP_Post(['ID' => self::POST_ID, 'post_type' => $type, 'post_status' => $status]);
    }

    /** The transient of the stubbed user (id 1) for the post under test. */
    private static function feedbackKey(): string
    {
        return EditorMetaBox::feedbackKey(self::POST_ID, WordPressSpy::$currentUserId);
    }

    /**
     * What WordPress leaves in $_POST: the fields of the form, slashed.
     */
    private function submit(string $markdown, ?string $nonce = 'nonce'): void
    {
        $_POST = [EditorMetaBox::FIELD => wp_slash($markdown)];
        if ($nonce !== null) {
            $_POST[EditorMetaBox::NONCE_FIELD] = $nonce;
        }
    }

    private function render(WP_Post $post): string
    {
        ob_start();
        $this->box->render($post);

        return (string) ob_get_clean();
    }
}
