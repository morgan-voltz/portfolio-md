<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;

/**
 * Result of importing one document. `postId` is null when nothing was
 * written for a document that does not exist yet (dry run, conflict).
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
