<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use RuntimeException;

/**
 * The Markdown document cannot be read as a document: no frontmatter block,
 * YAML that does not parse, or text that is not UTF-8.
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

    public static function invalidEncoding(): self
    {
        return new self('Document: the text is not valid UTF-8 (often a curly quote or an accented letter pasted from a Windows-1252 export); re-save the file as UTF-8.');
    }
}
