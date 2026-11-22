<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Meta;

use Voltz\PortfolioMd\Domain\Frontmatter;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;

/**
 * Registers the post metas shared by articles and projects, and those of the
 * native pages.
 *
 * Five public fields (exposed in the REST API, editable by anyone allowed to
 * edit the post). `reading_time` only makes editorial sense on articles but
 * is registered on both post types: an unused meta on a project costs
 * nothing, while a fourth registrar for a single field would. The SEO
 * description and the subtitle are also registered on the native page type,
 * whose documents (content/<lang>/pages) may carry them, and the headline
 * on that type only (ADR-027): the heading a page shows instead of its title.
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
    public const HEADLINE = '_portfolio_headline';

    /** Same bounds as the frontmatter contract: the domain owns them, this adapter applies them. */
    public const SEO_DESCRIPTION_MAX_LENGTH = Frontmatter::SEO_DESCRIPTION_MAX_LENGTH;
    public const READING_TIME_MAX_MINUTES = Frontmatter::READING_TIME_MAX_MINUTES;

    /**
     * Registers all shared post metas on both post types, the SEO description
     * and the subtitle on pages as well, and the headline on pages only.
     */
    public function register(): void
    {
        $this->registerMeta(
            self::SEO_DESCRIPTION,
            'string',
            '',
            'Custom SEO description for this article, project or page.',
            [self::class, 'sanitizeSeoDescription'],
            MetaRegistration::MARKDOWN_POST_TYPES,
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
            'Short subtitle or summary line of an article, a project or a page.',
            [self::class, 'sanitizeSubtitle'],
            MetaRegistration::MARKDOWN_POST_TYPES,
        );

        $this->registerMeta(
            self::HEADLINE,
            'string',
            '',
            'Heading shown on the page instead of its title, which still names it in menus and search results.',
            [self::class, 'sanitizeHeadline'],
            [PostTypeRegistrar::PAGE],
        );
    }

    /**
     * @param array{0: class-string, 1: string} $sanitizeCallback
     * @param list<string>                      $postTypes
     */
    private function registerMeta(
        string $metaKey,
        string $type,
        string|bool|int $default,
        string $description,
        array $sanitizeCallback,
        array $postTypes = MetaRegistration::PORTFOLIO_POST_TYPES,
    ): void {
        MetaRegistration::registerForPostTypes($postTypes, $metaKey, [
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

    public static function sanitizeHeadline(mixed $value): string
    {
        return Sanitizer::text($value);
    }

    // endregion
}
