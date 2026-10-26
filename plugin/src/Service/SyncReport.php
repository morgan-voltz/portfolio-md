<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * What a synchronization did, or would do in dry-run mode: one line per
 * noteworthy term plus counters, ready to be printed by the CLI command.
 *
 * Failures are recorded rather than thrown so that one refused term never
 * hides what happened to the others; the command decides how to exit.
 */
final class SyncReport
{
    /** @var list<string> */
    private array $lines = [];

    private int $created = 0;
    private int $updated = 0;
    private int $recoded = 0;
    private int $unchanged = 0;
    private int $orphans = 0;
    private int $failed = 0;

    public function __construct(private readonly bool $dryRun)
    {
    }

    public function created(string $label): void
    {
        $this->created++;
        $this->lines[] = ($this->dryRun ? 'would create ' : 'created ') . $label;
    }

    /**
     * @param list<string> $changedFields
     */
    public function updated(string $label, array $changedFields): void
    {
        $this->updated++;
        $this->lines[] = ($this->dryRun ? 'would update ' : 'updated ') . $label . ' (' . implode(', ', $changedFields) . ')';
    }

    /**
     * An existing term matched by slug carried another code: its identity
     * changes, which deserves a distinct line from a routine update.
     */
    public function recoded(string $label, string $previousCode): void
    {
        $this->recoded++;
        $this->lines[] = ($this->dryRun ? 'would re-code ' : 're-coded ') . $label . " (was {$previousCode})";
    }

    public function unchanged(string $label): void
    {
        $this->unchanged++;
    }

    public function orphan(string $label): void
    {
        $this->orphans++;
        $this->lines[] = 'orphan (in WordPress, not in the grid, left untouched) ' . $label;
    }

    public function failed(string $label, string $reason): void
    {
        $this->failed++;
        $this->lines[] = 'FAILED ' . $label . ': ' . $reason;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function createdCount(): int
    {
        return $this->created;
    }

    public function updatedCount(): int
    {
        return $this->updated;
    }

    public function recodedCount(): int
    {
        return $this->recoded;
    }

    public function unchangedCount(): int
    {
        return $this->unchanged;
    }

    public function orphanCount(): int
    {
        return $this->orphans;
    }

    public function failedCount(): int
    {
        return $this->failed;
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    public function summary(): string
    {
        $summary = sprintf(
            '%s%d created, %d updated, %d re-coded, %d unchanged, %d orphan(s)',
            $this->dryRun ? 'dry run: ' : '',
            $this->created,
            $this->updated,
            $this->recoded,
            $this->unchanged,
            $this->orphans,
        );

        return $this->failed > 0 ? $summary . sprintf(', %d FAILED', $this->failed) : $summary;
    }
}
