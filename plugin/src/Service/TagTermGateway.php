<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * The terms of the tech_tag taxonomy, as the synchronizer of the names
 * file needs them (ADR-024): find one by slug, create one with its names,
 * rename one, list them all. Implemented over WordPress in Storage and in
 * memory for the tests. A failed write is a RuntimeException.
 */
interface TagTermGateway
{
    public function findBySlug(string $slug): ?TagTermRecord;

    /**
     * @param array<string, string> $translations
     */
    public function insert(string $slug, string $name, array $translations = []): TagTermRecord;

    /**
     * @param array<string, string> $translations
     */
    public function update(int $termId, string $name, array $translations = []): TagTermRecord;

    /**
     * @return list<TagTermRecord>
     */
    public function all(): array;
}
