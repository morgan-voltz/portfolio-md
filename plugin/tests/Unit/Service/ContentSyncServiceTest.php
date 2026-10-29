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
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ContentRecord;
use Voltz\PortfolioMd\Service\ContentRepository;
use Voltz\PortfolioMd\Service\ContentSyncLine;
use Voltz\PortfolioMd\Service\ContentSyncReport;
use Voltz\PortfolioMd\Service\ContentSyncService;
use Voltz\PortfolioMd\Service\ContentSyncStatus;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\SkillTermSynchronizer;
use Voltz\PortfolioMd\Service\StoredContent;
use Voltz\PortfolioMd\Service\SyncFile;
use Voltz\PortfolioMd\Tests\Support\FixedClock;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentRepository;
use Voltz\PortfolioMd\Tests\Support\InMemorySkillTermGateway;

/**
 * The Git door with the real import chain behind, an in-memory store and
 * an in-memory skill taxonomy: everything but WordPress.
 */
#[CoversClass(ContentSyncService::class)]
#[CoversClass(ContentSyncLine::class)]
#[CoversClass(ContentSyncReport::class)]
#[CoversClass(ContentSyncStatus::class)]
#[CoversClass(SyncFile::class)]
final class ContentSyncServiceTest extends TestCase
{
    private const GRID = <<<'YAML'
    referentials:
      - code: "4.0.2"
        slug: 4-0-2
        name: "Compétences 4.0.2"
        description: "Deuxième année."
        skills:
          - code: "4.0.2/E00"
            slug: e00
            name: "Compétence e00"
            description: "Décrire."
          - code: "4.0.2/E01"
            slug: e01
            name: "Compétence e01"
            description: "Décrire."
    YAML;

    private InMemoryContentRepository $repository;
    private InMemorySkillTermGateway $skills;
    private ContentImportService $import;
    private ContentSyncService $service;

    protected function setUp(): void
    {
        $this->repository = new InMemoryContentRepository();
        $this->skills = new InMemorySkillTermGateway();
        $referential = $this->skills->seed('Compétences 4.0.2', '4-0-2', 'Deuxième année.', 0, '4.0.2');
        foreach (['4.0.2/E00' => 'e00', '4.0.2/E01' => 'e01'] as $code => $slug) {
            $this->skills->seed("Compétence {$slug}", $slug, 'Décrire.', $referential->id, $code);
        }

        $this->import = new ContentImportService(
            new FrontmatterParser(),
            new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
            $this->repository,
            $this->skills,
            new ConflictResolver(),
            FixedClock::at('2026-10-29T09:00:00'),
        );
        $this->service = new ContentSyncService($this->import, $this->repository, new SkillTermSynchronizer($this->skills), new SkillsGridLoader());
    }

    #[Test]
    public function creates_the_documents_of_a_push_as_git_writes(): void
    {
        $report = $this->service->synchronize([
            new SyncFile('projects/projet.md', self::project()),
            new SyncFile('pages/a-propos.md', self::page()),
        ]);

        self::assertSame(['created', 'created'], self::statuses($report));
        self::assertSame([ContentType::Project, ContentType::Page], array_map(static fn (ContentSyncLine $l) => $l->type, $report->lines));
        self::assertSame(['projet', 'a-propos'], array_map(static fn (ContentSyncLine $l) => $l->slug, $report->lines));
        self::assertNotNull($report->lines[0]->postId);
        self::assertCount(2, $this->repository->saved);
        self::assertSame(ContentSource::Git, $this->repository->saved[0]->source);
        self::assertSame('publish', $this->repository->saved[0]->postStatus);
        self::assertFalse($report->dryRun);
    }

    #[Test]
    public function a_replayed_push_changes_nothing(): void
    {
        $files = [new SyncFile('projects/projet.md', self::project()), new SyncFile('pages/a-propos.md', self::page())];
        $this->service->synchronize($files);

        $report = $this->service->synchronize($files);

        self::assertSame(['unchanged', 'unchanged'], self::statuses($report));
        self::assertCount(2, $this->repository->saved);
    }

    #[Test]
    public function a_changed_document_updates_its_post(): void
    {
        $first = $this->service->synchronize([new SyncFile('projects/projet.md', self::project())]);

        $report = $this->service->synchronize([new SyncFile('projects/projet.md', self::project(body: 'Un autre corps.'))]);

        self::assertSame(['updated'], self::statuses($report));
        self::assertSame($first->lines[0]->postId, $report->lines[0]->postId);
    }

