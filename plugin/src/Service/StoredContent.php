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
    public function __construct(
        public int $id,
        public string $hash,
        public ?ContentSource $source,
        public string $renderVersion,
        public bool $inTrash = false,
    ) {
    }
}
