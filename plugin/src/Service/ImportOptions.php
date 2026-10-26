<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use InvalidArgumentException;
use Voltz\PortfolioMd\Domain\ContentSource;

/**
 * How an import should behave, as decided by the door it comes through.
 */
final readonly class ImportOptions
{
    /** Statuses a new post may be created with. */
    public const POST_STATUSES = ['publish', 'draft'];

    /**
     * @param ContentSource $source     origin recorded on the content, which drives the conflict rule (ADR-005)
     * @param bool          $force      overwrite content last written from another source
     * @param bool          $dryRun     parse and render, but write nothing
     * @param string        $postStatus status of a post created by this import
     */
    public function __construct(
        public ContentSource $source = ContentSource::Git,
        public bool $force = false,
        public bool $dryRun = false,
        public string $postStatus = 'publish',
    ) {
        if (!in_array($postStatus, self::POST_STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported post status \"{$postStatus}\"; expected one of " . implode(', ', self::POST_STATUSES) . '.');
        }
    }
}
