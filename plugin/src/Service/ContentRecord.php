<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use DateTimeImmutable;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\Frontmatter;

/**
 * Everything the store needs to write one piece of content: the typed
 * metadata, the rendered Gutenberg HTML, the verbatim Markdown with its
 * hash, and the bookkeeping of ADR-005. The store maps it onto wp_posts,
 * the taxonomies and the metas (01-plugin-php.md §4.4 and §4.5).
 */
final readonly class ContentRecord
{
    /**
     * @param ?int      $id           existing post to update, null to create
     * @param list<int> $skillTermIds ids of the `skill` terms resolved from the frontmatter codes
     * @param string    $postStatus   status given to a new post; an existing post keeps its own
     */
    public function __construct(
        public ?int $id,
        public Frontmatter $frontmatter,
        public string $html,
        public string $markdown,
        public string $hash,
        public ContentSource $source,
        public DateTimeImmutable $syncedAt,
        public string $renderVersion,
        public array $skillTermIds,
        public string $postStatus,
    ) {
    }
}
