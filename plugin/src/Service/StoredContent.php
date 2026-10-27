<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentSource;

/**
 * What the import service needs to know about a post that already exists:
 * enough to apply the source_of_truth rule (ADR-005) and to skip unchanged
 * content. A post that never went through the pipeline (made by hand in the
 * admin) has an empty hash, no source and no render version. A post that
 * sits in the trash is reported as such: it still owns the slug.
 */
final readonly class StoredContent
{
    /**
     * @param ?string $documentSlug the slug written in the document the post holds, when the
     *                              store read it (findById); null when the post holds none.
     *                              It may differ from the post's own URL slug if that one was
     *                              changed behind the document's back: the document wins.
     */
    public function __construct(
        public int $id,
        public string $hash,
        public ?ContentSource $source,
        public string $renderVersion,
        public bool $inTrash = false,
        public ?string $documentSlug = null,
    ) {
    }

    /**
     * Whether the post holds a document, that is, went through the pipeline
     * at least once. A post made by hand in the admin, or just created
     * there and about to receive its first document, does not.
     */
    public function holdsDocument(): bool
    {
        return $this->hash !== '';
    }
}
