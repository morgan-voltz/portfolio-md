<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * Shapes shared by the frontmatter contract and the skills grid, kept in one
 * place so that the parser and the loader can never disagree on them.
 */
final class Patterns
{
    /** WordPress-safe slug: lowercase letters, digits, single hyphens. */
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** WordPress truncates a term slug beyond this length (varchar(200)), silently. */
    public const SLUG_MAX_LENGTH = 200;

    /** Referential code such as "4.0.1". */
    public const REFERENTIAL_CODE = '/^\d+(?:\.\d+)*$/';

    /** Competency code such as "4.0.1/FR-13" or "4.0.2/E07". */
    public const SKILL_CODE = '/^\d+(?:\.\d+)*\/[A-Z]+-?\d+$/';

    private function __construct()
    {
    }
}
