<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Parsing;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Voltz\PortfolioMd\Domain\Frontmatter;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;

/**
 * Phase one of the pipeline (docs/architecture/01-plugin-php.md §4.1):
 * splits a Markdown document into its YAML frontmatter and its body, and
 * turns the frontmatter into a typed Frontmatter value object.
 *
 * Pure PHP: no WordPress function is called here, which is what makes the
 * parser unit-testable in milliseconds.
 */
final class FrontmatterParser
{
    /**
     * The frontmatter is the block between the opening "---" line (first
     * line of the file) and the next "---" line; everything after is the body.
     */
    private const PATTERN = '/\A---\n(?<yaml>.*?)\n---(?:\n|\z)(?<body>.*)\z/s';

    /**
     * @throws MalformedDocumentException when the block is missing or not YAML
     * @throws InvalidFrontmatterException when the metadata break the contract
     */
    public function parse(string $markdown): ParsedDocument
    {
        $normalized = self::normalizeLineEndings(self::stripByteOrderMark($markdown));

        if (preg_match(self::PATTERN, $normalized, $matches) !== 1) {
            throw MalformedDocumentException::missingFrontmatter();
        }

        try {
            // PARSE_DATETIME: without it, symfony/yaml turns a bare date such
            // as 2026-10-26 into an integer timestamp.
            $data = Yaml::parse($matches['yaml'], Yaml::PARSE_DATETIME);
        } catch (ParseException $exception) {
            throw MalformedDocumentException::invalidYaml($exception->getMessage());
        }

        if (!is_array($data) || array_is_list($data)) {
            throw MalformedDocumentException::notAMapping();
        }

        return new ParsedDocument(Frontmatter::fromArray($data), $matches['body']);
    }

    private static function stripByteOrderMark(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    private static function normalizeLineEndings(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
