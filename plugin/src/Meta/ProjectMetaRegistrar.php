<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Meta;

use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;

/**
 * Registers the post metas specific to the `project` post type.
 *
 * Seven descriptive fields (status mirror, role, period, links, client) plus
 * the `is_fil_rouge` flag required by the academic brief (ADR-011): the one
 * project that must receive a distinctive editorial treatment.
 *
 * All fields are public: exposed in the REST API and editable by anyone
 * allowed to edit the project.
 *
 * Hooked on the WordPress `init` action by the composition root (`Plugin`).
 * See docs/architecture/04-structure-wordpress.md section 5.2.
 */
final class ProjectMetaRegistrar
{
    public const PROJECT_STATUS = '_portfolio_project_status';
    public const PROJECT_ROLE = '_portfolio_project_role';
    public const PROJECT_PERIOD_START = '_portfolio_project_period_start';
    public const PROJECT_PERIOD_END = '_portfolio_project_period_end';
    public const PROJECT_REPO_URL = '_portfolio_project_repo_url';
    public const PROJECT_DEMO_URL = '_portfolio_project_demo_url';
    public const PROJECT_CLIENT = '_portfolio_project_client';
    public const IS_FIL_ROUGE = '_portfolio_project_is_fil_rouge';

    /**
     * Registers all project metas on the `project` post type only.
     */
    public function register(): void
    {
        $this->registerMeta(
            self::PROJECT_STATUS,
            'string',
            '',
            'Project status slug, mirror of the project_status taxonomy term (en-cours, stable, archive, prototype).',
            [self::class, 'sanitizeProjectStatus'],
        );

        $this->registerMeta(
            self::PROJECT_ROLE,
            'string',
            '',
            'Role held on the project (lead, contributor, sole developer...).',
            [self::class, 'sanitizeProjectRole'],
        );

        $this->registerMeta(
            self::PROJECT_PERIOD_START,
            'string',
            '',
            'Start date of the project, ISO 8601 (YYYY-MM-DD).',
            [self::class, 'sanitizePeriodStart'],
        );

        $this->registerMeta(
            self::PROJECT_PERIOD_END,
            'string',
            '',
            'End date of the project, ISO 8601 (YYYY-MM-DD); empty while the project is ongoing.',
            [self::class, 'sanitizePeriodEnd'],
        );

        $this->registerMeta(
            self::PROJECT_REPO_URL,
            'string',
            '',
            'URL of the source repository (http or https).',
            [self::class, 'sanitizeRepoUrl'],
        );

        $this->registerMeta(
            self::PROJECT_DEMO_URL,
            'string',
            '',
            'URL of a live demo (http or https).',
            [self::class, 'sanitizeDemoUrl'],
        );

        $this->registerMeta(
            self::PROJECT_CLIENT,
            'string',
            '',
            'Client or organisation the project was built for, if any.',
            [self::class, 'sanitizeProjectClient'],
        );

        $this->registerMeta(
            self::IS_FIL_ROUGE,
            'boolean',
            false,
            'Whether this project is the "fil rouge" of the UHA 4.0 curriculum (distinctive editorial treatment).',
            [self::class, 'sanitizeIsFilRouge'],
        );
    }

    /**
     * @param array{0: class-string, 1: string} $sanitizeCallback
     */
    private function registerMeta(
        string $metaKey,
        string $type,
        string|bool $default,
        string $description,
        array $sanitizeCallback,
    ): void {
        MetaRegistration::registerForPostTypes([PostTypeRegistrar::PROJECT], $metaKey, [
            'type' => $type,
            'single' => true,
            'default' => $default,
            'description' => $description,
            'sanitize_callback' => $sanitizeCallback,
            'auth_callback' => [MetaRegistration::class, 'authorizeEditPost'],
            'show_in_rest' => true,
        ]);
    }

    // region Sanitizers

    /**
     * One of the closed project statuses; the taxonomy term list is the single
     * source of truth, so a new status only needs to be added there.
     */
    public static function sanitizeProjectStatus(mixed $value): string
    {
        return Sanitizer::oneOf($value, array_keys(TaxonomyRegistrar::PROJECT_STATUS_TERMS));
    }

    public static function sanitizeProjectRole(mixed $value): string
    {
        return Sanitizer::text($value);
    }

    public static function sanitizePeriodStart(mixed $value): string
    {
        return Sanitizer::isoDate($value);
    }

    public static function sanitizePeriodEnd(mixed $value): string
    {
        return Sanitizer::isoDate($value);
    }

    public static function sanitizeRepoUrl(mixed $value): string
    {
        return Sanitizer::url($value);
    }

    public static function sanitizeDemoUrl(mixed $value): string
    {
        return Sanitizer::url($value);
    }

    public static function sanitizeProjectClient(mixed $value): string
    {
        return Sanitizer::text($value);
    }

    public static function sanitizeIsFilRouge(mixed $value): bool
    {
        return Sanitizer::boolean($value);
    }

    // endregion
}
