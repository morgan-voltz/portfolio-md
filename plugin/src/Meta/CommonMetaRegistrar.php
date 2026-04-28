<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Meta;


final class CommonMetaRegistrar
{
    public const SEO_DESCRIPTION = '_portfolio_seo_description';
    public const FEATURED = '_portfolio_featured';
    public const READING_TIME = '_portfolio_reading_time';
    public const SUBTITLE = '_portfolio_subtitle';

    private const SHARED_POST_TYPES = ['article', 'project'];


    /**
     * Registers all post metas shared between articles and projects.
     *
     * Hooked on WordPress 'init' action via Plugin.php.
     */
    public function register(): void
    {
        $this->registerSeoDescription();
        $this->registerFeatured();
        $this->registerReadingTime();
        $this->registerSubtitle();
    }

    /**
     * Registers the SEO description post meta.
     */
    private function registerSeoDescription(): void
    {
        $args = [
            'type' => 'string',
            'single' => true,
            'default' => '',
            'description' => 'Custom SEO description for this article or project.',
            'sanitize_callback' => [self::class, 'sanitizeSeoDescription'],
            'show_in_rest' => true,
        ];
        $this->registerForBothTypes(self::SEO_DESCRIPTION, $args);
    }

    /**
     * Registers the featured flag post meta.
     */
    private function registerFeatured(): void
    {
        $args = [
            'type' => 'boolean',
            'single' => true,
            'default' => false,
            'description' => 'Whether this article is featured on the home page.',
            'sanitize_callback' => [self::class, 'sanitizeFeatured'],
            'show_in_rest' => true,
        ];
        $this->registerForBothTypes(self::FEATURED, $args);
    }

    /**
     * Registers the reading time post meta.
     */
    private function registerReadingTime(): void
    {
        $args = [
          'type' => 'integer',
          'single' => true,
          'default' => 0,
          'description' => 'Estimated reading time in minutes.',
          'sanitize_callback' => [self::class, 'sanitizeReadingTime'],
          'show_in_rest' => true,
        ];
        $this->registerForBothTypes(self::READING_TIME, $args);
    }

    /**
     * Registers the subtitle post meta.
     */
    private function registerSubtitle(): void
    {
        $args = [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'description'       => 'Short subtitle or summary line for articles.',
            'sanitize_callback' => [self::class, 'sanitizeSubtitle'],
            'show_in_rest'      => true,
        ];
        $this->registerForBothTypes(self::SUBTITLE, $args);
    }


    /**
     * Registers a post meta for both 'article' and 'project' post types.
     */
    private function registerForBothTypes(string $metaKey, array $args): void
    {
        foreach (self::SHARED_POST_TYPES as $postType) {
            register_post_meta($postType, $metaKey, $args);
        }
    }


    // region Sanitizers


    /**
     * Sanitizes the SEO description value before storage.
     */
    public  static function sanitizeSeoDescription(mixed $value): string
    {
        $cleaned = sanitize_text_field((string) $value);
        return mb_substr($cleaned, 0, 160);
    }

    /**
     * Sanitizes the featured flag value before storage.
     */
    public static function sanitizeFeatured(mixed $value): bool
    {
        if (is_string($value)) {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        return (bool) $value;
    }

    /**
     * Sanitizes the reading time value before storage.
     */
    public static function sanitizeReadingTime(mixed $value): int
    {
        $result = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 240],
        ]);

        return $result === false ? 0 : $result;
    }

    /**
     * Sanitizes the subtitle value before storage.
     */
    public static function sanitizeSubtitle(mixed $value): string
    {
        return sanitize_text_field((string) $value);
    }
}
