<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use RuntimeException;
use Voltz\PortfolioMd\Domain\ContentType;

/**
 * A document that is well formed but cannot be imported as it stands.
 */
final class ImportException extends RuntimeException
{
    public static function unsupportedType(ContentType $type): self
    {
        return new self("Documents of type \"{$type->value}\" cannot be imported yet: only articles and projects can.");
    }

    /**
     * @param list<string> $codes
     */
    public static function unknownSkills(array $codes): self
    {
        return new self(
            'Unknown competency code(s): ' . implode(', ', $codes)
            . '. Add them to content/skills.yaml and run `wp portfolio skills sync` first.',
        );
    }
}
