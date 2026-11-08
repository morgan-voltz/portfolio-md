<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\TagNames;
use Voltz\PortfolioMd\Domain\TechTagName;
use Voltz\PortfolioMd\Service\TagTermRecord;
use Voltz\PortfolioMd\Service\TagTermSynchronizer;
use Voltz\PortfolioMd\Tests\Support\InMemoryTagTermGateway;

/**
 * The names file into the tech_tag terms (ADR-024): created, renamed,
 * unchanged, orphaned, failed; a dry run decides without writing.
 */
#[CoversClass(TagTermSynchronizer::class)]
#[CoversClass(TagTermRecord::class)]
final class TagTermSynchronizerTest extends TestCase
{
    private InMemoryTagTermGateway $terms;
    private TagTermSynchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->terms = new InMemoryTagTermGateway();
        $this->synchronizer = new TagTermSynchronizer($this->terms);
    }

    private static function names(): TagNames
    {
        return new TagNames([
            new TechTagName('csharp', 'C#'),
            new TechTagName('architecture-hexagonale', 'Architecture hexagonale', ['de' => 'Hexagonale Architektur', 'en' => 'Hexagonal architecture']),
        ]);
    }

    #[Test]
    public function creates_the_missing_terms_with_their_names(): void
    {
        $report = $this->synchronizer->synchronize(self::names());

        self::assertSame(2, $report->createdCount());
        self::assertSame('2 created, 0 updated, 0 re-coded, 0 unchanged, 0 orphan(s)', $report->summary());
        self::assertSame(['insert csharp', 'insert architecture-hexagonale'], $this->terms->writes);
        $term = $this->terms->findBySlug('architecture-hexagonale');
        self::assertNotNull($term);
        self::assertSame('Architecture hexagonale', $term->name);
        self::assertSame(['de' => 'Hexagonale Architektur', 'en' => 'Hexagonal architecture'], $term->translations);
    }

    #[Test]
    public function renames_a_term_the_import_created_with_its_slug_and_leaves_a_right_one_alone(): void
    {
        $this->terms->seed('csharp', 'csharp');
        $this->terms->seed('architecture-hexagonale', 'Architecture hexagonale', ['de' => 'Hexagonale Architektur', 'en' => 'Hexagonal architecture']);

        $report = $this->synchronizer->synchronize(self::names());

        self::assertSame(1, $report->updatedCount());
        self::assertSame(1, $report->unchangedCount());
        self::assertSame(['update 1'], $this->terms->writes);
        self::assertSame('C#', $this->terms->findBySlug('csharp')?->name);
        self::assertStringContainsString('updated tag csharp "C#" (name)', implode("\n", $report->lines()));
    }

    #[Test]
    public function a_changed_translation_is_an_update_and_a_dropped_one_too(): void
    {
        $this->terms->seed('csharp', 'C#');
        $this->terms->seed('architecture-hexagonale', 'Architecture hexagonale', ['de' => 'Sechseckige Architektur', 'en' => 'Hexagonal architecture']);

        $report = $this->synchronizer->synchronize(self::names());

        self::assertSame(1, $report->updatedCount());
        self::assertStringContainsString('(translations)', implode("\n", $report->lines()));
        self::assertSame('Hexagonale Architektur', $this->terms->findBySlug('architecture-hexagonale')?->translations['de']);

        $plain = new TagNames([new TechTagName('csharp', 'C#'), new TechTagName('architecture-hexagonale', 'Architecture hexagonale')]);
        $second = $this->synchronizer->synchronize($plain);
        self::assertSame(1, $second->updatedCount(), 'translations removed from the file are removed from the term');
        self::assertSame([], $this->terms->findBySlug('architecture-hexagonale')?->translations);
    }

    #[Test]
    public function a_term_absent_from_the_file_is_reported_and_kept(): void
    {
        $this->terms->seed('cobol', 'cobol');

        $report = $this->synchronizer->synchronize(self::names());

        self::assertSame(1, $report->orphanCount());
        self::assertStringContainsString('tag "cobol" "cobol" (term 1) is not in the file', implode("\n", $report->lines()));
        self::assertNotNull($this->terms->findBySlug('cobol'));
        self::assertFalse($report->hasFailures());
    }

    #[Test]
    public function a_dry_run_decides_without_writing(): void
    {
        $this->terms->seed('csharp', 'csharp');

        $report = $this->synchronizer->synchronize(self::names(), true);

        self::assertTrue($report->isDryRun());
        self::assertSame(1, $report->createdCount());
        self::assertSame(1, $report->updatedCount());
        self::assertSame([], $this->terms->writes);
        self::assertSame('csharp', $this->terms->findBySlug('csharp')?->name);
    }

    #[Test]
    public function a_failed_write_is_reported_and_the_rest_goes_on(): void
    {
        $this->terms->failing = ['csharp'];

        $report = $this->synchronizer->synchronize(self::names());

        self::assertSame(1, $report->failedCount());
        self::assertSame(1, $report->createdCount());
        self::assertTrue($report->hasFailures());
        self::assertStringContainsString('cannot write csharp', implode("\n", $report->lines()));
    }
}
