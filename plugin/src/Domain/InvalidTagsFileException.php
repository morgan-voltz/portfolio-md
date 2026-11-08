<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

use InvalidArgumentException;

/**
 * The names file of the technology tags (content/tags.yaml, ADR-024) is not
 * what the plugin expects: every reason is said in one sentence.
 */
final class InvalidTagsFileException extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self("Tags file: {$reason}.");
    }
}
