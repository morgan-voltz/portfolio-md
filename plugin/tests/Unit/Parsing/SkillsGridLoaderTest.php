<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\InvalidSkillsGridException;
use Voltz\PortfolioMd\Domain\Referential;
use Voltz\PortfolioMd\Domain\Skill;
use Voltz\PortfolioMd\Domain\SkillsGrid;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;

#[CoversClass(SkillsGridLoader::class)]
#[CoversClass(SkillsGrid::class)]
#[CoversClass(Referential::class)]
#[CoversClass(Skill::class)]
#[CoversClass(InvalidSkillsGridException::class)]
final class SkillsGridLoaderTest extends TestCase
{
    private const GRID_FILE = __DIR__ . '/../../../../content/skills.yaml';

    private SkillsGridLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new SkillsGridLoader();
    }

    /**
     * @return array<string, mixed>
     */
    private static function grid(array $overrides = []): array
    {
        $grid = ['referentials' => [[
            'code' => '4.0.1',
            'slug' => '4-0-1',
            'name' => 'Compétences 4.0.1',
            'official' => true,
            'description' => 'Première année.',
            'skills' => [[
                'code' => '4.0.1/FR-01',
                'slug' => 'fr-01-outils',
                'name' => 'Utiliser des outils collaboratifs',
                'i18n' => ['de' => ['name' => 'Kollaborative Werkzeuge nutzen'], 'en' => ['name' => 'Use collaborative tools']],
                'description' => 'Étape Découverte.',
            ]],
        ]]];

        return array_replace_recursive($grid, $overrides);
    }

    #[Test]
    public function loads_the_real_grid_of_the_content_folder(): void
    {
        $grid = $this->loader->load(self::GRID_FILE);

        self::assertCount(2, $grid->referentials);
        self::assertSame(['4-0-1', '4-0-2'], array_map(static fn (Referential $r): string => $r->slug, $grid->referentials));
        self::assertGreaterThan(40, $grid->countSkills());
        self::assertSame('4.0.1/FR-13', $grid->findSkill('4.0.1/FR-13')?->code);
        self::assertSame('4.0.2', $grid->referentialOf('4.0.2/E07')?->code);
        self::assertNull($grid->findSkill('4.0.9/X-99'));
    }

    #[Test]
    public function the_real_grid_words_every_skill_in_german_and_english(): void
    {
        $grid = $this->loader->load(self::GRID_FILE);

        foreach ($grid->allSkills() as $skill) {
            self::assertSame(['de', 'en'], array_keys($skill->translations), $skill->code);
            self::assertNotSame($skill->name, $skill->translations['de'], "{$skill->code}: the German wording is the French one");
            self::assertNotSame($skill->name, $skill->translations['en'], "{$skill->code}: the English wording is the French one");
        }
    }

    #[Test]
    public function the_grid_parents_are_the_referentials_seeded_at_activation(): void
    {
        $grid = $this->loader->load(self::GRID_FILE);

        foreach ($grid->referentials as $referential) {
            self::assertArrayHasKey($referential->slug, TaxonomyRegistrar::SKILL_REFERENTIALS);
            self::assertSame(TaxonomyRegistrar::SKILL_REFERENTIALS[$referential->slug], $referential->name);
        }
    }

    #[Test]
    public function builds_the_grid_from_yaml_text_as_a_push_hands_it(): void
    {
        $grid = $this->loader->fromYaml((string) file_get_contents(self::GRID_FILE));

        self::assertSame(['4-0-1', '4-0-2'], array_map(static fn (Referential $r): string => $r->slug, $grid->referentials));
    }

    #[Test]
    public function refuses_yaml_text_that_does_not_parse(): void
    {
        $this->expectException(InvalidSkillsGridException::class);
        $this->expectExceptionMessage('invalid YAML');

        $this->loader->fromYaml("- not: [a mapping\n");
    }

    #[Test]
    public function refuses_yaml_text_that_is_not_a_mapping(): void
    {
        $this->expectException(InvalidSkillsGridException::class);
        $this->expectExceptionMessage('must be a YAML mapping');

        $this->loader->fromYaml("just a string\n");
    }

    #[Test]
    public function builds_typed_objects_from_an_array(): void
    {
        $grid = $this->loader->fromArray(self::grid());

        self::assertSame('4.0.1', $grid->referentials[0]->code);
        self::assertTrue($grid->referentials[0]->official);
        self::assertSame('fr-01-outils', $grid->referentials[0]->skills[0]->slug);
        self::assertSame('Utiliser des outils collaboratifs', $grid->referentials[0]->skills[0]->name);
        self::assertSame(['de' => 'Kollaborative Werkzeuge nutzen', 'en' => 'Use collaborative tools'], $grid->referentials[0]->skills[0]->translations);
        self::assertSame(1, $grid->countSkills());
    }

    #[Test]
    public function the_wordings_come_in_the_order_of_the_languages_of_the_site_whatever_the_file_order(): void
    {
        // Assigned whole, not merged: array_replace_recursive() would keep the de, en order of the fixture.
        $raw = self::grid();
        $raw['referentials'][0]['skills'][0]['i18n'] = ['en' => ['name' => 'Use collaborative tools'], 'de' => ['name' => 'Kollaborative Werkzeuge nutzen']];
        self::assertSame(['en', 'de'], array_keys($raw['referentials'][0]['skills'][0]['i18n']), 'the file really lists English first');

        $grid = $this->loader->fromArray($raw);

        self::assertSame(['de', 'en'], array_keys($grid->referentials[0]->skills[0]->translations));

        $fromYaml = $this->loader->fromYaml("referentials:\n  - code: \"4.0.1\"\n    slug: 4-0-1\n    name: \"Compétences 4.0.1\"\n    official: true\n    description: \"Première année.\"\n    skills:\n      - code: \"4.0.1/FR-01\"\n        slug: fr-01-outils\n        name: \"Utiliser des outils collaboratifs\"\n        i18n:\n          en: { name: \"Use collaborative tools\" }\n          de: { name: \"Kollaborative Werkzeuge nutzen\" }\n        description: \"Étape Découverte.\"\n");

        self::assertSame(['de', 'en'], array_keys($fromYaml->referentials[0]->skills[0]->translations));
    }

    #[Test]
    public function a_stand_in_referential_is_not_official(): void
    {
        $grid = $this->loader->fromArray(self::grid(['referentials' => [0 => ['official' => false]]]));

        self::assertFalse($grid->referentials[0]->official);
    }

    #[Test]
    #[DataProvider('rejectedProvider')]
    public function rejects_a_broken_grid(array $overrides, string $expectedMessagePart): void
    {
        $this->expectException(InvalidSkillsGridException::class);
        $this->expectExceptionMessage($expectedMessagePart);

        $this->loader->fromArray(self::grid($overrides));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function rejectedProvider(): iterable
    {
        yield 'referential code shape' => [['referentials' => [0 => ['code' => 'un']]], 'must look like 4.0.1'];
        yield 'referential slug shape' => [['referentials' => [0 => ['slug' => '4.0.1']]], 'is not a slug'];
        yield 'skill code shape' => [['referentials' => [0 => ['skills' => [0 => ['code' => 'FR-01']]]]], 'must look like 4.0.1/FR-01'];
        yield 'skill outside its referential' => [['referentials' => [0 => ['skills' => [0 => ['code' => '4.0.2/FR-01']]]]], 'does not belong to referential 4.0.1'];
        yield 'skill slug shape' => [['referentials' => [0 => ['skills' => [0 => ['slug' => 'FR 01']]]]], 'is not a slug'];
        yield 'skill slug too long for WordPress' => [['referentials' => [0 => ['skills' => [0 => ['slug' => str_repeat('a', 201)]]]]], 'at most 200'];
        yield 'missing name' => [['referentials' => [0 => ['skills' => [0 => ['name' => '']]]]], '"name" must be a non-empty string'];
        yield 'missing official flag' => [['referentials' => [0 => ['official' => null]]], 'referential 4.0.1: "official" must be true or false'];
        yield 'official flag as a string' => [['referentials' => [0 => ['official' => 'yes']]], '"official" must be true or false'];
        yield 'unknown referential key' => [['referentials' => [0 => ['offical' => true]]], 'referential 4.0.1: unknown key(s) "offical" (allowed: code, slug, name, description, official, skills)'];
        yield 'unknown skill key' => [['referentials' => [0 => ['skills' => [0 => ['nmae' => 'x']]]]], 'skill 4.0.1/FR-01: unknown key(s) "nmae" (allowed: code, slug, name, description, i18n)'];
        yield 'missing i18n block' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => null]]]]], 'skill 4.0.1/FR-01: "i18n" must be a mapping with the languages de, en'];
        yield 'i18n lacking a language' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['en' => null]]]]]], 'skill 4.0.1/FR-01: "i18n" lacks the language "en"'];
        yield 'i18n with a language the site does not have' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['es' => ['name' => 'Usar herramientas']]]]]]], 'skill 4.0.1/FR-01, i18n: unknown key(s) "es" (allowed: de, en)'];
        yield 'i18n with French, which is the name itself' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['fr' => ['name' => 'Utiliser des outils']]]]]]], 'unknown key(s) "fr"'];
        yield 'translated wording declared official' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['de' => ['official' => true]]]]]]], 'skill 4.0.1/FR-01: a translated wording is never official (ADR-020), remove "official" from i18n.de'];
        yield 'translated wording declared unofficial, still a key too many' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['en' => ['official' => false]]]]]]], 'a translated wording is never official'];
        yield 'unknown key in a wording' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['de' => ['description' => 'x']]]]]]], 'skill 4.0.1/FR-01, i18n.de: unknown key(s) "description" (allowed: name)'];
        yield 'empty translated wording' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['en' => ['name' => ' ']]]]]]], 'skill 4.0.1/FR-01, i18n.en: "name" must be a non-empty string'];
        yield 'wording as a bare string instead of a mapping' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['de' => 'Kollaborative Werkzeuge nutzen']]]]]], '"i18n" lacks the language "de"'];
        yield 'French wording too long for a term name' => [['referentials' => [0 => ['skills' => [0 => ['name' => str_repeat('é', 201)]]]]], 'skill 4.0.1/FR-01: "name" exceeds 200 characters'];
        yield 'translated wording too long for a term name' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['de' => ['name' => str_repeat('ä', 201)]]]]]]], 'skill 4.0.1/FR-01, i18n.de: "name" exceeds 200 characters'];
        yield 'referential name too long for a term name' => [['referentials' => [0 => ['name' => str_repeat('x', 201)]]], 'referential 4.0.1: "name" exceeds 200 characters'];
        yield 'wording with markup, which WordPress would strip' => [['referentials' => [0 => ['skills' => [0 => ['name' => 'Utiliser des <em>outils</em>']]]]], 'skill 4.0.1/FR-01: "name" must be plain text on one line'];
        yield 'wording with a lone angle bracket, which WordPress would escape' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['en' => ['name' => 'x < y']]]]]]], 'skill 4.0.1/FR-01, i18n.en: "name" must be plain text on one line'];
        yield 'wording on two lines, which WordPress would join' => [['referentials' => [0 => ['skills' => [0 => ['i18n' => ['de' => ['name' => "Kollaborative\nWerkzeuge nutzen"]]]]]]], 'skill 4.0.1/FR-01, i18n.de: "name" must be plain text on one line'];
        yield 'wording with a double space, which WordPress would collapse' => [['referentials' => [0 => ['skills' => [0 => ['name' => 'Utiliser des  outils']]]]], 'must be plain text on one line'];
        yield 'wording with an encoded octet, which WordPress would remove' => [['referentials' => [0 => ['name' => 'Compétences%20 4.0.1']]], 'referential 4.0.1: "name" must be plain text on one line'];
        yield 'wording with an ampersand, which kses would encode in a term name' => [['referentials' => [0 => ['skills' => [0 => ['name' => 'HTML & CSS']]]]], 'must be plain text on one line'];
    }

    #[Test]
    public function a_percent_sign_that_is_not_an_octet_and_accented_text_are_ordinary_characters(): void
    {
        $grid = $this->loader->fromArray(self::grid(['referentials' => [0 => ['skills' => [0 => [
            'name' => 'Atteindre 100 % de couverture : tests, revue, déploiement',
            'i18n' => ['en' => ['name' => 'Reach 100 % coverage'], 'de' => ['name' => 'Ziel: Überprüfung der Qualität (Tests, Code-Review)']],
        ]]]]]));

        self::assertSame('Reach 100 % coverage', $grid->referentials[0]->skills[0]->translations['en']);
        self::assertSame('Ziel: Überprüfung der Qualität (Tests, Code-Review)', $grid->referentials[0]->skills[0]->translations['de']);
    }

    #[Test]
    public function a_wording_of_exactly_the_length_of_a_term_name_passes(): void
    {
        $grid = $this->loader->fromArray(self::grid(['referentials' => [0 => ['skills' => [0 => ['i18n' => ['de' => ['name' => str_repeat('ä', 200)]]]]]]]));

        self::assertSame(200, mb_strlen($grid->referentials[0]->skills[0]->translations['de']));
    }

    #[Test]
    public function rejects_duplicate_codes_across_the_grid(): void
    {
        $grid = self::grid();
        $grid['referentials'][0]['skills'][] = ['code' => '4.0.1/FR-01', 'slug' => 'other', 'name' => 'n', 'description' => 'd', 'i18n' => ['de' => ['name' => 'n'], 'en' => ['name' => 'n']]];

        $this->expectException(InvalidSkillsGridException::class);
        $this->expectExceptionMessage('used twice');

        $this->loader->fromArray($grid);
    }

    #[Test]
    public function rejects_a_slug_reused_across_referentials(): void
    {
        $grid = self::grid();
        $grid['referentials'][] = [
            'code' => '4.0.2', 'slug' => '4-0-2', 'name' => 'Compétences 4.0.2', 'description' => 'Deuxième année.', 'official' => false,
            'skills' => [['code' => '4.0.2/E01', 'slug' => 'fr-01-outils', 'name' => 'n', 'description' => 'd', 'i18n' => ['de' => ['name' => 'n'], 'en' => ['name' => 'n']]]],
        ];

        $this->expectException(InvalidSkillsGridException::class);
        $this->expectExceptionMessage('unique across the whole taxonomy');

        $this->loader->fromArray($grid);
    }

    #[Test]
    public function rejects_a_skill_slug_equal_to_a_referential_slug(): void
    {
        $grid = self::grid(['referentials' => [0 => ['skills' => [0 => ['slug' => '4-0-1']]]]]);

        $this->expectException(InvalidSkillsGridException::class);
        $this->expectExceptionMessage('used twice');

        $this->loader->fromArray($grid);
    }

    #[Test]
    public function rejects_an_empty_referential_list(): void
    {
        $this->expectException(InvalidSkillsGridException::class);
        $this->expectExceptionMessage('non-empty list');

        $this->loader->fromArray(['referentials' => []]);
    }

    #[Test]
    public function rejects_an_unreadable_file(): void
    {
        $this->expectException(InvalidSkillsGridException::class);
        $this->expectExceptionMessage('not readable');

        $this->loader->load('/nonexistent/skills.yaml');
    }
}
