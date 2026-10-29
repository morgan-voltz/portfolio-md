<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

/**
 * What a Git synchronization did with one file (01-plugin-php.md §6.2):
 * the four outcomes of an import, plus a file whose post was retired, a
 * file that could not be processed and a file that is not a document.
 *
 * A conflict and a failure both need a human: the response that carries
 * them does not answer 200, so that an unattended caller notices.
 */
enum ContentSyncStatus: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';
    case Failed = 'failed';
    case Deleted = 'deleted';
    case Skipped = 'skipped';

    public static function fromOutcome(ImportOutcome $outcome): self
    {
        return match ($outcome) {
            ImportOutcome::Created => self::Created,
            ImportOutcome::Updated => self::Updated,
            ImportOutcome::Unchanged => self::Unchanged,
            ImportOutcome::Conflict => self::Conflict,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