    #[Test]
    public function a_document_edited_from_the_admin_is_a_conflict_left_alone(): void
    {
        $this->import->import(self::project(), new ImportOptions(source: ContentSource::Admin));

        $report = $this->service->synchronize([new SyncFile('projects/projet.md', self::project(body: 'Depuis Git.'))]);

        self::assertSame(['conflict'], self::statuses($report));
        self::assertStringContainsString('last written from admin', $report->lines[0]->message);
        self::assertCount(1, $this->repository->saved);
        self::assertTrue($report->hasConflicts());
        self::assertFalse($report->hasFailures());
    }

    #[Test]
    public function every_file_is_handled_on_its_own_and_in_order(): void
    {
        $report = $this->service->synchronize([
            new SyncFile('projects/casse.md', "No frontmatter.\n"),
            new SyncFile('projects/projet.md', self::project()),
        ]);

        self::assertSame(['failed', 'created'], self::statuses($report));
        self::assertSame(['projects/casse.md', 'projects/projet.md'], array_map(static fn (ContentSyncLine $l) => $l->path, $report->lines));
        self::assertSame(ContentType::Project, $report->lines[0]->type);
        self::assertSame('casse', $report->lines[0]->slug);
        self::assertNotSame('', $report->lines[0]->message);
        self::assertCount(1, $this->repository->saved);
        self::assertTrue($report->hasFailures());
    }

