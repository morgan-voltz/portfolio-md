<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use RuntimeException;
use Voltz\PortfolioMd\Domain\Referential;
use Voltz\PortfolioMd\Domain\Skill;
use Voltz\PortfolioMd\Domain\SkillsGrid;

/**
 * Makes the `skill` taxonomy match a SkillsGrid (ADR-014).
 *
 * Referentials become top-level terms, competencies child terms. Both are
 * found by their code meta first, then by slug, so that a renamed slug is an
 * in-place update rather than a duplicate. Existing terms are updated in
 * place (name, slug, description, parent, code) so that their ids, and the
 * projects attached to them, are preserved. A term matched by slug that
 * carried another code is reported as re-coded, because its identity
 * changes. Every term of the taxonomy that the grid did not claim is reported
 * as an orphan and left untouched: deleting is a human decision.
 *
 * A term the store refuses is recorded as failed and the run continues, so
 * that one bad entry never hides what happened to the others. In dry-run
 * mode nothing is written; the report says what would change.
 */
final class SkillTermSynchronizer
{
    public function __construct(private readonly SkillTermGateway $terms)
    {
    }

    public function synchronize(SkillsGrid $grid, bool $dryRun = false): SyncReport
    {
        $report = new SyncReport($dryRun);
        /** @var array<int, true> ids of the terms the grid claimed */
        $claimed = [];

        foreach ($grid->referentials as $referential) {
            $parent = $this->syncReferential($referential, $dryRun, $report, $claimed);

            foreach ($referential->skills as $skill) {
                $this->syncSkill($skill, $parent, $dryRun, $report, $claimed);
            }
        }

        foreach ($this->terms->all() as $term) {
            if (!isset($claimed[$term->id])) {
                $report->orphan(($term->code ?? 'no code') . " \"{$term->name}\" (term {$term->id}, slug {$term->slug})");
            }
        }

        return $report;
    }

    /**
     * Returns the parent term, or null when it does not exist yet and this is
     * a dry run (nothing can be looked up under a parent that has no id).
     *
     * @param array<int, true> $claimed
     */
    private function syncReferential(Referential $referential, bool $dryRun, SyncReport $report, array &$claimed): ?SkillTermRecord
    {
        $label = "referential {$referential->code} \"{$referential->name}\"";
        $existing = $this->terms->findByCode($referential->code) ?? $this->terms->findBySlug($referential->slug, 0);

        try {
            if ($existing === null) {
                $report->created($label);
                if ($dryRun) {
                    return null;
                }
                $created = $this->terms->insert($referential->name, $referential->slug, $referential->description, 0, $referential->code);
                $claimed[$created->id] = true;

                return $created;
            }

            $claimed[$existing->id] = true;
            $changed = self::changedFields($existing, $referential->name, $referential->slug, $referential->description, 0, $referential->code);
            if ($changed === []) {
                $report->unchanged($label);

                return $existing;
            }

            $report->updated($label, $changed);

            return $dryRun ? $existing : $this->terms->update($existing->id, $referential->name, $referential->slug, $referential->description, 0, $referential->code);
        } catch (RuntimeException $exception) {
            $report->failed($label, $exception->getMessage());

            return $existing;
        }
    }

    /**
     * @param array<int, true> $claimed
     */
    private function syncSkill(Skill $skill, ?SkillTermRecord $parent, bool $dryRun, SyncReport $report, array &$claimed): void
    {
        $label = "skill {$skill->code} \"{$skill->name}\"";
        $existing = $this->terms->findByCode($skill->code);
        $matchedBySlug = false;

        if ($existing === null && $parent !== null) {
            $existing = $this->terms->findBySlug($skill->slug, $parent->id);
            $matchedBySlug = $existing !== null;
        }

        // Parent still to be created (dry run): compare against an id no term can have.
        $parentId = $parent?->id ?? -1;

        try {
            if ($existing === null) {
                $report->created($label);
                if (!$dryRun && $parent !== null) {
                    $created = $this->terms->insert($skill->name, $skill->slug, $skill->description, $parent->id, $skill->code);
                    $claimed[$created->id] = true;
                }

                return;
            }

            $claimed[$existing->id] = true;
            $changed = self::changedFields($existing, $skill->name, $skill->slug, $skill->description, $parentId, $skill->code);
            if ($changed === []) {
                $report->unchanged($label);

                return;
            }

            if ($matchedBySlug && $existing->code !== null && $existing->code !== $skill->code) {
                $report->recoded($label, $existing->code);
            } else {
                $report->updated($label, $changed);
            }

            if (!$dryRun && $parent !== null) {
                $this->terms->update($existing->id, $skill->name, $skill->slug, $skill->description, $parent->id, $skill->code);
            }
        } catch (RuntimeException $exception) {
            $report->failed($label, $exception->getMessage());
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
