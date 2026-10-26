<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ContentRecord;
use Voltz\PortfolioMd\Service\ImportException;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\ImportResult;
use Voltz\PortfolioMd\Service\StoredContent;
use Voltz\PortfolioMd\Tests\Support\FixedClock;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentRepository;
use Voltz\PortfolioMd\Tests\Support\InMemorySkillTermGateway;

/**
 * The import use case with the real parsers and renderer, an in-memory
 * store and an in-memory skill taxonomy: everything but WordPress.
 */
#[CoversClass(ContentImportService::class)]
#[CoversClass(ContentRecord::class)]
#[CoversClass(ImportOptions::class)]
#[CoversClass(ImportOutcome::class)]
#[CoversClass(ImportResult::class)]
#[CoversClass(ImportException::class)]
final class ContentImportServiceTest extends TestCase
{
    private const NOW = '2026-10-26T18:30:00';

    private InMemoryContentRepository $repository;

    private InMemorySkillTermGateway $skills;

    private ContentImportService $service;

    /** @var array<string, int> code => term id */
    private array $skillIds = [];

    protected function setUp(): void
    {
        $this->repository = new InMemoryContentRepository();
        $this->skills = new InMemorySkillTermGateway();
        $referential = $this->skills->seed('Compétences 4.0.2', '4-0-2', '', 0, '4.0.2');
        foreach (['4.0.2/E00' => 'e00', '4.0.2/E01' => 'e01'] as $code => $slug) {
            $this->skillIds[$code] = $this->skills->seed("Compétence {$slug}", $slug, '', $referential->id, $code)->id;
        }

        $this->service = new ContentImportService(
            new FrontmatterParser(),
            new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
            $this->repository,
            $this->skills,
            new ConflictResolver(),
            FixedClock::at(self::NOW),
        );
    }

    #[Test]
    public function creates_a_project_with_everything_the_store_needs(): void
    {
        $markdown = self::project();

        $result = $this->service->import($markdown, new ImportOptions());

        self::assertSame(ImportOutcome::Created, $result->outcome);
        self::assertSame(ContentType::Project, $result->type);
        self::assertSame('projet', $result->slug);
        self::assertSame('Projet', $result->title);
        self::assertSame(100, $result->postId);

        $record = $this->repository->lastSaved();
        self::assertNull($record->id);
        self::assertSame('projet', $record->frontmatter->slug);
        self::assertStringStartsWith("<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Projet</h1>", $record->html);
        self::assertSame($markdown, $record->markdown);
        self::assertSame(hash('sha256', $markdown), $record->hash);
        self::assertSame(ContentSource::Git, $record->source);
        self::assertSame(self::NOW . '+00:00', $record->syncedAt->format('Y-m-d\TH:i:sP'));
        self::assertSame(ContentRenderer::VERSION, $record->renderVersion);
        self::assertSame([$this->skillIds['4.0.2/E00'], $this->skillIds['4.0.2/E01']], $record->skillTermIds);
        self::assertSame('publish', $record->postStatus);
    }

    #[Test]
    public function a_second_import_of_the_same_file_is_a_no_op(): void
    {
        $markdown = self::project();
        $this->service->import($markdown, new ImportOptions());

        $result = $this->service->import($markdown, new ImportOptions());

        self::assertSame(ImportOutcome::Unchanged, $result->outcome);
        self::assertSame(100, $result->postId);
        self::assertCount(1, $this->repository->saved);
    }

    #[Test]
    public function a_changed_file_updates_the_same_post(): void
    {
        $this->service->import(self::project(), new ImportOptions());

        $result = $this->service->import(self::project(title: 'Projet renommé'), new ImportOptions());

        self::assertSame(ImportOutcome::Updated, $result->outcome);
        self::assertSame(100, $result->postId);
        self::assertCount(2, $this->repository->saved);
        self::assertSame(100, $this->repository->lastSaved()->id);
        self::assertSame('Projet renommé', $this->repository->lastSaved()->frontmatter->title);
    }

