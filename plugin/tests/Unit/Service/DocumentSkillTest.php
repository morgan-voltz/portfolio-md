<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\DocumentSkill;

/**
 * How a competency picks the wording a reader gets (lot 4 of ADR-020): the
 * requested language first, then its fallback chain, French last; and
 * which wording may be called official.
 */
#[CoversClass(DocumentSkill::class)]
final class DocumentSkillTest extends TestCase
{
    private const NAMES = ['fr' => 'Exposer le domaine par une API documentée', 'de' => 'Die Domäne über eine dokumentierte API bereitstellen', 'en' => 'Expose the domain through a documented API'];

    #[Test]
    public function serves_the_wording_of_the_language_asked_when_the_term_has_it(): void
    {
        $skill = new DocumentSkill('e07-api-documentee', '4.0.2/E07', self::NAMES);

        self::assertSame(self::NAMES['fr'], $skill->label(Language::Fr));
        self::assertSame(self::NAMES['de'], $skill->label(Language::De));
        self::assertSame(self::NAMES['en'], $skill->label(Language::En));
        self::assertSame(Language::De, $skill->wordingLanguage(Language::De));
    }

    #[Test]
    public function a_missing_translation_falls_back_along_the_chain_of_the_language(): void
    {
        $withoutGerman = new DocumentSkill('e07', '4.0.2/E07', ['fr' => self::NAMES['fr'], 'en' => self::NAMES['en']]);
        $frenchOnly = new DocumentSkill('e07', '4.0.2/E07', ['fr' => self::NAMES['fr']]);

        self::assertSame(self::NAMES['en'], $withoutGerman->label(Language::De), 'a German reader gets English before French');
        self::assertSame(Language::En, $withoutGerman->wordingLanguage(Language::De));
        self::assertSame(self::NAMES['fr'], $frenchOnly->label(Language::De));
        self::assertSame(self::NAMES['fr'], $frenchOnly->label(Language::En));
        self::assertSame(Language::Fr, $frenchOnly->wordingLanguage(Language::En));
    }

    #[Test]
    public function an_english_reader_never_gets_the_german_wording(): void
    {
        $withoutEnglish = new DocumentSkill('e07', '4.0.2/E07', ['fr' => self::NAMES['fr'], 'de' => self::NAMES['de']], official: true);

        self::assertSame(self::NAMES['fr'], $withoutEnglish->label(Language::En), 'the English chain is en, fr: German is not on it');
        self::assertSame(Language::Fr, $withoutEnglish->wordingLanguage(Language::En));
        self::assertTrue($withoutEnglish->isOfficial(Language::En), 'the French wording served keeps its officiality');
        self::assertSame(self::NAMES['de'], $withoutEnglish->label(Language::De));
        self::assertFalse($withoutEnglish->isOfficial(Language::De));
    }

    #[Test]
    public function an_empty_wording_counts_as_missing(): void
    {
        $skill = new DocumentSkill('e07', '4.0.2/E07', ['fr' => self::NAMES['fr'], 'de' => '', 'en' => self::NAMES['en']]);

        self::assertSame(self::NAMES['en'], $skill->label(Language::De));
    }

    #[Test]
    public function only_the_french_wording_can_be_official(): void
    {
        $official = new DocumentSkill('fr-13', '4.0.1/FR-13', self::NAMES, official: true);
        $standIn = new DocumentSkill('e07', '4.0.2/E07', self::NAMES, official: false);
        $officialWithoutGerman = new DocumentSkill('fr-13', '4.0.1/FR-13', ['fr' => self::NAMES['fr']], official: true);

        self::assertTrue($official->isOfficial(Language::Fr));
        self::assertFalse($official->isOfficial(Language::De), 'a translation is never official');
        self::assertFalse($official->isOfficial(Language::En));
        self::assertFalse($standIn->isOfficial(Language::Fr));
        self::assertTrue($officialWithoutGerman->isOfficial(Language::De), 'the French wording served as a fallback keeps its officiality');
    }

    #[Test]
    public function official_defaults_to_false(): void
    {
        self::assertFalse((new DocumentSkill('e07', '4.0.2/E07', self::NAMES))->official);
    }

    #[Test]
    public function refuses_a_skill_without_french_wording(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('4.0.2/E07');

        new DocumentSkill('e07', '4.0.2/E07', ['de' => self::NAMES['de']]);
    }
}
