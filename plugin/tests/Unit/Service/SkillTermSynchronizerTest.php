<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Voltz\PortfolioMd\Domain\Referential;
use Voltz\PortfolioMd\Domain\Skill;
use Voltz\PortfolioMd\Domain\SkillsGrid;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\Service\SkillTermGateway;
use Voltz\PortfolioMd\Service\SkillTermRecord;
use Voltz\PortfolioMd\Service\SkillTermSynchronizer;
use Voltz\PortfolioMd\Service\SyncReport;
use Voltz\PortfolioMd\Tests\Support\InMemorySkillTermGateway;

#[CoversClass(SkillTermSynchronizer::class)]
#[CoversClass(SyncReport::class)]
#[CoversClass(SkillTermRecord::class)]
final class SkillTermSynchronizerTest extends TestCase
{
    private const GRID_FILE = __DIR__ . '/../../../../content/skills.yaml';

    private InMemorySkillTermGateway $terms;
    private SkillTermSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->terms = new InMemorySkillTermGateway();
        $this->synchronizer = new SkillTermSynchronizer($this->terms);
    }

    private static function grid(): SkillsGrid
    {
        return new SkillsGrid([
            new Referential('4.0.1', '4-0-1', 'Compétences 4.0.1', 'Première année.', [
                new Skill('4.0.1/FR-01', 'fr-01-outils', 'Utiliser des outils collaboratifs', 'Découverte.'),
                new Skill('4.0.1/FR-02', 'fr-02-maquettes', 'Créer des maquettes', 'Maquette.'),
            ]),
            new Referential('4.0.2', '4-0-2', 'Compétences 4.0.2', 'Deuxième année.', [
                new Skill('4.0.2/E07', 'e07-api', 'Exposer une API', 'Étape 7.'),
            ]),
        ]);
    }

    #[Test]
    public function creates_parents_and_children_on_an_empty_taxonomy(): void
    {
        $report = $this->synchronizer->synchronize(self::grid());

        self::assertSame(5, $report->createdCount());
        self::assertSame(0, $report->updatedCount());
        self::assertSame('5 created, 0 updated, 0 re-coded, 0 unchanged, 0 orphan(s)', $report->summary());

        $parent = $this->terms->findBySlug('4-0-1', 0);
        self::assertNotNull($parent);
        self::assertSame('4.0.1', $parent->code);

        $child = $this->terms->findByCode('4.0.1/FR-02');
        self::assertNotNull($child);
        self::assertSame($parent->id, $child->parentId);
        self::assertSame('fr-02-maquettes', $child->slug);
        self::assertSame('Créer des maquettes', $child->name);
    }

    #[Test]
    public function a_second_run_changes_nothing(): void
    {
        $this->synchronizer->synchronize(self::grid());
        $writesAfterFirstRun = count($this->terms->writes);

        $report = $this->synchronizer->synchronize(self::grid());

        self::assertSame(0, $report->createdCount());
        self::assertSame(0, $report->updatedCount());
        self::assertSame(5, $report->unchangedCount());
        self::assertSame(0, $report->orphanCount());
        self::assertCount($writesAfterFirstRun, $this->terms->writes);
    }

    #[Test]
    public function updates_an_existing_term_in_place_when_the_grid_changes(): void
    {
        $this->synchronizer->synchronize(self::grid());
        $before = $this->terms->findByCode('4.0.1/FR-01');

        $changed = new SkillsGrid([
            new Referential('4.0.1', '4-0-1', 'Compétences 4.0.1', 'Première année.', [
                new Skill('4.0.1/FR-01', 'fr-01-gestion-de-projet', 'Utiliser des outils collaboratifs de gestion de projet', 'Découverte et Versionning.'),
                new Skill('4.0.1/FR-02', 'fr-02-maquettes', 'Créer des maquettes', 'Maquette.'),
            ]),
            new Referential('4.0.2', '4-0-2', 'Compétences 4.0.2', 'Deuxième année.', [
                new Skill('4.0.2/E07', 'e07-api', 'Exposer une API', 'Étape 7.'),
            ]),
        ]);

        $report = $this->synchronizer->synchronize($changed);
        $after = $this->terms->findByCode('4.0.1/FR-01');

        self::assertSame(1, $report->updatedCount());
        self::assertSame(4, $report->unchangedCount());
        self::assertSame($before?->id, $after?->id, 'the term id must survive an update');
        self::assertSame('fr-01-gestion-de-projet', $after?->slug);
        self::assertStringContainsString('(name, slug, description)', $report->lines()[0]);
    }

    #[Test]
    public function a_renamed_referential_slug_is_an_update_found_by_code_not_a_duplicate(): void
    {
        $this->synchronizer->synchronize(self::grid());
        $before = $this->terms->findByCode('4.0.1');

        $renamed = new SkillsGrid([
            new Referential('4.0.1', 'competences-4-0-1', 'Compétences 4.0.1', 'Première année.', [
                new Skill('4.0.1/FR-01', 'fr-01-outils', 'Utiliser des outils collaboratifs', 'Découverte.'),
                new Skill('4.0.1/FR-02', 'fr-02-maquettes', 'Créer des maquettes', 'Maquette.'),
            ]),
            new Referential('4.0.2', '4-0-2', 'Compétences 4.0.2', 'Deuxième année.', [
                new Skill('4.0.2/E07', 'e07-api', 'Exposer une API', 'Étape 7.'),
            ]),
        ]);

        $report = $this->synchronizer->synchronize($renamed);

        self::assertSame(0, $report->createdCount());
        self::assertSame(1, $report->updatedCount());
        self::assertSame($before?->id, $this->terms->findByCode('4.0.1')?->id);
        self::assertSame('competences-4-0-1', $this->terms->findByCode('4.0.1')?->slug);
        self::assertCount(5, $this->terms->all(), 'no duplicate top-level term');
    }

    #[Test]
    public function finds_a_child_by_slug_when_it_has_no_code_yet_and_gives_it_one(): void
    {
        $parent = $this->terms->seed('Compétences 4.0.1', '4-0-1', 'Première année.', 0, null);
        $this->terms->seed('Utiliser des outils collaboratifs', 'fr-01-outils', 'Découverte.', $parent->id, null);

        $report = $this->synchronizer->synchronize(self::grid());

        self::assertSame(3, $report->createdCount());
        self::assertSame(2, $report->updatedCount(), 'parent and child both gain their code');
        self::assertSame(0, $report->recodedCount());
        self::assertSame('4.0.1/FR-01', $this->terms->findBySlug('fr-01-outils', $parent->id)?->code);
    }

    #[Test]
    public function a_term_matched_by_slug_that_carried_another_code_is_reported_as_recoded(): void
    {
        $parent = $this->terms->seed('Compétences 4.0.1', '4-0-1', 'Première année.', 0, '4.0.1');
        $this->terms->seed('Ancien intitulé', 'fr-01-outils', 'Ancien.', $parent->id, '4.0.1/OLD-01');

        $report = $this->synchronizer->synchronize(self::grid());

        self::assertSame(1, $report->recodedCount());
        self::assertSame(0, $report->orphanCount(), 'the re-coded term is claimed, not orphaned');
        self::assertStringContainsString('re-coded skill 4.0.1/FR-01', $report->lines()[0]);
        self::assertStringContainsString('(was 4.0.1/OLD-01)', $report->lines()[0]);
        self::assertSame('4.0.1/FR-01', $this->terms->findBySlug('fr-01-outils', $parent->id)?->code);
    }

    #[Test]
    public function reports_every_unclaimed_term_as_an_orphan_without_deleting_it(): void
    {
        $this->synchronizer->synchronize(self::grid());
        $parent = $this->terms->findBySlug('4-0-1', 0);
        $this->terms->seed('Ancienne compétence', 'fr-99-ancienne', 'Retirée.', $parent->id, '4.0.1/FR-99');
        $this->terms->seed('Ancien référentiel', '4-0-0', 'Retiré.', 0, '4.0.0');
        $this->terms->seed('Terme sans code', 'sans-code', 'Créé à la main.', $parent->id, null);

        $report = $this->synchronizer->synchronize(self::grid());

        self::assertSame(3, $report->orphanCount());
        self::assertStringContainsString('4.0.1/FR-99', implode("\n", $report->lines()));
        self::assertStringContainsString('4.0.0', implode("\n", $report->lines()));
        self::assertStringContainsString('no code', implode("\n", $report->lines()));
        self::assertCount(8, $this->terms->all(), 'nothing deleted');
    }

    #[Test]
    public function dry_run_reports_without_writing(): void
    {
        $report = $this->synchronizer->synchronize(self::grid(), dryRun: true);

        self::assertTrue($report->isDryRun());
        self::assertSame(5, $report->createdCount());
        self::assertStringStartsWith('would create', $report->lines()[0]);
        self::assertStringStartsWith('dry run: ', $report->summary());
        self::assertSame([], $this->terms->all());
        self::assertSame([], $this->terms->writes);
    }

    #[Test]
    public function dry_run_never_looks_for_children_under_a_parent_that_does_not_exist_yet(): void
    {
        // A top-level term that happens to carry a child's slug must not be mistaken for that child.
        $this->terms->seed('Homonyme au niveau racine', 'fr-01-outils', 'Pas une compétence.', 0, null);

        $report = $this->synchronizer->synchronize(self::grid(), dryRun: true);

        self::assertSame(5, $report->createdCount());
        self::assertSame(0, $report->updatedCount());
        self::assertSame(1, $report->orphanCount(), 'the homonym is unclaimed');
    }

    #[Test]
    public function a_term_the_store_refuses_is_reported_and_the_run_continues(): void
    {
        $refusing = new class ($this->terms) implements SkillTermGateway {
            public function __construct(private readonly InMemorySkillTermGateway $inner)
            {
            }

            public function findByCode(string $code): ?SkillTermRecord
            {
                return $this->inner->findByCode($code);
            }

            public function findBySlug(string $slug, int $parentId): ?SkillTermRecord
            {
                return $this->inner->findBySlug($slug, $parentId);
            }

            public function insert(string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
            {
                if ($slug === 'fr-02-maquettes') {
                    throw new RuntimeException('refused by the store');
                }

                return $this->inner->insert($name, $slug, $description, $parentId, $code);
            }

            public function update(int $termId, string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
            {
                return $this->inner->update($termId, $name, $slug, $description, $parentId, $code);
            }

            public function all(): array
            {
                return $this->inner->all();
            }
        };

        $report = (new SkillTermSynchronizer($refusing))->synchronize(self::grid());

        self::assertSame(1, $report->failedCount());
        self::assertTrue($report->hasFailures());
        self::assertSame(4, $report->createdCount(), 'a refused term is not also counted as created');
        self::assertStringContainsString('FAILED skill 4.0.1/FR-02', implode("\n", $report->lines()));
        self::assertStringNotContainsString('created skill 4.0.1/FR-02', implode("\n", $report->lines()));
        self::assertStringContainsString('1 FAILED', $report->summary());
        self::assertNotNull($this->terms->findByCode('4.0.2/E07'), 'later terms are still synchronized');
        self::assertCount(4, $this->terms->all());
    }

    #[Test]
    public function a_refused_update_is_a_failure_not_an_update(): void
    {
        $this->synchronizer->synchronize(self::grid());
        $collision = new SkillsGrid([
            new Referential('4.0.1', '4-0-1', 'Compétences 4.0.1', 'Première année.', [
                new Skill('4.0.1/FR-01', 'fr-02-maquettes', 'Utiliser des outils collaboratifs', 'Découverte.'),
                new Skill('4.0.1/FR-02', 'fr-02-maquettes-bis', 'Créer des maquettes', 'Maquette.'),
            ]),
            new Referential('4.0.2', '4-0-2', 'Compétences 4.0.2', 'Deuxième année.', [
                new Skill('4.0.2/E07', 'e07-api', 'Exposer une API', 'Étape 7.'),
            ]),
        ]);

        $report = $this->synchronizer->synchronize($collision);

        self::assertSame(1, $report->failedCount(), 'FR-01 cannot take a slug FR-02 still holds');
        self::assertSame(1, $report->updatedCount(), 'FR-02 moves to its new slug');
        self::assertStringNotContainsString('updated skill 4.0.1/FR-01', implode("\n", $report->lines()));
        self::assertSame('fr-01-outils', $this->terms->findByCode('4.0.1/FR-01')?->slug, 'the refused term is left as it was');
    }

    #[Test]
    public function when_a_referential_cannot_be_written_its_skills_are_failures_not_creations(): void
    {
        $refusing = new class ($this->terms) implements SkillTermGateway {
            public function __construct(private readonly InMemorySkillTermGateway $inner)
            {
            }

            public function findByCode(string $code): ?SkillTermRecord
            {
                return $this->inner->findByCode($code);
            }

            public function findBySlug(string $slug, int $parentId): ?SkillTermRecord
            {
                return $this->inner->findBySlug($slug, $parentId);
            }

            public function insert(string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
            {
                if ($slug === '4-0-1') {
                    throw new RuntimeException('refused by the store');
                }

                return $this->inner->insert($name, $slug, $description, $parentId, $code);
            }

            public function update(int $termId, string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
            {
                return $this->inner->update($termId, $name, $slug, $description, $parentId, $code);
            }

            public function all(): array
            {
                return $this->inner->all();
            }
        };

        $report = (new SkillTermSynchronizer($refusing))->synchronize(self::grid());

        self::assertSame(3, $report->failedCount(), 'the referential and its two skills');
        self::assertSame(2, $report->createdCount(), 'only the other referential and its skill');
        self::assertStringContainsString('FAILED skill 4.0.1/FR-01 "Utiliser des outils collaboratifs": its referential could not be written', implode("\n", $report->lines()));
        self::assertCount(2, $this->terms->all());
    }

    #[Test]
    public function a_referential_matched_by_slug_under_another_code_is_reported_as_recoded(): void
    {
        $this->terms->seed('Compétences 4.0.1', '4-0-1', 'Première année.', 0, '4.0.0');

        $report = $this->synchronizer->synchronize(self::grid());

        self::assertSame(1, $report->recodedCount());
        self::assertStringContainsString('re-coded referential 4.0.1', $report->lines()[0]);
        self::assertStringContainsString('(was 4.0.0)', $report->lines()[0]);
        self::assertSame('4.0.1', $this->terms->findBySlug('4-0-1', 0)?->code);
    }

    #[Test]
    public function synchronizes_the_real_grid_twice_without_drift(): void
    {
        $grid = (new SkillsGridLoader())->load(self::GRID_FILE);

        $first = $this->synchronizer->synchronize($grid);
        $second = $this->synchronizer->synchronize($grid);

        self::assertSame(count($grid->referentials) + $grid->countSkills(), $first->createdCount());
        self::assertSame(0, $second->createdCount() + $second->updatedCount() + $second->recodedCount() + $second->orphanCount() + $second->failedCount());
        self::assertCount(count($grid->referentials) + $grid->countSkills(), $this->terms->all());
    }
}
