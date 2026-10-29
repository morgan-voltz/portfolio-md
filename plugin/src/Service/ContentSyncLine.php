<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentType;

/**
 * The account of one file in a Git synchronization: what was done, to
 * which post, and why when nothing could be done. One line per file of the
 * request, so that the caller's log tells where every file went.
 */
final readonly class ContentSyncLine
{
    public function __construct(
        public string $path,
        public ContentSyncStatus $status,
        public ?ContentType $type = null,
        public ?string $slug = null,
        public ?int $postId = null,
        public string $message = '',
    ) {
    }

    public static function skipped(string $path, string $why, ?ContentType $type = null, ?string $slug = null): self
    {
        return new self($path, ContentSyncStatus::Skipped, $type, $slug, null, $why);
    }

    public static function failed(string $path, string $why, ?ContentType $type = null, ?string $slug = null): self
    {
        return new self($path, ContentSyncStatus::Failed, $type, $slug, null, $why);
    }
}
