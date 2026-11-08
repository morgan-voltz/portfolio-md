<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * Port of the skill synchronizer towards the term store.
 *
 * Implemented by Storage\WordPressSkillTermGateway in production and by an
 * in-memory fake in the unit tests, so that the synchronization logic is
 * tested without WordPress.
 *
 * A write carries everything a term holds: its name (the French wording),
 * slug, description, parent, code, and since lot 4 of ADR-020 the wordings
 * of the other languages and whether the French one is official. A
 * referential passes no translation and is never official; the store
 * keeps no meta for what is empty or false.
 */
interface SkillTermGateway
{
    /**
     * The term whose code meta equals $code, or null.
     */
    public function findByCode(string $code): ?SkillTermRecord;

    /**
     * The term with this slug under this parent (0 for a top-level term), or null.
     */
    public function findBySlug(string $slug, int $parentId): ?SkillTermRecord;

    /**
     * @param array<string, string> $translations language value => wording, for the languages other than French
     *
     * @throws \RuntimeException when the store refuses the term
     */
    public function insert(string $name, string $slug, string $description, int $parentId, ?string $code, array $translations = [], bool $official = false): SkillTermRecord;

    /**
     * @param array<string, string> $translations language value => wording, for the languages other than French
     *
     * @throws \RuntimeException when the store refuses the change
     */
    public function update(int $termId, string $name, string $slug, string $description, int $parentId, ?string $code, array $translations = [], bool $official = false): SkillTermRecord;

    /**
     * Every term of the taxonomy, parents and children.
     *
     * @return list<SkillTermRecord>
     */
    public function all(): array;
}