    #[Test]
    public function a_document_in_the_folder_of_another_kind_is_refused(): void
    {
        $report = $this->service->synchronize([new SyncFile('pages/projet.md', self::project())]);

        self::assertSame(['failed'], self::statuses($report));
        self::assertStringContainsString('"pages/" folder', $report->lines[0]->message);
        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function files_that_are_not_documents_are_skipped_with_the_reason(): void
    {
        $report = $this->service->synchronize([
            new SyncFile('README.md', "# Content\n"),
            new SyncFile('projects/projet/capture.png', 'binary'),
            new SyncFile('images/logo.md', '---'),
            new SyncFile('projects/notes.txt', 'notes'),
            new SyncFile('../wp-config.php', 'x'),
            new SyncFile('/etc/passwd', 'x'),
        ]);

        self::assertSame(array_fill(0, 6, 'skipped'), self::statuses($report));
        self::assertStringContainsString('root of content/', $report->lines[0]->message);
        self::assertStringContainsString('nested', $report->lines[1]->message);
        self::assertStringContainsString('not a folder of documents', $report->lines[2]->message);
        self::assertStringContainsString('not a Markdown document', $report->lines[3]->message);
        self::assertStringContainsString('leaves the content repository', $report->lines[4]->message);
        self::assertStringContainsString('leaves the content repository', $report->lines[5]->message);
        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function the_grid_goes_first_so_that_a_document_may_cite_a_code_the_push_introduces(): void
    {
        $grid = str_replace("            description: \"Décrire.\"\n    YAML", '', self::GRID) . "\n      - code: \"4.0.2/E02\"\n        slug: e02\n        name: \"Compétence e02\"\n        description: \"Décrire.\"\n";

        $report = $this->service->synchronize([
            new SyncFile('projects/projet.md', self::project(skills: ['4.0.2/E02'])),
            new SyncFile('skills.yaml', $grid),
        ]);

        self::assertSame(['skills.yaml', 'projects/projet.md'], array_map(static fn (ContentSyncLine $l) => $l->path, $report->lines));
        self::assertSame(['updated', 'created'], self::statuses($report));
        self::assertStringContainsString('1 created', $report->lines[0]->message);
        self::assertNotNull($this->skills->findByCode('4.0.2/E02'));
    }

    #[Test]
    public function a_document_citing_a_code_that_neither_the_taxonomy_nor_the_push_knows_fails(): void
    {
        $report = $this->service->synchronize([new SyncFile('projects/projet.md', self::project(skills: ['4.0.2/Z99']))]);

        self::assertSame(['failed'], self::statuses($report));
        self::assertStringContainsString('Unknown competency code(s): 4.0.2/Z99', $report->lines[0]->message);
        self::assertSame([], $this->repository->saved);
    }

    #[Test]
    public function an_unchanged_grid_is_reported_as_such(): void
    {
        $report = $this->service->synchronize([new SyncFile('skills.yaml', self::GRID)]);

        self::assertSame(['unchanged'], self::statuses($report));
        self::assertNull($report->lines[0]->type);
        self::assertStringContainsString('0 created, 0 updated', $report->lines[0]->message);
    }

    #[Test]
    public function an_invalid_grid_fails_without_stopping_the_documents(): void
    {
        $report = $this->service->synchronize([
            new SyncFile('pages/a-propos.md', self::page()),
            new SyncFile('skills.yaml', "referentials: []\n"),
            new SyncFile('content/skills.yaml', "- not: [a mapping\n"),
        ]);

        self::assertSame(['failed', 'failed', 'created'], self::statuses($report));
        self::assertStringContainsString('non-empty list', $report->lines[0]->message);
        self::assertStringContainsString('invalid YAML', $report->lines[1]->message);
    }

    #[Test]
    public function a_deleted_grid_is_skipped_and_the_taxonomy_kept(): void
    {
        $report = $this->service->synchronize([new SyncFile('skills.yaml', null, deleted: true)]);

        self::assertSame(['skipped'], self::statuses($report));
        self::assertStringContainsString('never removed', $report->lines[0]->message);
        self::assertCount(3, $this->skills->all());
    }

    #[Test]
    public function a_deleted_document_that_git_wrote_is_moved_to_the_trash_once(): void
    {
        $created = $this->service->synchronize([new SyncFile('projects/projet.md', self::project())]);
        $postId = $created->lines[0]->postId;

        $report = $this->service->synchronize([new SyncFile('projects/projet.md', null, deleted: true)]);
        $again = $this->service->synchronize([new SyncFile('projects/projet.md', null, deleted: true)]);

        self::assertSame(['deleted'], self::statuses($report));
        self::assertSame($postId, $report->lines[0]->postId);
        self::assertSame('moved to the trash', $report->lines[0]->message);
        self::assertSame([$postId], $this->repository->trashed);
        self::assertSame(['unchanged'], self::statuses($again));
        self::assertSame('already in the trash', $again->lines[0]->message);
        self::assertSame([$postId], $this->repository->trashed);
    }

    #[Test]
    public function a_draft_that_git_wrote_is_retired_like_a_published_one(): void
    {
        $this->import->import(self::project(), new ImportOptions(source: ContentSource::Git, postStatus: 'draft'));

        $report = $this->service->synchronize([new SyncFile('projects/projet.md', null, deleted: true)]);

        self::assertSame(['deleted'], self::statuses($report));
        self::assertSame([100], $this->repository->trashed);
    }

    #[Test]
    public function a_deletion_that_the_store_refuses_is_a_failure_with_its_reason(): void
    {
        $this->service->synchronize([new SyncFile('projects/projet.md', self::project())]);
        $refusing = new class($this->repository) implements ContentRepository {
            public function __construct(private readonly InMemoryContentRepository $inner)
            {
            }

            public function findBySlug(ContentType $type, string $slug): ?StoredContent
            {
                return $this->inner->findBySlug($type, $slug);
            }

            public function findById(ContentType $type, int $postId): ?StoredContent
            {
                return $this->inner->findById($type, $postId);
            }

            public function save(ContentRecord $record): int
            {
                return $this->inner->save($record);
            }

            public function trash(int $postId): void
            {
                throw new \RuntimeException("Cannot move post {$postId} to the trash: WordPress refused.");
            }
        };
        $service = new ContentSyncService($this->import, $refusing, new SkillTermSynchronizer($this->skills), new SkillsGridLoader());

        $report = $service->synchronize([new SyncFile('projects/projet.md', null, deleted: true)]);

        self::assertSame(['failed'], self::statuses($report));
        self::assertSame('Cannot move post 100 to the trash: WordPress refused.', $report->lines[0]->message);
        self::assertSame('projet', $report->lines[0]->slug);
        self::assertTrue($report->hasFailures());
    }

    #[Test]
    public function a_file_both_deleted_and_carrying_content_is_a_deletion(): void
    {
        $this->service->synchronize([new SyncFile('projects/projet.md', self::project())]);

        $report = $this->service->synchronize([new SyncFile('projects/projet.md', self::project(body: 'Never written.'), deleted: true)]);

        self::assertSame(['deleted'], self::statuses($report));
        self::assertCount(1, $this->repository->saved);
        self::assertSame([100], $this->repository->trashed);
    }

    #[Test]
    public function a_document_deleted_then_recreated_in_the_same_batch_meets_the_trash_rule(): void
    {
        // A diff never names a path twice; a hand-built batch may. The second entry finds the post the first
        // one just trashed, and the trash rule (§4.4) holds: a human restores or removes the post first.
        $this->service->synchronize([new SyncFile('projects/projet.md', self::project())]);

        $report = $this->service->synchronize([
            new SyncFile('projects/projet.md', null, deleted: true),
            new SyncFile('projects/projet.md', self::project(body: 'Back again.')),
        ]);

        self::assertSame(['deleted', 'failed'], self::statuses($report));
        self::assertStringContainsString('in the trash', $report->lines[1]->message);
        self::assertCount(1, $this->repository->saved);
    }

    #[Test]
    public function a_deleted_document_written_elsewhere_or_made_by_hand_is_a_conflict(): void
    {
        $this->import->import(self::project(), new ImportOptions(source: ContentSource::Paste));
        $this->repository->seed(ContentType::Page, 'contact', new StoredContent(900, '', null, ''));

        $report = $this->service->synchronize([
            new SyncFile('projects/projet.md', null, deleted: true),
            new SyncFile('pages/contact.md', null, deleted: true),
        ]);

        self::assertSame(['conflict', 'conflict'], self::statuses($report));
        self::assertStringContainsString('last written from paste', $report->lines[0]->message);
        self::assertStringContainsString('outside the pipeline', $report->lines[1]->message);
        self::assertSame(900, $report->lines[1]->postId);
        self::assertSame([], $this->repository->trashed);
    }

    #[Test]
    public function a_deleted_document_without_post_is_skipped(): void
    {
        $report = $this->service->synchronize([new SyncFile('articles/jamais-ecrit.md', null, deleted: true)]);

        self::assertSame(['skipped'], self::statuses($report));
        self::assertStringContainsString('nothing to retire', $report->lines[0]->message);
        self::assertSame(ContentType::Article, $report->lines[0]->type);
        self::assertSame('jamais-ecrit', $report->lines[0]->slug);
    }

    #[Test]
    public function a_dry_run_decides_everything_and_writes_nothing(): void
    {
        $this->service->synchronize([new SyncFile('projects/projet.md', self::project())]);
        $saved = count($this->repository->saved);

        $report = $this->service->synchronize([
            new SyncFile('pages/a-propos.md', self::page()),
            new SyncFile('projects/projet.md', null, deleted: true),
        ], dryRun: true);

        self::assertTrue($report->dryRun);
        self::assertSame(['created', 'deleted'], self::statuses($report));
        self::assertSame('would be moved to the trash', $report->lines[1]->message);
        self::assertCount($saved, $this->repository->saved);
        self::assertSame([], $this->repository->trashed);
    }

    #[Test]
    public function a_file_without_content_that_is_not_deleted_fails(): void
    {
        $report = $this->service->synchronize([new SyncFile('projects/projet.md', null), new SyncFile('skills.yaml', null)]);

        self::assertSame(['failed', 'failed'], self::statuses($report));
        self::assertStringContainsString('no content', $report->lines[1]->message);
    }

    #[Test]
    public function a_leading_content_prefix_or_dot_slash_is_tolerated(): void
    {
        $report = $this->service->synchronize([
            new SyncFile('content/projects/projet.md', self::project()),
            new SyncFile('./pages/a-propos.md', self::page()),
        ]);

        self::assertSame(['created', 'created'], self::statuses($report));
        self::assertSame('content/projects/projet.md', $report->lines[0]->path);
    }

    #[Test]
    public function a_file_whose_name_is_not_a_slug_fails(): void
    {
        $report = $this->service->synchronize([new SyncFile('projects/Mon Projet.md', self::project())]);

        self::assertSame(['failed'], self::statuses($report));
        self::assertStringContainsString('is not a slug', $report->lines[0]->message);
    }

    #[Test]
    public function the_report_counts_every_status(): void
    {
        $report = new ContentSyncReport([
            new ContentSyncLine('a', ContentSyncStatus::Created),
            new ContentSyncLine('b', ContentSyncStatus::Created),
            new ContentSyncLine('c', ContentSyncStatus::Conflict),
        ], false);

        self::assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'conflict' => 1, 'failed' => 0, 'deleted' => 0, 'skipped' => 0], $report->counts());
        self::assertTrue($report->hasConflicts());
        self::assertFalse($report->hasFailures());
        self::assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflict' => 0, 'failed' => 0, 'deleted' => 0, 'skipped' => 0], (new ContentSyncReport([], true))->counts());
    }

    /**
     * @return list<string>
     */
    private static function statuses(ContentSyncReport $report): array
    {
        return array_map(static fn (ContentSyncLine $line): string => $line->status->value, $report->lines);
    }

    /**
     * @param list<string> $skills
     */
    private static function project(string $slug = 'projet', array $skills = ['4.0.2/E00'], string $body = 'Corps du projet.'): string
    {
        $codes = implode(', ', array_map(static fn (string $code): string => "\"{$code}\"", $skills));

        return "---\ntitle: \"Projet\"\nslug: \"{$slug}\"\ntype: \"project\"\nstatus: \"en-cours\"\ntags: [php]\nskills: [{$codes}]\n---\n\n{$body}\n";
    }

    private static function page(string $slug = 'a-propos'): string
    {
        return "---\ntitle: \"À propos\"\nslug: \"{$slug}\"\ntype: \"page\"\n---\n\nLe parcours.\n";
    }
}
