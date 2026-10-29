<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * A tech tag as a reader sees it: the slug it may filter on and the name
 * the site shows (01-plugin-php.md §7.3).
 */
final readonly class DocumentTag
{
    public function __construct(
        public string $slug,
        public string $name,
    ) {
    }
}
