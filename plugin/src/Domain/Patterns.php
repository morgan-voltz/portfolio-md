<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Domain;

/**
 * Shapes shared by the frontmatter contract and the skills grid, kept in one
 * place so that the parser and the loader can never disagree on them.
 */
final class Patterns
{
    /** The body of a WordPress-safe slug: lowercase letters, digits, single hyphens. For route regexes. */
    public const SLUG_BODY = '[a-z0-9]+(?:-[a-z0-9]+)*';

    /** WordPress-safe slug: lowercase letters, digits, single hyphens. `D`: a trailing newline is not a slug either. */
    public const SLUG = '/^' . self::SLUG_BODY . '$/D';

    /** WordPress truncates a term slug beyond this length (varchar(200)), silently. */
    public const SLUG_MAX_LENGTH = 200;

    /** A term name lives in a varchar(200) too: the wording of a competency, in any language, stays within it. */
    public const TERM_NAME_MAX_LENGTH = 200;

    /** Referential code such as "4.0.1". */
    public const REFERENTIAL_CODE = '/^\d+(?:\.\d+)*$/';

    /** Competency code such as "4.0.1/FR-13" or "4.0.2/E07". */
    public const SKILL_CODE = '/^\d+(?:\.\d+)*\/[A-Z]+-?\d+$/';

    private function __construct()
    {
    }
}
