<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * What a synchronization did, or would do in dry-run mode: one line per
 * term plus counters, ready to be printed by the CLI command.
 */
final class SyncReport
{
    /** @var list<string> */
    private array $lines = [];

    private int $created = 0;
    private int $updated = 0;
    private int $unchanged = 0;
    private int $orphans = 0;

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

    public function unchanged(string $label): void
    {
        $this->unchanged++;
    }

    public function orphan(string $label): void
    {
        $this->orphans++;
        $this->lines[] = 'orphan (in WordPress, not in the grid, left untouched) ' . $label;
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

    public function unchangedCount(): int
    {
        return $this->unchanged;
    }

    public function orphanCount(): int
    {
        return $this->orphans;
    }

    public function summary(): string
    {
        return sprintf(
            '%s%d created, %d updated, %d unchanged, %d orphan(s)',
            $this->dryRun ? 'dry run: ' : '',
            $this->created,
            $this->updated,
            $this->unchanged,
            $this->orphans,
        );
    }
}
