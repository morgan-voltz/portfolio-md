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
     * The label of each status in each language of the site (lot 4 of
     * ADR-020): interface vocabulary, so a fixed table in the code rather
     * than a translation stored in a term, which a synchronization could
     * leave behind. The French column is what the admin and the taxonomy
     * terms show.
     */
    private const LABELS = [
        'en-cours' => ['fr' => 'En cours', 'de' => 'In Arbeit', 'en' => 'In progress'],
        'stable' => ['fr' => 'Stable', 'de' => 'Stabil', 'en' => 'Stable'],
        'archive' => ['fr' => 'Archivé', 'de' => 'Archiviert', 'en' => 'Archived'],
        'prototype' => ['fr' => 'Prototype', 'de' => 'Prototyp', 'en' => 'Prototype'],
    ];

    /**
     * Human label shown in the admin and on the site; French unless a
     * language is given, which the REST presenter does with the language
     * of the reader.
     */
    public function label(Language $language = Language::Fr): string
    {
        return self::LABELS[$this->value][$language->value];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
