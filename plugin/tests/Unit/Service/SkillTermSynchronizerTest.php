<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Referential;
use Voltz\PortfolioMd\Domain\Skill;
use Voltz\PortfolioMd\Domain\SkillsGrid;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
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
        self::assertSame('5 created, 0 updated, 0 unchanged, 0 orphan(s)', $report->summary());

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
    public function finds_a_child_by_slug_when_it_has_no_code_yet_and_gives_it_one(): void
    {
        $parent = $this->terms->seed('Compétences 4.0.1', '4-0-1', 'Première année.', 0, null);
        $this->terms->seed('Utiliser des outils collaboratifs', 'fr-01-outils', 'Découverte.', $parent->id, null);

        $report = $this->synchronizer->synchronize(self::grid());

        self::assertSame(3, $report->createdCount());
        self::assertSame(2, $report->updatedCount(), 'parent and child both gain their code');
        self::assertSame('4.0.1/FR-01', $this->terms->findBySlug('fr-01-outils', $parent->id)?->code);
    }

    #[Test]
    public function reports_terms_absent_from_the_grid_as_orphans_without_deleting_them(): void
    {
        $this->synchronizer->synchronize(self::grid());
        $parent = $this->terms->findBySlug('4-0-1', 0);
        $this->terms->seed('Ancienne compétence', 'fr-99-ancienne', 'Retirée.', $parent->id, '4.0.1/FR-99');

        $report = $this->synchronizer->synchronize(self::grid());

        self::assertSame(1, $report->orphanCount());
        self::assertStringContainsString('4.0.1/FR-99', $report->lines()[0]);
        self::assertNotNull($this->terms->findByCode('4.0.1/FR-99'));
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
    public function synchronizes_the_real_grid_twice_without_drift(): void
    {
        $grid = (new SkillsGridLoader())->load(self::GRID_FILE);

        $first = $this->synchronizer->synchronize($grid);
        $second = $this->synchronizer->synchronize($grid);

        self::assertSame(count($grid->referentials) + $grid->countSkills(), $first->createdCount());
        self::assertSame(0, $second->createdCount() + $second->updatedCount() + $second->orphanCount());
        self::assertCount(count($grid->referentials) + $grid->countSkills(), $this->terms->all());
    }
}
