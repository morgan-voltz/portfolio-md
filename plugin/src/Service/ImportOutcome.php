<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * What an import did with one document, or would do in dry-run mode.
 */
enum ImportOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';

    public function isWrite(): bool
    {
        return $this === self::Created || $this === self::Updated;
    }
}
