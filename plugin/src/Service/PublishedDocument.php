<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use DateTimeImmutable;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\ProjectStatus;

/**
 * A published document as the catalog reads it back from the store: the
 * verbatim Markdown with the metadata WordPress distributed from its
 * frontmatter (01-plugin-php.md §4.4), plus what only the store knows (post
 * id, permalink, dates). The REST presenter turns it into the JSON of §7.3.
 *
 * Distinct from Domain\Frontmatter, which is what the author wrote and is
 * built from YAML, and from StoredContent, which is what the import needs to
 * apply the conflict rule. This object looks outward.
 *
 * Only the common fields are set for a page; `tags`, `subtitle`,
 * `readingTime` and `featured` are set for articles and projects; the last
 * nine are set for projects. The dates are instants in UTC: the publication
 * date is the frontmatter's calendar day at midnight in the site's timezone,
 * as the import wrote it, and the modification date is WordPress's own.
 */
final readonly class PublishedDocument
{
    /**
     * @param list<DocumentTag>   $tags
     * @param list<DocumentSkill> $skills competencies the project demonstrates, in code order
     */
    public function __construct(
        public int $id,
        public ContentType $type,
        public string $slug,
        public string $title,
        public string $link,
        public DateTimeImmutable $date,
        public DateTimeImmutable $updated,
        public string $markdown,
        public string $seoDescription = '',
        public array $tags = [],
        public string $subtitle = '',
        public int $readingTime = 0,
        public bool $featured = false,
        public ?ProjectStatus $status = null,
        public array $skills = [],
        public bool $isFilRouge = false,
        public string $role = '',
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
        public string $repoUrl = '',
        public string $demoUrl = '',
        public string $client = '',
    ) {
    }
}
