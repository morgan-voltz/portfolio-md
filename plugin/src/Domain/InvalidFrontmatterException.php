<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

use InvalidArgumentException;

/**
 * The frontmatter is well-formed YAML but its content breaks the contract
 * of content/README.md (missing key, unknown key, wrong type or value).
 *
 * Messages name the offending key so that the author can fix the file.
 */
final class InvalidFrontmatterException extends InvalidArgumentException
{
    public static function missingKey(string $key): self
    {
        return new self("Frontmatter: missing required key \"{$key}\".");
    }

    /**
     * @param list<string> $keys
     */
    public static function unknownKeys(array $keys): self
    {
        $hint = in_array('category', $keys, true)
            ? ' The "category" key was dropped (ADR-015): use "tags" instead.'
            : '';

        return new self('Frontmatter: unknown key(s) "' . implode('", "', $keys) . '".' . $hint);
    }

    public static function invalidValue(string $key, string $reason): self
    {
        return new self("Frontmatter: invalid value for \"{$key}\": {$reason}.");
    }
}
