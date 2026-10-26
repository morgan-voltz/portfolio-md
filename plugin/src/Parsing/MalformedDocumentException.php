<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use RuntimeException;

/**
 * The Markdown document cannot be split into frontmatter and body: no
 * frontmatter block, or YAML that does not parse.
 *
 * Distinct from Domain\InvalidFrontmatterException, which is about the
 * meaning of a well-formed frontmatter.
 */
final class MalformedDocumentException extends RuntimeException
{
    public static function missingFrontmatter(): self
    {
        return new self('Document: no YAML frontmatter found; the file must start with a "---" line and close it with another "---" line.');
    }

    public static function invalidYaml(string $reason): self
    {
        return new self("Document: the frontmatter is not valid YAML ({$reason}).");
    }

    public static function notAMapping(): self
    {
        return new self('Document: the frontmatter must be a YAML mapping of key: value pairs.');
    }
}
