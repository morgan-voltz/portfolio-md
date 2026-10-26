<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * Origin of the last write performed on a piece of content.
 *
 * Content can enter the plugin through three doors (admin editor, Git
 * webhook, manual paste). The origin of the last write is persisted in the
 * `_portfolio_source_type` post meta and drives the conflict detection rule
 * described in ADR-005 (a Git sync refuses to overwrite content that was last
 * edited from another source without an explicit force flag).
 *
 * This enum is pure domain code: it must never depend on WordPress.
 */
enum ContentSource: string
{
    case Admin = 'admin';
    case Git = 'git';
    case Paste = 'paste';

    /**
     * Backed values of every case, in declaration order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $source): string => $source->value,
            self::cases(),
        );
    }
}
