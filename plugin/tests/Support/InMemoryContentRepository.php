<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Support;

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Service\ContentRecord;
use Voltz\PortfolioMd\Service\ContentRepository;
use Voltz\PortfolioMd\Service\DocumentLister;
use Voltz\PortfolioMd\Service\StoredContent;

/**
 * ContentRepository backed by arrays, for the unit tests of the import
 * service. Keeps every record it was asked to save, so that tests can read
 * back exactly what the store would have received.
 *
 * Mimics the one WordPress rule the service relies on: a post has one slug,
 * so writing it under a new slug frees the old one.
 */
final class InMemoryContentRepository implements ContentRepository, DocumentLister
{
    /** @var array<string, StoredContent> "type/slug" => content */
    private array $bySlug = [];

    /** @var array<int, string> post id => "type/slug" */
    private array $keysById = [];

    /** @var list<ContentRecord> */
    public array $saved = [];

    /** @var list<int> ids handed to trash(), in order */
    public array $trashed = [];

    private int $nextId = 100;

    /**
     * Makes a post "exist" without going through save(): a post made by
     * hand, or one written by an earlier version of the pipeline.
     */
    public function seed(ContentType $type, string $slug, StoredContent $content): void
    {
        $this->store($type, $slug, $content);
    }

    public function findBySlug(ContentType $type, string $slug): ?StoredContent
    {
        return $this->bySlug[self::key($type, $slug)] ?? null;
    }

    public function findById(ContentType $type, int $postId): ?StoredContent
    {
        $key = $this->keysById[$postId] ?? null;
        if ($key === null || !str_starts_with($key, $type->value . '/')) {
            return null;
        }

        return $this->bySlug[$key];
    }

    public function save(ContentRecord $record): int
    {
        $id = $record->id ?? $this->nextId++;
        $this->saved[] = $record;
        $this->store($record->frontmatter->type, $record->frontmatter->slug, new StoredContent(
            $id,
            $record->hash,
            $record->source,
            $record->renderVersion,
            documentSlug: $record->frontmatter->slug,
            lang: $record->frontmatter->lang,
            translationKey: $record->frontmatter->translationKey,
        ));

        return $id;
    }

    public function trash(int $postId): void
    {
        $key = $this->keysById[$postId] ?? throw new \RuntimeException("Cannot move post {$postId} to the trash: there is no such post.");
        $content = $this->bySlug[$key];
        $this->bySlug[$key] = new StoredContent($content->id, $content->hash, $content->source, $content->renderVersion, true, $content->documentSlug, $content->lang, $content->translationKey);
        $this->trashed[] = $postId;
    }

    public function findByKey(ContentType $type, string $translationKey): array
    {
        $found = [];
        foreach ($this->bySlug as $key => $content) {
            if (str_starts_with($key, $type->value . '/') && !$content->inTrash && $content->translationKey === $translationKey) {
                $found[] = $content;
            }
        }
        usort($found, static fn (StoredContent $a, StoredContent $b): int => $a->id <=> $b->id);

        return $found;
    }

    public function listDocuments(ContentType $type): array
    {
        $found = [];
        foreach ($this->bySlug as $key => $content) {
            if (str_starts_with($key, $type->value . '/') && $content->holdsDocument()) {
                $found[] = $content;
            }
        }
        usort($found, static fn (StoredContent $a, StoredContent $b): int => $a->id <=> $b->id);

        return $found;
    }

    public function lastSaved(): ContentRecord
    {
        $last = end($this->saved);
        if ($last === false) {
            throw new \LogicException('Nothing was saved.');
        }

        return $last;
    }

    private function store(ContentType $type, string $slug, StoredContent $content): void
    {
        $previous = $this->keysById[$content->id] ?? null;
        if ($previous !== null) {
            unset($this->bySlug[$previous]);
        }

        $key = self::key($type, $slug);
        $this->bySlug[$key] = $content;
        $this->keysById[$content->id] = $key;
    }

    private static function key(ContentType $type, string $slug): string
    {
        return $type->value . '/' . $slug;
    }
}
