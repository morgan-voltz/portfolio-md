<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Service\ContentRecord;
use Voltz\PortfolioMd\Service\ContentRepository;
use Voltz\PortfolioMd\Service\StoredContent;

/**
 * ContentRepository backed by an array, for the unit tests of the import
 * service. Keeps every record it was asked to save, so that tests can read
 * back exactly what the store would have received.
 */
final class InMemoryContentRepository implements ContentRepository
{
    /** @var array<string, StoredContent> */
    private array $stored = [];

    /** @var list<ContentRecord> */
    public array $saved = [];

    private int $nextId = 100;

    /**
     * Makes a post "exist" without going through save(): a post made by
     * hand, or one written by an earlier version of the pipeline.
     */
    public function seed(ContentType $type, string $slug, StoredContent $content): void
    {
        $this->stored[self::key($type, $slug)] = $content;
    }

    public function findBySlug(ContentType $type, string $slug): ?StoredContent
    {
        return $this->stored[self::key($type, $slug)] ?? null;
    }

    public function save(ContentRecord $record): int
    {
        $id = $record->id ?? $this->nextId++;
        $this->saved[] = $record;
        $this->stored[self::key($record->frontmatter->type, $record->frontmatter->slug)] = new StoredContent(
            $id,
            $record->hash,
            $record->source,
            $record->renderVersion,
        );

        return $id;
    }

    public function lastSaved(): ContentRecord
    {
        $last = end($this->saved);
        if ($last === false) {
            throw new \LogicException('Nothing was saved.');
        }

        return $last;
    }

    private static function key(ContentType $type, string $slug): string
    {
        return $type->value . '/' . $slug;
    }
}
