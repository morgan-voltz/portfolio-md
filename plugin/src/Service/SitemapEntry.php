<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use DateTimeImmutable;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;

/**
 * One line of the sitemap (01-plugin-php.md §7.1): a published document
 * named by its kind and slug, with the instant of its last modification, so
 * that a consumer who caches documents knows which ones to fetch again. The
 * slug is unique within a kind only: the pair is the key. Since ADR-021 the
 * line also says the language of the document and the key of its
 * translation group, so that the consumer can drop every cached version of
 * a group when one of its members changes.
 */
final readonly class SitemapEntry
{
    public function __construct(
        public ContentType $type,
        public string $slug,
        public DateTimeImmutable $updated,
        public Language $lang,
        public string $translationKey,
    ) {
    }
}
