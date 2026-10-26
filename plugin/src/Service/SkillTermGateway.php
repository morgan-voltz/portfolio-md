<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * Port of the skill synchronizer towards the term store.
 *
 * Implemented by Storage\WordPressSkillTermGateway in production and by an
 * in-memory fake in the unit tests, so that the synchronization logic is
 * tested without WordPress.
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
     * @throws \RuntimeException when the store refuses the term
     */
    public function insert(string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord;

    /**
     * @throws \RuntimeException when the store refuses the change
     */
    public function update(int $termId, string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord;

    /**
     * Every term of the taxonomy, parents and children.
     *
     * @return list<SkillTermRecord>
     */
    public function all(): array;
}
