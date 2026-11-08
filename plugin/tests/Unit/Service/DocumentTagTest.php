<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\DocumentTag;

/**
 * How a technology tag picks the name a reader gets (ADR-024): the language
 * asked when the tag has it, the fallback chain otherwise, and a name
 * without translation that belongs to every language.
 */
#[CoversClass(DocumentTag::class)]
final class DocumentTagTest extends TestCase
{
    #[Test]
    public function a_translated_tag_serves_the_language_asked(): void
    {
        $tag = new DocumentTag('architecture-hexagonale', 'Architecture hexagonale', ['de' => 'Hexagonale Architektur', 'en' => 'Hexagonal architecture']);

        self::assertSame('Architecture hexagonale', $tag->label(Language::Fr));
        self::assertSame('Hexagonale Architektur', $tag->label(Language::De));
        self::assertSame('Hexagonal architecture', $tag->label(Language::En));
        self::assertSame(Language::De, $tag->wordingLanguage(Language::De));
    }

    #[Test]
    public function a_missing_translation_falls_back_along_the_chain_and_says_so(): void
    {
        $withoutGerman = new DocumentTag('securite', 'Sécurité', ['en' => 'Security']);

        self::assertSame('Security', $withoutGerman->label(Language::De), 'German falls back on English first');
        self::assertSame(Language::En, $withoutGerman->wordingLanguage(Language::De));
        self::assertSame('Sécurité', $withoutGerman->label(Language::Fr));

        $frenchOnlyButTranslatedElsewhere = new DocumentTag('securite', 'Sécurité', ['de' => 'Sicherheit']);
        self::assertSame('Sécurité', $frenchOnlyButTranslatedElsewhere->label(Language::En));
        self::assertSame(Language::Fr, $frenchOnlyButTranslatedElsewhere->wordingLanguage(Language::En), 'the theme marks the French wording on an English page');
    }

    #[Test]
    public function a_name_without_translation_belongs_to_every_language(): void
    {
        $dotnet = new DocumentTag('dotnet', '.NET');

        foreach (Language::cases() as $language) {
            self::assertSame('.NET', $dotnet->label($language));
            self::assertSame($language, $dotnet->wordingLanguage($language), 'nothing to mark: the name is the same everywhere');
        }
    }
}
