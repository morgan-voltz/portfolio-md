<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * The whole competency grid: every referential with its skills, indexed by code.
 */
final readonly class SkillsGrid
{
    /** @var array<string, Skill> */
    private array $skillsByCode;

    /** @var array<string, Referential> */
    private array $referentialBySkillCode;

    /**
     * @param list<Referential> $referentials
     */
    public function __construct(public array $referentials)
    {
        $skills = [];
        $owners = [];
        foreach ($referentials as $referential) {
            foreach ($referential->skills as $skill) {
                $skills[$skill->code] = $skill;
                $owners[$skill->code] = $referential;
            }
        }
        $this->skillsByCode = $skills;
        $this->referentialBySkillCode = $owners;
    }

    /**
     * @return list<Skill>
     */
    public function allSkills(): array
    {
        return array_values($this->skillsByCode);
    }

    public function findSkill(string $code): ?Skill
    {
        return $this->skillsByCode[$code] ?? null;
    }

    public function referentialOf(string $skillCode): ?Referential
    {
        return $this->referentialBySkillCode[$skillCode] ?? null;
    }

    public function countSkills(): int
    {
        return count($this->skillsByCode);
    }
}