    #[Test]
    public function an_older_render_version_triggers_a_new_render_of_unchanged_markdown(): void
    {
        $markdown = self::project();
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, hash('sha256', $markdown), ContentSource::Admin, '0.0.1'));

        $result = $this->service->import($markdown, new ImportOptions());

        self::assertSame(ImportOutcome::Updated, $result->outcome);
        self::assertSame(42, $this->repository->lastSaved()->id);
        self::assertSame(ContentRenderer::VERSION, $this->repository->lastSaved()->renderVersion);
    }

    #[Test]
    public function a_git_import_refuses_to_overwrite_an_admin_edit(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, str_repeat('0', 64), ContentSource::Admin, ContentRenderer::VERSION));

        $result = $this->service->import(self::project(), new ImportOptions());

        self::assertSame(ImportOutcome::Conflict, $result->outcome);
        self::assertSame(42, $result->postId);
        self::assertStringContainsString('last written from admin', $result->detail);
        self::assertStringContainsString('--force', $result->detail);
        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function force_overrides_the_conflict(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, str_repeat('0', 64), ContentSource::Admin, ContentRenderer::VERSION));

        $result = $this->service->import(self::project(), new ImportOptions(force: true));

        self::assertSame(ImportOutcome::Updated, $result->outcome);
        self::assertSame(42, $this->repository->lastSaved()->id);
        self::assertSame(ContentSource::Git, $this->repository->lastSaved()->source);
    }

    #[Test]
    public function a_paste_overwrites_an_admin_edit(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, str_repeat('0', 64), ContentSource::Admin, ContentRenderer::VERSION));

        $result = $this->service->import(self::project(), new ImportOptions(source: ContentSource::Paste));

        self::assertSame(ImportOutcome::Updated, $result->outcome);
        self::assertSame(ContentSource::Paste, $this->repository->lastSaved()->source);
    }

    #[Test]
    public function a_post_made_by_hand_is_protected_from_git(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, '', null, ''));

        $result = $this->service->import(self::project(), new ImportOptions());

        self::assertSame(ImportOutcome::Conflict, $result->outcome);
        self::assertStringContainsString('outside the pipeline', $result->detail);
    }

    #[Test]
    public function unknown_skill_codes_are_refused_before_anything_is_written(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Unknown competency code(s): 4.0.2/E99, 4.0.1/FR-01.');

        try {
            $this->service->import(self::project(skills: '["4.0.2/E00", "4.0.2/E99", "4.0.1/FR-01"]'), new ImportOptions());
        } finally {
            self::assertSame([], $this->repository->saved);
        }
    }

    #[Test]
    public function a_code_that_left_the_grid_does_not_fail_an_unchanged_document(): void
    {
        $markdown = self::project(skills: '["4.0.2/E99"]');
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, hash('sha256', $markdown), ContentSource::Git, ContentRenderer::VERSION));

        $result = $this->service->import($markdown, new ImportOptions());

        self::assertSame(ImportOutcome::Unchanged, $result->outcome);
        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function a_code_that_left_the_grid_does_not_hide_a_conflict(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, str_repeat('0', 64), ContentSource::Admin, ContentRenderer::VERSION));

        $result = $this->service->import(self::project(skills: '["4.0.2/E99"]'), new ImportOptions());

        self::assertSame(ImportOutcome::Conflict, $result->outcome);
        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function a_slug_that_differs_from_the_file_name_is_refused(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The slug "projet" does not match the file name "geoforge"');

        try {
            $this->service->import(self::project(), new ImportOptions(), 'geoforge');
        } finally {
            self::assertSame([], $this->repository->saved);
        }
    }

    #[Test]
    public function a_file_named_after_its_slug_is_accepted(): void
    {
        $result = $this->service->import(self::project(), new ImportOptions(), 'projet');

        self::assertSame(ImportOutcome::Created, $result->outcome);
    }

    #[Test]
    public function a_slug_whose_post_is_in_the_trash_is_refused_even_when_nothing_changed(): void
    {
        $markdown = self::project();
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, hash('sha256', $markdown), ContentSource::Git, ContentRenderer::VERSION, inTrash: true));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Post 42 (project/projet) is in the trash');

        try {
            $this->service->import($markdown, new ImportOptions());
        } finally {
            self::assertSame([], $this->repository->saved);
        }
    }

    #[Test]
    public function pages_are_refused(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('type "page"');

        $this->service->import("---\ntitle: \"À propos\"\nslug: \"a-propos\"\ntype: \"page\"\n---\n\nCorps.\n", new ImportOptions());
    }

    #[Test]
    public function a_dry_run_reports_the_outcome_and_writes_nothing(): void
    {
        $result = $this->service->import(self::project(), new ImportOptions(dryRun: true));

        self::assertSame(ImportOutcome::Created, $result->outcome);
        self::assertNull($result->postId);
        self::assertSame([], $this->repository->saved);
        self::assertNull($this->repository->findBySlug(ContentType::Project, 'projet'));
    }

    #[Test]
    public function new_posts_can_be_created_as_drafts(): void
    {
        $this->service->import(self::project(), new ImportOptions(postStatus: 'draft'));

        self::assertSame('draft', $this->repository->lastSaved()->postStatus);
    }

    #[Test]
    public function an_unknown_post_status_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ImportOptions(postStatus: 'pending');
    }

    #[Test]
    public function an_article_carries_no_skills(): void
    {
        $markdown = "---\ntitle: \"Billet\"\nslug: \"billet\"\ntype: \"article\"\nreading_time: 4\ntags: [php]\n---\n\nCorps.\n";

        $result = $this->service->import($markdown, new ImportOptions());

        self::assertSame(ImportOutcome::Created, $result->outcome);
        self::assertSame(ContentType::Article, $result->type);
        self::assertSame([], $this->repository->lastSaved()->skillTermIds);
        self::assertSame(4, $this->repository->lastSaved()->frontmatter->readingTime);
    }

    #[Test]
    public function a_malformed_document_is_reported_as_such(): void
    {
        $this->expectException(MalformedDocumentException::class);

        $this->service->import("# Pas de frontmatter\n", new ImportOptions());
    }

    #[Test]
    public function the_hash_covers_the_document_verbatim(): void
    {
        $crlf = str_replace("\n", "\r\n", self::project());

        $this->service->import($crlf, new ImportOptions());

        self::assertSame($crlf, $this->repository->lastSaved()->markdown);
        self::assertSame(hash('sha256', $crlf), $this->repository->lastSaved()->hash);
        self::assertNotSame(hash('sha256', self::project()), $this->repository->lastSaved()->hash);
    }

    // --- A target post: the editor of a post knows which one it edits ----------------------------

    #[Test]
    public function a_document_is_created_into_a_target_that_holds_none_yet(): void
    {
        $this->repository->seed(ContentType::Project, 'coquille', new StoredContent(7, '', null, ''));

        $result = $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 7);

        self::assertSame(ImportOutcome::Created, $result->outcome);
        self::assertSame(7, $result->postId);
        self::assertSame(7, $this->repository->lastSaved()->id);
        self::assertSame(ContentSource::Admin, $this->repository->lastSaved()->source);
        self::assertNotNull($this->repository->findBySlug(ContentType::Project, 'projet'));
        self::assertNull($this->repository->findBySlug(ContentType::Project, 'coquille'));
    }

    #[Test]
    public function a_target_whose_slug_already_matches_the_document_receives_it(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(7, '', null, ''));

        $result = $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 7);

        self::assertSame(ImportOutcome::Created, $result->outcome);
        self::assertSame(7, $this->repository->lastSaved()->id);
    }

    #[Test]
    public function a_target_that_holds_a_document_is_updated_in_place(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, str_repeat('0', 64), ContentSource::Git, ContentRenderer::VERSION, documentSlug: 'projet'));

        $result = $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 42);

        self::assertSame(ImportOutcome::Updated, $result->outcome);
        self::assertSame(42, $result->postId);
        self::assertSame(42, $this->repository->lastSaved()->id);
    }

    #[Test]
    public function an_unchanged_document_saved_into_its_own_target_is_a_no_op(): void
    {
        $markdown = self::project();
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, hash('sha256', $markdown), ContentSource::Admin, ContentRenderer::VERSION, documentSlug: 'projet'));

        $result = $this->service->import($markdown, new ImportOptions(source: ContentSource::Admin), targetPostId: 42);

        self::assertSame(ImportOutcome::Unchanged, $result->outcome);
        self::assertSame(42, $result->postId);
        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function a_dry_run_into_a_target_names_the_post_and_writes_nothing(): void
    {
        $this->repository->seed(ContentType::Project, 'coquille', new StoredContent(7, '', null, ''));

        $result = $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin, dryRun: true), targetPostId: 7);

        self::assertSame(ImportOutcome::Created, $result->outcome);
        self::assertSame(7, $result->postId);
        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function a_target_that_does_not_exist_is_refused(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Post 7 is not an existing project');

        $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 7);
    }

    #[Test]
    public function a_target_of_another_type_is_refused(): void
    {
        $this->repository->seed(ContentType::Article, 'projet', new StoredContent(7, '', null, ''));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Post 7 is not an existing project');

        $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 7);
    }

    #[Test]
    public function a_target_in_the_trash_is_refused(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, str_repeat('0', 64), ContentSource::Git, ContentRenderer::VERSION, inTrash: true));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Post 42 (project/projet) is in the trash');

        $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 42);
    }

    #[Test]
    public function a_slug_owned_by_another_post_cannot_be_taken_by_the_target(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, str_repeat('0', 64), ContentSource::Git, ContentRenderer::VERSION));
        $this->repository->seed(ContentType::Project, 'coquille', new StoredContent(7, '', null, ''));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('The slug "projet" already belongs to post 42 (project/projet): post 7 cannot take it');

        $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 7);
    }

    #[Test]
    public function a_slug_owned_by_a_trashed_post_blocks_the_target_as_well(): void
    {
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, str_repeat('0', 64), ContentSource::Git, ContentRenderer::VERSION, inTrash: true));
        $this->repository->seed(ContentType::Project, 'coquille', new StoredContent(7, '', null, ''));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Post 42 (project/projet) is in the trash');

        $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 7);
    }

    #[Test]
    public function a_target_that_holds_a_document_keeps_the_slug_written_in_it(): void
    {
        $this->repository->seed(ContentType::Project, 'ancien-slug', new StoredContent(42, str_repeat('0', 64), ContentSource::Git, ContentRenderer::VERSION, documentSlug: 'ancien-slug'));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Post 42 (project) holds a document whose slug is "ancien-slug" and keeps it: renaming it to "projet"');

        $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 42);
    }

    #[Test]
    public function a_slug_line_change_is_refused_even_when_the_url_slug_already_matches(): void
    {
        // The post's URL slug was changed behind the document's back (REST); the stored document still says "ancien".
        $this->repository->seed(ContentType::Project, 'projet', new StoredContent(42, str_repeat('0', 64), ContentSource::Git, ContentRenderer::VERSION, documentSlug: 'ancien'));

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('holds a document whose slug is "ancien" and keeps it');

        $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 42);
    }

    #[Test]
    public function a_url_slug_that_drifted_from_the_document_does_not_count_as_a_rename(): void
    {
        // Same document slug as before: the drifted URL slug is the one that is wrong, and the write puts it back.
        $this->repository->seed(ContentType::Project, 'slug-change-par-rest', new StoredContent(42, str_repeat('0', 64), ContentSource::Git, ContentRenderer::VERSION, documentSlug: 'projet'));

        $result = $this->service->import(self::project(), new ImportOptions(source: ContentSource::Admin), targetPostId: 42);

        self::assertSame(ImportOutcome::Updated, $result->outcome);
        self::assertSame(42, $this->repository->lastSaved()->id);
        self::assertSame(42, $this->repository->findBySlug(ContentType::Project, 'projet')?->id);
        self::assertNull($this->repository->findBySlug(ContentType::Project, 'slug-change-par-rest'));
    }

    private static function project(string $title = 'Projet', string $skills = '["4.0.2/E00", "4.0.2/E01"]'): string
    {
        return "---\ntitle: \"{$title}\"\nslug: \"projet\"\ntype: \"project\"\nstatus: \"en-cours\"\nskills: {$skills}\ntags: [php]\n---\n\n# Projet\n\nCorps.\n";
    }
}
