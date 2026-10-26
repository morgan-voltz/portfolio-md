<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Meta;

use Voltz\PortfolioMd\Domain\ContentSource;

/**
 * Registers the metas the plugin keeps for its own bookkeeping.
 *
 * Five fields, present on both post types, written exclusively by the plugin
 * (the Markdown pipeline, later the Git sync): the canonical Markdown source,
 * its hash, the origin of the last write, the last sync time and the version
 * of the renderer that produced the stored HTML.
 *
 * Two differences with the public metas:
 *   - `show_in_rest` is false: they never appear in the REST API;
 *   - `auth_callback` is `__return_false`: no user can write them through
 *     REST or the admin, whatever their role. Plugin code writes them with
 *     `update_post_meta()`, which does not go through capability checks.
 *
 * Hooked on the WordPress `init` action by the composition root (`Plugin`).
 * See docs/architecture/04-structure-wordpress.md section 5.3 and ADR-005.
 */
final class InternalMetaRegistrar
{
    public const MD_SOURCE = '_portfolio_md_source';
    public const MD_HASH = '_portfolio_md_hash';
    public const SOURCE_TYPE = '_portfolio_source_type';
    public const LAST_SYNC_AT = '_portfolio_last_sync_at';
    public const RENDER_VERSION = '_portfolio_render_version';

    /**
     * Registers all internal metas on both post types.
     */
    public function register(): void
    {
        $this->registerMeta(
            self::MD_SOURCE,
            'Raw Markdown source including the YAML frontmatter; canonical content.',
            [self::class, 'sanitizeMdSource'],
        );

        $this->registerMeta(
            self::MD_HASH,
            'SHA-256 hex digest of the Markdown source, for change detection.',
            [self::class, 'sanitizeMdHash'],
        );

        $this->registerMeta(
            self::SOURCE_TYPE,
            'Origin of the last write: admin, git or paste.',
            [self::class, 'sanitizeSourceType'],
        );

        $this->registerMeta(
            self::LAST_SYNC_AT,
            'Instant of the last successful sync, ISO 8601 in UTC.',
            [self::class, 'sanitizeLastSyncAt'],
        );

        $this->registerMeta(
            self::RENDER_VERSION,
            'Version of the renderer that produced the stored Gutenberg HTML (semver).',
            [self::class, 'sanitizeRenderVersion'],
        );
    }

    /**
     * Every internal meta is a string with an empty default.
     *
     * @param array{0: class-string, 1: string} $sanitizeCallback
     */
    private function registerMeta(string $metaKey, string $description, array $sanitizeCallback): void
    {
        MetaRegistration::registerForPostTypes(MetaRegistration::PORTFOLIO_POST_TYPES, $metaKey, [
            'type' => 'string',
            'single' => true,
            'default' => '',
            'description' => $description,
            'sanitize_callback' => $sanitizeCallback,
            'auth_callback' => '__return_false',
            'show_in_rest' => false,
        ]);
    }

    // region Sanitizers

    /**
     * Kept verbatim: stripping tags or normalising whitespace would corrupt
     * the Markdown and invalidate its hash.
     */
    public static function sanitizeMdSource(mixed $value): string
    {
        return Sanitizer::rawString($value);
    }

    public static function sanitizeMdHash(mixed $value): string
    {
        return Sanitizer::sha256Hex($value);
    }

    public static function sanitizeSourceType(mixed $value): string
    {
        return Sanitizer::oneOf($value, ContentSource::values());
    }

    /**
     * Normalised to UTC ("2026-10-26T07:00:00Z") whatever offset was supplied.
     */
    public static function sanitizeLastSyncAt(mixed $value): string
    {
        return Sanitizer::isoDateTimeUtc($value);
    }

    public static function sanitizeRenderVersion(mixed $value): string
    {
        return Sanitizer::semver($value);
    }

    // endregion
}
