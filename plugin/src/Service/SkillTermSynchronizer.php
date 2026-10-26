<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\Referential;
use Voltz\PortfolioMd\Domain\Skill;
use Voltz\PortfolioMd\Domain\SkillsGrid;

/**
 * Makes the `skill` taxonomy match a SkillsGrid (ADR-014).
 *
 * Referentials become top-level terms found by slug; competencies become
 * child terms found by their code meta first, by slug under the parent
 * otherwise. Existing terms are updated in place (name, slug, description,
 * parent, code) so that their ids, and the projects attached to them, are
 * preserved. Terms present in WordPress but absent from the grid are never
 * deleted: they are reported as orphans for a human to decide.
 *
 * In dry-run mode nothing is written; the report says what would change.
 */
final class SkillTermSynchronizer
{
    public function __construct(private readonly SkillTermGateway $terms)
    {
    }

    public function synchronize(SkillsGrid $grid, bool $dryRun = false): SyncReport
    {
        $report = new SyncReport($dryRun);

        foreach ($grid->referentials as $referential) {
            $parent = $this->syncReferential($referential, $dryRun, $report);
            $parentId = $parent?->id ?? 0;

            foreach ($referential->skills as $skill) {
                $this->syncSkill($skill, $referential, $parentId, $dryRun, $report);
            }
        }

        foreach ($this->terms->all() as $term) {
            if ($term->code !== null && $term->parentId !== 0 && $grid->findSkill($term->code) === null) {
                $report->orphan("{$term->code} \"{$term->name}\" (term {$term->id})");
            }
        }

        return $report;
    }

    private function syncReferential(Referential $referential, bool $dryRun, SyncReport $report): ?SkillTermRecord
    {
        $label = "referential {$referential->code} \"{$referential->name}\"";
        $existing = $this->terms->findBySlug($referential->slug, 0);

        if ($existing === null) {
            $report->created($label);

            return $dryRun ? null : $this->terms->insert($referential->name, $referential->slug, $referential->description, 0, $referential->code);
        }

        $changed = self::changedFields($existing, $referential->name, $referential->slug, $referential->description, 0, $referential->code);
        if ($changed === []) {
            $report->unchanged($label);

            return $existing;
        }

        $report->updated($label, $changed);

        return $dryRun ? $existing : $this->terms->update($existing->id, $referential->name, $referential->slug, $referential->description, 0, $referential->code);
    }

    private function syncSkill(Skill $skill, Referential $referential, int $parentId, bool $dryRun, SyncReport $report): void
    {
        $label = "skill {$skill->code} \"{$skill->name}\"";
        $existing = $this->terms->findByCode($skill->code) ?? $this->terms->findBySlug($skill->slug, $parentId);

        if ($existing === null) {
            $report->created($label);
            if (!$dryRun) {
                $this->terms->insert($skill->name, $skill->slug, $skill->description, $parentId, $skill->code);
            }

            return;
        }

        $changed = self::changedFields($existing, $skill->name, $skill->slug, $skill->description, $parentId, $skill->code);
        if ($changed === []) {
            $report->unchanged($label);

            return;
        }

        $report->updated($label, $changed);
        if (!$dryRun) {
            $this->terms->update($existing->id, $skill->name, $skill->slug, $skill->description, $parentId, $skill->code);
        }
    }

    /**
     * @return list<string>
     */
    private static function changedFields(SkillTermRecord $existing, string $name, string $slug, string $description, int $parentId, ?string $code): array
    {
        $changed = [];
        if ($existing->name !== $name) {
            $changed[] = 'name';
        }
        if ($existing->slug !== $slug) {
            $changed[] = 'slug';
        }
        if ($existing->description !== $description) {
            $changed[] = 'description';
        }
        if ($existing->parentId !== $parentId) {
            $changed[] = 'parent';
        }
        if ($existing->code !== $code) {
            $changed[] = 'code';
        }

        return $changed;
    }
}
