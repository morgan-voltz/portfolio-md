<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use Voltz\PortfolioMd\Domain\Frontmatter;

/**
 * Result of FrontmatterParser::parse(): the typed metadata and the Markdown
 * body without its frontmatter block.
 */
final readonly class ParsedDocument
{
    public function __construct(
        public Frontmatter $frontmatter,
        public string $body,
    ) {
    }
}
