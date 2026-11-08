<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use RuntimeException;
use Voltz\PortfolioMd\Service\TagTermGateway;
use Voltz\PortfolioMd\Service\TagTermRecord;

/**
 * The tech_tag terms in memory, for the tests of the synchronizer (ADR-024):
 * the same contract as the WordPress gateway, every write recorded.
 */
final class InMemoryTagTermGateway implements TagTermGateway
{
    /** @var array<int, TagTermRecord> */
    private array $terms = [];

    private int $nextId = 1;

    /** @var list<string> */
    public array $writes = [];

    /** @var list<string> slugs whose write must fail, to test the report */
    public array $failing = [];

    /**
     * @param array<string, string> $translations
     */
    public function seed(string $slug, string $name, array $translations = []): TagTermRecord
    {
        return $this->store($this->nextId++, $slug, $name, $translations);
    }

    public function findBySlug(string $slug): ?TagTermRecord
    {
        foreach ($this->terms as $term) {
            if ($term->slug === $slug) {
                return $term;
            }
        }

        return null;
    }

    public function insert(string $slug, string $name, array $translations = []): TagTermRecord
    {
        if (in_array($slug, $this->failing, true)) {
            throw new RuntimeException("cannot write {$slug}");
        }
        if ($this->findBySlug($slug) !== null) {
            throw new RuntimeException("slug \"{$slug}\" already exists");
        }
        $this->writes[] = "insert {$slug}";

        return $this->store($this->nextId++, $slug, $name, $translations);
    }

    public function update(int $termId, string $name, array $translations = []): TagTermRecord
    {
        if (!isset($this->terms[$termId])) {
            throw new RuntimeException("term {$termId} does not exist");
        }
        if (in_array($this->terms[$termId]->slug, $this->failing, true)) {
            throw new RuntimeException("cannot write {$this->terms[$termId]->slug}");
        }
        $this->writes[] = "update {$termId}";

        return $this->store($termId, $this->terms[$termId]->slug, $name, $translations);
    }

    public function all(): array
    {
        return array_values($this->terms);
    }

    /**
     * @param array<string, string> $translations
     */
    private function store(int $id, string $slug, string $name, array $translations): TagTermRecord
    {
        // Like the store: nothing is kept for an empty wording.
        $translations = array_filter($translations, static fn (string $wording): bool => $wording !== '');
        $this->terms[$id] = new TagTermRecord($id, $slug, $name, $translations);

        return $this->terms[$id];
    }
}
