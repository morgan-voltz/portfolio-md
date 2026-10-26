<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Service;

use Voltz\PortfolioMd\Domain\ContentSource;

/**
 * The source_of_truth rule of ADR-005, as a pure decision.
 *
 * Content may be written from three doors. A write coming from Git refuses
 * to overwrite content whose last write came from another door, or from
 * nowhere the plugin knows (a post made by hand), when the content differs,
 * unless it is forced: that edit would otherwise be lost silently. Writes
 * from the admin or from a paste are human acts and always win.
 *
 * Identical content rendered by the current renderer is left alone, which
 * makes imports idempotent; identical content rendered by an older renderer
 * is written again so that the stored HTML catches up.
 */
final class ConflictResolver
{
    public function decide(?StoredContent $existing, string $hash, string $renderVersion, ContentSource $source, bool $force): ImportOutcome
    {
        if ($existing === null) {
            return ImportOutcome::Created;
        }

        if ($existing->hash === $hash && $existing->renderVersion === $renderVersion) {
            return ImportOutcome::Unchanged;
        }

        if ($source === ContentSource::Git && $existing->source !== ContentSource::Git && $existing->hash !== $hash && !$force) {
            return ImportOutcome::Conflict;
        }

        return ImportOutcome::Updated;
    }
}
