<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Meta;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Meta\CommonMetaRegistrar;
use Voltz\PortfolioMd\Meta\MetaRegistration;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(CommonMetaRegistrar::class)]
final class CommonMetaRegistrarTest extends TestCase
{
    private const EXPECTED_KEYS = [
        CommonMetaRegistrar::SEO_DESCRIPTION,
        CommonMetaRegistrar::FEATURED,
        CommonMetaRegistrar::READING_TIME,
        CommonMetaRegistrar::SUBTITLE,
    ];

    protected function setUp(): void
    {
        WordPressSpy::reset();
        (new CommonMetaRegistrar())->register();
    }

    #[Test]
    public function registers_the_four_shared_metas_on_articles_and_projects_and_three_metas_on_pages(): void
    {
        self::assertSame(self::EXPECTED_KEYS, WordPressSpy::postMetaKeys('article'));
        self::assertSame(self::EXPECTED_KEYS, WordPressSpy::postMetaKeys('project'));
        // A page carries its SEO description, a subtitle and its headline (ADR-027), nothing else.
        self::assertSame([CommonMetaRegistrar::SEO_DESCRIPTION, CommonMetaRegistrar::SUBTITLE, CommonMetaRegistrar::HEADLINE], WordPressSpy::postMetaKeys('page'));
        self::assertSame([], WordPressSpy::postMetaKeys('post'));
        self::assertSame(WordPressSpy::postMeta('article', CommonMetaRegistrar::SEO_DESCRIPTION), WordPressSpy::postMeta('page', CommonMetaRegistrar::SEO_DESCRIPTION));
        self::assertSame(WordPressSpy::postMeta('article', CommonMetaRegistrar::SUBTITLE), WordPressSpy::postMeta('page', CommonMetaRegistrar::SUBTITLE));
    }

    #[Test]
    public function the_headline_is_a_public_single_text_of_pages_only(): void
    {
        $args = WordPressSpy::postMeta('page', CommonMetaRegistrar::HEADLINE);

        self::assertNotNull($args);
        self::assertSame('string', $args['type']);
        self::assertTrue($args['single']);
        self::assertSame('', $args['default']);
        self::assertTrue($args['show_in_rest']);
        self::assertSame([MetaRegistration::class, 'authorizeEditPost'], $args['auth_callback']);
        self::assertSame([CommonMetaRegistrar::class, 'sanitizeHeadline'], $args['sanitize_callback']);
        self::assertNull(WordPressSpy::postMeta('article', CommonMetaRegistrar::HEADLINE));
        self::assertNull(WordPressSpy::postMeta('project', CommonMetaRegistrar::HEADLINE));
    }

    #[Test]
    public function the_headline_is_single_line_plain_text(): void
    {
        self::assertSame('Du câblage au code', CommonMetaRegistrar::sanitizeHeadline("<strong>Du câblage</strong>\nau code"));
    }

    #[Test]
    #[DataProvider('definitionProvider')]
    public function each_meta_is_public_single_and_typed(string $metaKey, string $type, mixed $default, string $sanitizer): void
    {
        foreach (['article', 'project'] as $postType) {
            $args = WordPressSpy::postMeta($postType, $metaKey);

            self::assertNotNull($args, "{$metaKey} on {$postType}");
            self::assertSame($type, $args['type']);
            self::assertTrue($args['single']);
            self::assertSame($default, $args['default']);
            self::assertTrue($args['show_in_rest']);
            self::assertSame([MetaRegistration::class, 'authorizeEditPost'], $args['auth_callback']);
            self::assertSame([CommonMetaRegistrar::class, $sanitizer], $args['sanitize_callback']);
            self::assertIsCallable($args['sanitize_callback']);
            self::assertNotSame('', $args['description']);
        }
    }

    /** @return iterable<string, array{string, string, mixed, string}> */
    public static function definitionProvider(): iterable
    {
        yield 'seo description' => [CommonMetaRegistrar::SEO_DESCRIPTION, 'string', '', 'sanitizeSeoDescription'];
        yield 'featured' => [CommonMetaRegistrar::FEATURED, 'boolean', false, 'sanitizeFeatured'];
        yield 'reading time' => [CommonMetaRegistrar::READING_TIME, 'integer', 0, 'sanitizeReadingTime'];
        yield 'subtitle' => [CommonMetaRegistrar::SUBTITLE, 'string', '', 'sanitizeSubtitle'];
    }

    #[Test]
    public function seo_description_is_truncated_to_160_characters_after_stripping_markup(): void
    {
        $long = '<p>' . str_repeat('a', 200) . '</p>';

        $clean = CommonMetaRegistrar::sanitizeSeoDescription($long);

        self::assertSame(160, mb_strlen($clean));
        self::assertSame(str_repeat('a', 160), $clean);
    }

    #[Test]
    public function reading_time_is_bounded_between_zero_and_240_minutes(): void
    {
        self::assertSame(12, CommonMetaRegistrar::sanitizeReadingTime('12'));
        self::assertSame(240, CommonMetaRegistrar::sanitizeReadingTime(240));
        self::assertSame(0, CommonMetaRegistrar::sanitizeReadingTime(241));
        self::assertSame(0, CommonMetaRegistrar::sanitizeReadingTime(-1));
        self::assertSame(0, CommonMetaRegistrar::sanitizeReadingTime('soon'));
    }

    #[Test]
    public function featured_accepts_textual_booleans(): void
    {
        self::assertTrue(CommonMetaRegistrar::sanitizeFeatured('yes'));
        self::assertTrue(CommonMetaRegistrar::sanitizeFeatured('1'));
        self::assertFalse(CommonMetaRegistrar::sanitizeFeatured('0'));
        self::assertFalse(CommonMetaRegistrar::sanitizeFeatured('nope'));
    }

    #[Test]
    public function subtitle_is_single_line_plain_text(): void
    {
        self::assertSame('A tale of two sites', CommonMetaRegistrar::sanitizeSubtitle("<em>A tale</em>\nof two sites"));
    }
}
