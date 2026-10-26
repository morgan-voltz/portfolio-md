<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

use InvalidArgumentException;

/**
 * The skills grid file does not respect the contract documented at the top
 * of content/skills.yaml (structure, codes, slugs, uniqueness).
 */
final class InvalidSkillsGridException extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self("Skills grid: {$reason}.");
    }
}
