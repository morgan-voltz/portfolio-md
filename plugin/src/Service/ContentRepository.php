<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;

/**
 * Port of the import service towards the post store.
 *
 * Implemented by Storage\WordPressContentRepository in production and by an
 * in-memory fake in the unit tests, so that the import logic (conflict rule,
 * idempotence, skill resolution) is tested without WordPress.
 */
interface ContentRepository
{
    /**
     * What is already stored under this slug for this content type, or null.
     *
     * A post in the trash is returned too, flagged as such: WordPress frees
     * the slug of a trashed post, and the service must not create a second
     * post next to it.
     */
    public function findBySlug(ContentType $type, string $slug): ?StoredContent;

    /**
     * What this post stores, or null when no post of this content type has
     * this id. A post in the trash is returned too, flagged as such.
     */
    public function findById(ContentType $type, int $postId): ?StoredContent;

    /**
     * Creates the post when $record->id is null, updates it otherwise, and
     * returns the post id.
     *
     * @throws \RuntimeException when the store refuses the write
     */
    public function save(ContentRecord $record): int;
}
