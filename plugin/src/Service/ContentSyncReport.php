<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * What a Git synchronization did with a batch of files: one line per
 * file, in the order they were processed, and the counts by status.
 */
final readonly class ContentSyncReport
{
    /**
     * @param list<ContentSyncLine> $lines
     */
    public function __construct(
        public array $lines,
        public bool $dryRun,
    ) {
    }

    /**
     * How many files ended in each status, every status present.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(ContentSyncStatus::values(), 0);
        foreach ($this->lines as $line) {
            $counts[$line->status->value]++;
        }

        return $counts;
    }

    /** Whether at least one file was left alone because a human has to choose. */
    public function hasConflicts(): bool
    {
        return $this->has(ContentSyncStatus::Conflict);
    }

    /** Whether at least one file could not be processed. */
    public function hasFailures(): bool
    {
        return $this->has(ContentSyncStatus::Failed);
    }

    private function has(ContentSyncStatus $status): bool
    {
        foreach ($this->lines as $line) {
            if ($line->status === $status) {
                return true;
            }
        }

        return false;
    }
}
