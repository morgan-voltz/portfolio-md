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
            'description' => 'Première année.',
            'skills' => [[
                'code' => '4.0.1/FR-01',
                'slug' => 'fr-01-outils',
                'name' => 'Utiliser des outils collaboratifs',
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
    public function the_grid_parents_are_the_referentials_seeded_at_activation(): void
    {
        $grid = $this->loader->load(self::GRID_FILE);

        foreach ($grid->referentials as $referential) {
            self::assertArrayHasKey($referential->slug, TaxonomyRegistrar::SKILL_REFERENTIALS);
            self::assertSame(TaxonomyRegistrar::SKILL_REFERENTIALS[$referential->slug], $referential->name);
        }
    }

    #[Test]
    public function builds_typed_objects_from_an_array(): void
    {
        $grid = $this->loader->fromArray(self::grid());

        self::assertSame('4.0.1', $grid->referentials[0]->code);
        self::assertSame('fr-01-outils', $grid->referentials[0]->skills[0]->slug);
        self::assertSame(1, $grid->countSkills());
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
        yield 'missing name' => [['referentials' => [0 => ['skills' => [0 => ['name' => '']]]]], '"name" must be a non-empty string'];
    }

    #[Test]
    public function rejects_duplicate_codes_across_the_grid(): void
    {
        $grid = self::grid();
        $grid['referentials'][0]['skills'][] = ['code' => '4.0.1/FR-01', 'slug' => 'other', 'name' => 'n', 'description' => 'd'];

        $this->expectException(InvalidSkillsGridException::class);
        $this->expectExceptionMessage('used twice');

        $this->loader->fromArray($grid);
    }

    #[Test]
    public function rejects_a_slug_reused_across_referentials(): void
    {
        $grid = self::grid();
        $grid['referentials'][] = [
            'code' => '4.0.2', 'slug' => '4-0-2', 'name' => 'Compétences 4.0.2', 'description' => 'Deuxième année.',
            'skills' => [['code' => '4.0.2/E01', 'slug' => 'fr-01-outils', 'name' => 'n', 'description' => 'd']],
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
