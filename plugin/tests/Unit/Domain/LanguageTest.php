<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;

#[CoversClass(Language::class)]
final class LanguageTest extends TestCase
{
    #[Test]
    public function values_lists_the_three_languages_in_declaration_order(): void
    {
        self::assertSame(['fr', 'de', 'en'], Language::values());
    }

    #[Test]
    public function french_is_the_default_language(): void
    {
        self::assertSame(Language::Fr, Language::default());
        self::assertTrue(Language::Fr->isDefault());
        self::assertFalse(Language::De->isDefault());
        self::assertFalse(Language::En->isDefault());
    }

    #[Test]
    public function each_language_has_a_slug_suffix_except_french(): void
    {
        self::assertSame('', Language::Fr->slugSuffix());
        self::assertSame('-de', Language::De->slugSuffix());
        self::assertSame('-en', Language::En->slugSuffix());
    }

    #[Test]
    public function an_unknown_language_is_refused(): void
    {
        self::assertNull(Language::tryFrom('es'));
        self::assertSame(Language::De, Language::from('de'));
    }

    #[Test]
    #[DataProvider('slugProvider')]
    public function a_slug_belongs_to_a_language_by_its_suffix(Language $language, string $slug, bool $accepted): void
    {
        self::assertSame($accepted, $language->acceptsSlug($slug));
    }

    /** @return iterable<string, array{Language, string, bool}> */
    public static function slugProvider(): iterable
    {
        yield 'fr, bare slug' => [Language::Fr, 'atlas', true];
        yield 'fr, hyphenated slug that is not a suffix' => [Language::Fr, 'carbon-fr', true];
        yield 'fr, german suffix' => [Language::Fr, 'atlas-de', false];
        yield 'fr, english suffix' => [Language::Fr, 'atlas-en', false];
        yield 'de, german suffix' => [Language::De, 'atlas-de', true];
        yield 'de, bare slug' => [Language::De, 'atlas', false];
        yield 'de, english suffix' => [Language::De, 'atlas-en', false];
        yield 'en, english suffix' => [Language::En, 'atlas-en', true];
        yield 'en, german suffix' => [Language::En, 'atlas-de', false];
        yield 'fr, ends with the letters de without a hyphen' => [Language::Fr, 'code', true];
        yield 'fr, ends with the letters en without a hyphen' => [Language::Fr, 'pen', true];
        yield 'fr, suffix in the middle' => [Language::Fr, 'atlas-de-notes', true];
        yield 'fr, the bare word de' => [Language::Fr, 'de', true];
        yield 'de, the bare word de has no hyphen' => [Language::De, 'de', false];
        yield 'en, the bare word en has no hyphen' => [Language::En, 'en', false];
        yield 'en, stacked suffixes end with -en' => [Language::En, 'atlas-de-en', true];
        yield 'fr, stacked suffixes' => [Language::Fr, 'atlas-de-en', false];
        yield 'de, stacked suffixes end with -en' => [Language::De, 'atlas-de-en', false];
    }
}
