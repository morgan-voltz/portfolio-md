<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Meta;

use Voltz\PortfolioMd\Domain\Frontmatter;

/**
 * Registers the post metas shared by articles and projects.
 *
 * Four public fields (exposed in the REST API, editable by anyone allowed to
 * edit the post). `subtitle` and `reading_time` only make editorial sense on
 * articles but are registered on both post types: an unused meta on a project
 * costs nothing, while a fourth registrar for a single field would.
 *
 * Hooked on the WordPress `init` action by the composition root (`Plugin`).
 * See docs/architecture/04-structure-wordpress.md section 5.1.
 */
final class CommonMetaRegistrar
{
    public const SEO_DESCRIPTION = '_portfolio_seo_description';
    public const FEATURED = '_portfolio_featured';
    public const READING_TIME = '_portfolio_reading_time';
    public const SUBTITLE = '_portfolio_subtitle';

    /** Same bounds as the frontmatter contract: the domain owns them, this adapter applies them. */
    public const SEO_DESCRIPTION_MAX_LENGTH = Frontmatter::SEO_DESCRIPTION_MAX_LENGTH;
    public const READING_TIME_MAX_MINUTES = Frontmatter::READING_TIME_MAX_MINUTES;

    /**
     * Registers all shared post metas on both post types.
     */
    public function register(): void
    {
        $this->registerMeta(
            self::SEO_DESCRIPTION,
            'string',
            '',
            'Custom SEO description for this article or project.',
            [self::class, 'sanitizeSeoDescription'],
        );

        $this->registerMeta(
            self::FEATURED,
            'boolean',
            false,
            'Whether this content is featured on the home page.',
            [self::class, 'sanitizeFeatured'],
        );

        $this->registerMeta(
            self::READING_TIME,
            'integer',
            0,
            'Estimated reading time in minutes.',
            [self::class, 'sanitizeReadingTime'],
        );

        $this->registerMeta(
            self::SUBTITLE,
            'string',
            '',
            'Short subtitle or summary line for articles.',
            [self::class, 'sanitizeSubtitle'],
        );
    }

    /**
     * @param array{0: class-string, 1: string} $sanitizeCallback
     */
    private function registerMeta(
        string $metaKey,
        string $type,
        string|bool|int $default,
        string $description,
        array $sanitizeCallback,
    ): void {
        MetaRegistration::registerForPostTypes(MetaRegistration::PORTFOLIO_POST_TYPES, $metaKey, [
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
     * Plain text truncated to the SEO-friendly length.
     */
    public static function sanitizeSeoDescription(mixed $value): string
    {
        return Sanitizer::text($value, self::SEO_DESCRIPTION_MAX_LENGTH);
    }

    public static function sanitizeFeatured(mixed $value): bool
    {
        return Sanitizer::boolean($value);
    }

    /**
     * Minutes between 0 and READING_TIME_MAX_MINUTES; anything else becomes 0.
     */
    public static function sanitizeReadingTime(mixed $value): int
    {
        return Sanitizer::boundedInt($value, 0, self::READING_TIME_MAX_MINUTES);
    }

    public static function sanitizeSubtitle(mixed $value): string
    {
        return Sanitizer::text($value);
    }

    // endregion
}
