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

    public function seed(string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
    {
        return $this->store($this->nextId++, $name, $slug, $description, $parentId, $code);
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

    public function insert(string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
    {
        foreach ($this->terms as $term) {
            if ($term->slug === $slug) {
                throw new RuntimeException("slug \"{$slug}\" already exists");
            }
        }
        $this->writes[] = "insert {$slug}";

        return $this->store($this->nextId++, $name, $slug, $description, $parentId, $code);
    }

    public function update(int $termId, string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
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

        return $this->store($termId, $name, $slug, $description, $parentId, $code);
    }

    public function all(): array
    {
        return array_values($this->terms);
    }

    private function store(int $id, string $name, string $slug, string $description, int $parentId, ?string $code): SkillTermRecord
    {
        $record = new SkillTermRecord($id, $name, $slug, $description, $parentId, $code);
        $this->terms[$id] = $record;

        return $record;
    }
}
