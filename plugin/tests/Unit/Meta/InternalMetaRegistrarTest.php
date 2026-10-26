<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Meta;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(InternalMetaRegistrar::class)]
final class InternalMetaRegistrarTest extends TestCase
{
    private const EXPECTED_KEYS = [
        InternalMetaRegistrar::MD_SOURCE,
        InternalMetaRegistrar::MD_HASH,
        InternalMetaRegistrar::SOURCE_TYPE,
        InternalMetaRegistrar::LAST_SYNC_AT,
        InternalMetaRegistrar::RENDER_VERSION,
    ];

    protected function setUp(): void
    {
        WordPressSpy::reset();
        (new InternalMetaRegistrar())->register();
    }

    #[Test]
    public function registers_the_five_internal_metas_on_articles_and_projects(): void
    {
        self::assertSame(self::EXPECTED_KEYS, WordPressSpy::postMetaKeys('article'));
        self::assertSame(self::EXPECTED_KEYS, WordPressSpy::postMetaKeys('project'));
        self::assertSame([], WordPressSpy::postMetaKeys('post'));
    }

    #[Test]
    #[DataProvider('definitionProvider')]
    public function each_meta_is_hidden_from_rest_and_locked_for_users(string $metaKey, string $sanitizer): void
    {
        foreach (['article', 'project'] as $postType) {
            $args = WordPressSpy::postMeta($postType, $metaKey);

            self::assertNotNull($args, "{$metaKey} on {$postType}");
            self::assertSame('string', $args['type']);
            self::assertTrue($args['single']);
            self::assertSame('', $args['default']);
            self::assertFalse($args['show_in_rest']);
            self::assertSame('__return_false', $args['auth_callback']);
            self::assertSame([InternalMetaRegistrar::class, $sanitizer], $args['sanitize_callback']);
            self::assertIsCallable($args['sanitize_callback']);
            self::assertNotSame('', $args['description']);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function definitionProvider(): iterable
    {
        yield 'markdown source' => [InternalMetaRegistrar::MD_SOURCE, 'sanitizeMdSource'];
        yield 'markdown hash' => [InternalMetaRegistrar::MD_HASH, 'sanitizeMdHash'];
        yield 'source type' => [InternalMetaRegistrar::SOURCE_TYPE, 'sanitizeSourceType'];
        yield 'last sync at' => [InternalMetaRegistrar::LAST_SYNC_AT, 'sanitizeLastSyncAt'];
        yield 'render version' => [InternalMetaRegistrar::RENDER_VERSION, 'sanitizeRenderVersion'];
    }

    #[Test]
    public function markdown_source_is_stored_verbatim(): void
    {
        $markdown = "---\ntitle: \"Rust\"\n---\n\n# Heading\n\n```rust\nfn main() {}\n```\n\n<details>kept</details>\n";

        self::assertSame($markdown, InternalMetaRegistrar::sanitizeMdSource($markdown));
    }

    #[Test]
    public function hash_must_be_a_sha256_digest(): void
    {
        $digest = hash('sha256', 'x');

        self::assertSame($digest, InternalMetaRegistrar::sanitizeMdHash(strtoupper($digest)));
        self::assertSame('', InternalMetaRegistrar::sanitizeMdHash(md5('x')));
    }

    #[Test]
    public function source_type_accepts_exactly_the_content_source_values(): void
    {
        foreach (ContentSource::cases() as $source) {
            self::assertSame($source->value, InternalMetaRegistrar::sanitizeSourceType($source->value));
        }

        self::assertSame('', InternalMetaRegistrar::sanitizeSourceType('webhook'));
    }

    #[Test]
    public function last_sync_at_is_normalised_to_utc(): void
    {
        self::assertSame('2026-10-26T07:00:00Z', InternalMetaRegistrar::sanitizeLastSyncAt('2026-10-26T09:00:00+02:00'));
        self::assertSame('', InternalMetaRegistrar::sanitizeLastSyncAt('yesterday'));
    }

    #[Test]
    public function render_version_is_a_semver_core(): void
    {
        self::assertSame('0.1.0', InternalMetaRegistrar::sanitizeRenderVersion('0.1.0'));
        self::assertSame('', InternalMetaRegistrar::sanitizeRenderVersion('0.1'));
    }
}
