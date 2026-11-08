<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\InvalidTagsFileException;
use Voltz\PortfolioMd\Domain\TagNames;
use Voltz\PortfolioMd\Domain\TechTagName;
use Voltz\PortfolioMd\Parsing\TagsFileLoader;

/**
 * The names file of the technology tags (ADR-024): both forms of an entry,
 * what it refuses, and the real file of the repository.
 */
#[CoversClass(TagsFileLoader::class)]
#[CoversClass(TagNames::class)]
#[CoversClass(TechTagName::class)]
final class TagsFileLoaderTest extends TestCase
{
    private const FILE = __DIR__ . '/../../../../content/tags.yaml';

    private TagsFileLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new TagsFileLoader();
    }

    #[Test]
    public function reads_a_plain_name_and_a_translated_one(): void
    {
        $names = $this->loader->fromYaml("tags:\n  csharp: \"C#\"\n  architecture-hexagonale:\n    name: \"Architecture hexagonale\"\n    i18n: { de: \"Hexagonale Architektur\", en: \"Hexagonal architecture\" }\n");

        self::assertSame(2, $names->count());
        self::assertSame(['csharp', 'architecture-hexagonale'], $names->slugs());
        self::assertTrue($names->has('csharp'));
        self::assertFalse($names->has('rust'));
        $csharp = $names->get('csharp');
        self::assertNotNull($csharp);
        self::assertSame(['fr' => 'C#'], $csharp->names(), 'a plain name has no translation: it serves in every language');
        $hexagonal = $names->get('architecture-hexagonale');
        self::assertNotNull($hexagonal);
        self::assertSame(['fr' => 'Architecture hexagonale', 'de' => 'Hexagonale Architektur', 'en' => 'Hexagonal architecture'], $hexagonal->names());
        self::assertSame(['de' => 'Hexagonale Architektur', 'en' => 'Hexagonal architecture'], $hexagonal->translations);
    }

    #[Test]
    public function the_file_of_the_repository_loads_and_names_every_tag_of_the_sheets(): void
    {
        $names = $this->loader->load(self::FILE);

        self::assertGreaterThanOrEqual(84, $names->count());
        $used = [];
        foreach (glob(dirname(self::FILE) . '/fr/projects/*.md') ?: [] as $sheet) {
            if (preg_match('/^tags: *\[(.*)\]/m', (string) file_get_contents($sheet), $match) === 1) {
                foreach (explode(',', $match[1]) as $tag) {
                    $used[trim($tag)] = true;
                }
            }
        }
        self::assertNotSame([], $used);
        foreach (array_keys($used) as $slug) {
            self::assertTrue($names->has($slug), "the tag {$slug} of a French sheet is named in content/tags.yaml");
        }
    }

    #[Test]
    public function an_all_digit_slug_stays_a_string(): void
    {
        $names = $this->loader->fromYaml("tags:\n  \"2026\": \"Millésime\"\n  rust: \"Rust\"\n");

        self::assertSame(['2026', 'rust'], $names->slugs(), 'PHP would turn the key into an int; the validator compares strings');
        self::assertTrue($names->has('2026'));
        self::assertSame('Millésime', $names->get('2026')?->name);
    }

    #[Test]
    public function refuses_what_is_not_a_slug_or_not_a_name(): void
    {
        $this->expectException(InvalidTagsFileException::class);
        $this->expectExceptionMessage('"C Sharp" is not a slug');
        $this->loader->fromYaml("tags:\n  C Sharp: \"C#\"\n");
    }

    #[Test]
    public function refuses_an_entry_that_is_neither_a_name_nor_a_mapping(): void
    {
        $this->expectException(InvalidTagsFileException::class);
        $this->expectExceptionMessage('tag rust must be a name or a mapping');
        $this->loader->fromYaml("tags:\n  rust: [\"Rust\"]\n");
    }

    #[Test]
    public function refuses_an_i18n_block_that_lacks_a_language_or_carries_an_unknown_key(): void
    {
        try {
            $this->loader->fromYaml("tags:\n  securite:\n    name: \"Sécurité\"\n    i18n: { de: \"Sicherheit\" }\n");
            self::fail('a missing English wording must be refused');
        } catch (InvalidTagsFileException $exception) {
            self::assertStringContainsString('lacks the language "en"', $exception->getMessage());
        }

        $this->expectException(InvalidTagsFileException::class);
        $this->expectExceptionMessage('tag securite, i18n has unknown key(s): es');
        $this->loader->fromYaml("tags:\n  securite:\n    name: \"Sécurité\"\n    i18n: { de: \"Sicherheit\", en: \"Security\", es: \"Seguridad\" }\n");
    }

    #[Test]
    public function refuses_a_name_wordpress_would_store_changed_and_an_unknown_key_of_an_entry(): void
    {
        try {
            $this->loader->fromYaml("tags:\n  cpp: \"C++ & co\"\n");
            self::fail('an ampersand must be refused');
        } catch (InvalidTagsFileException $exception) {
            self::assertStringContainsString('plain text on one line', $exception->getMessage());
        }

        $this->expectException(InvalidTagsFileException::class);
        $this->expectExceptionMessage('tag rust has unknown key(s): colour');
        $this->loader->fromYaml("tags:\n  rust:\n    name: \"Rust\"\n    colour: orange\n");
    }

    #[Test]
    public function refuses_an_empty_or_shapeless_file(): void
    {
        foreach (["tags: []\n", "tags: {}\n", "- rust\n", "other: 1\n"] as $yaml) {
            try {
                $this->loader->fromYaml($yaml);
                self::fail("{$yaml} must be refused");
            } catch (InvalidTagsFileException $exception) {
                self::assertStringStartsWith('Tags file: ', $exception->getMessage());
            }
        }
        $this->expectException(InvalidTagsFileException::class);
        $this->expectExceptionMessage('is not readable');
        $this->loader->load('/nowhere/tags.yaml');
    }
}
