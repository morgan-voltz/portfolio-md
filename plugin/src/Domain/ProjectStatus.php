<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * Closed list of project statuses (docs/architecture/04-structure-wordpress.md §4.2).
 *
 * The same four values are the terms of the `project_status` taxonomy
 * (`TaxonomyRegistrar::PROJECT_STATUS_TERMS`); a unit test keeps both lists
 * identical so that the domain never drifts from what WordPress knows.
 */
enum ProjectStatus: string
{
    case EnCours = 'en-cours';
    case Stable = 'stable';
    case Archive = 'archive';
    case Prototype = 'prototype';

    /**
     * Human label shown in the admin and on the site.
     */
    public function label(): string
    {
        return match ($this) {
            self::EnCours => 'En cours',
            self::Stable => 'Stable',
            self::Archive => 'Archivé',
            self::Prototype => 'Prototype',
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
