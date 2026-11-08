<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use RuntimeException;
use Voltz\PortfolioMd\Service\SkillTermGateway;
use Voltz\PortfolioMd\Service\SkillTermRecord;

/**
 * SkillTermGateway backed by an array, for the unit tests of the synchronizer.
 *
 * Mimics the two WordPress rules the synchronizer relies on: slugs are unique
 * within the taxonomy, and ids are never reused.
 */
final class InMemorySkillTermGateway implements SkillTermGateway
{
    /** @var array<int, SkillTermRecord> */
    private array $terms = [];

    private int $nextId = 1;

    /** @var list<string> */
    public array $writes = [];

    /**
     * @param array<string, string> $translations
     */
    public function seed(string $name, string $slug, string $description, int $parentId, ?string $code, array $translations = [], bool $official = false): SkillTermRecord
    {
        return $this->store($this->nextId++, $name, $slug, $description, $parentId, $code, $translations, $official);
    }

    public function findByCode(string $code): ?SkillTermRecord
    {
        foreach ($this->terms as $term) {
            if ($term->code === $code) {
                return $term;
            }
        }

        return null;
    }

    public function findBySlug(string $slug, int $parentId): ?SkillTermRecord
    {
        foreach ($this->terms as $term) {
            if ($term->slug === $slug && $term->parentId === $parentId) {
                return $term;
            }
        }

        return null;
    }

    public function insert(string $name, string $slug, string $description, int $parentId, ?string $code, array $translations = [], bool $official = false): SkillTermRecord
    {
        foreach ($this->terms as $term) {
            if ($term->slug === $slug) {
                throw new RuntimeException("slug \"{$slug}\" already exists");
            }
        }
        $this->writes[] = "insert {$slug}";

        return $this->store($this->nextId++, $name, $slug, $description, $parentId, $code, $translations, $official);
    }

    public function update(int $termId, string $name, string $slug, string $description, int $parentId, ?string $code, array $translations = [], bool $official = false): SkillTermRecord
    {
        if (!isset($this->terms[$termId])) {
            throw new RuntimeException("term {$termId} does not exist");
        }
        foreach ($this->terms as $term) {
            if ($term->slug === $slug && $term->id !== $termId) {
                throw new RuntimeException("slug \"{$slug}\" already exists");
            }
        }
        $this->writes[] = "update {$termId}";

        return $this->store($termId, $name, $slug, $description, $parentId, $code, $translations, $official);
    }

    public function all(): array
    {
        return array_values($this->terms);
    }

    /**
     * @param array<string, string> $translations
     */
    private function store(int $id, string $name, string $slug, string $description, int $parentId, ?string $code, array $translations, bool $official): SkillTermRecord
    {
        // Like the store: nothing is kept for an empty wording.
        $record = new SkillTermRecord($id, $name, $slug, $description, $parentId, $code, array_filter($translations, static fn (string $wording): bool => $wording !== ''), $official);
        $this->terms[$id] = $record;

        return $record;
    }
}
