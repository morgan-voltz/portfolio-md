<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use RuntimeException;
use Voltz\PortfolioMd\Domain\TagNames;
use Voltz\PortfolioMd\Domain\TechTagName;

/**
 * Brings the tech_tag terms in line with the names file (ADR-024): a slug
 * of the file without term gets one, a term whose name or translations
 * differ is rewritten, a term absent from the file is reported as an orphan
 * and never deleted (a sheet may still use it; the import creates a term
 * with the slug for name, and this is how it gets its names). The same
 * report as the skills grid; a dry run decides without writing.
 */
final class TagTermSynchronizer
{
    public function __construct(private readonly TagTermGateway $terms)
    {
    }

    public function synchronize(TagNames $names, bool $dryRun = false): SyncReport
    {
        $report = new SyncReport($dryRun);
        $claimed = [];

        foreach ($names->tags as $tag) {
            $this->sync($tag, $dryRun, $report, $claimed);
        }

        foreach ($this->terms->all() as $term) {
            if (!isset($claimed[$term->id])) {
                $report->orphan("tag \"{$term->slug}\" \"{$term->name}\" (term {$term->id}) is not in the file");
            }
        }

        return $report;
    }

    /**
     * @param array<int, true> $claimed
     */
    private function sync(TechTagName $tag, bool $dryRun, SyncReport $report, array &$claimed): void
    {
        $label = "tag {$tag->slug} \"{$tag->name}\"";
        try {
            $existing = $this->terms->findBySlug($tag->slug);
            if ($existing === null) {
                if (!$dryRun) {
                    $created = $this->terms->insert($tag->slug, $tag->name, $tag->translations);
                    $claimed[$created->id] = true;
                }
                $report->created($label);

                return;
            }

            $claimed[$existing->id] = true;
            $changed = self::changedFields($existing, $tag);
            if ($changed === []) {
                $report->unchanged($label);

                return;
            }
            if (!$dryRun) {
                $this->terms->update($existing->id, $tag->name, $tag->translations);
            }
            $report->updated($label, $changed);
        } catch (RuntimeException $exception) {
            $report->failed($label, $exception->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private static function changedFields(TagTermRecord $existing, TechTagName $tag): array
    {
        $changed = [];
        if ($existing->name !== $tag->name) {
            $changed[] = 'name';
        }
        $before = $existing->translations;
        $after = $tag->translations;
        ksort($before);
        ksort($after);
        if ($before !== $after) {
            $changed[] = 'translations';
        }

        return $changed;
    }
}
