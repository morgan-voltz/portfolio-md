<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * What PreviewService makes of a document: the rendering the site would
 * show, and what the import would refuse.
 */
final readonly class PreviewResult
{
    /**
     * @param string|null           $title    the document's title, null when the frontmatter did not parse
     * @param string|null           $html     the Gutenberg block markup of the body, null when nothing could be rendered
     * @param array<string, string> $summary  frontmatter lines worth a glance, keyed by a fixed name
     *                                        (type, slug, date, status for a project, skills, tags)
     * @param list<string>          $problems what the import would refuse, in the words of its exceptions
     */
    public function __construct(
        public ?string $title,
        public ?string $html,
        public array $summary,
        public array $problems,
    ) {
    }

    /** A document that could not be read at all. */
    public static function refused(string $problem): self
    {
        return new self(null, null, [], [$problem]);
    }

    /** Whether the import would accept the document, as far as a preview can tell. */
    public function importable(): bool
    {
        return $this->problems === [];
    }
}
