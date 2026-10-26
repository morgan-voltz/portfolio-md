<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;

/**
 * Result of importing one document. `postId` is the post the document
 * belongs to, or null when no post exists yet and nothing was written (a
 * dry run of a new document).
 */
final readonly class ImportResult
{
    public function __construct(
        public ImportOutcome $outcome,
        public ContentType $type,
        public string $slug,
        public string $title,
        public ?int $postId,
        public string $detail = '',
    ) {
    }
}
