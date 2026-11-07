<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Tests\Support\ContentTree;
use Voltz\PortfolioMd\Validation\ContentValidator;
use Voltz\PortfolioMd\Validation\ValidationReport;

#[CoversClass(ContentValidator::class)]
#[CoversClass(ValidationReport::class)]
final class ContentValidatorTest extends TestCase
{
    private const CONTENT = __DIR__ . '/../../../../content';

    private ContentTree $tree;
    private ContentValidator $validator;

    protected function setUp(): void
    {
        $this->tree = ContentTree::create();
        $this->validator = new ContentValidator(['4.0.2/E07', '4.0.1/FR-13']);
    }

    protected function tearDown(): void
    {
        $this->tree->remove();
    }

    private function validate(): ValidationReport
    {
        return $this->validator->validate($this->tree->root());
    }

    private static function assertClean(ValidationReport $report): void
    {
        self::assertFalse($report->hasErrors(), json_encode($report->errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '');
    }

    private static function assertError(ValidationReport $report, string $path, string $messagePart): void
    {
        self::assertArrayHasKey($path, $report->errors, json_encode($report->errors, JSON_UNESCAPED_UNICODE) ?: '');
        self::assertStringContainsString($messagePart, implode("\n", $report->errors[$path]));
    }

    // ------------------------------------------------------------ layouts

    #[Test]
    public function the_real_content_tree_has_no_error(): void
    {
        $grid = Yaml::parseFile(self::CONTENT . '/skills.yaml');
        $report = (new ContentValidator(ContentValidator::skillCodes(is_array($grid) ? $grid : [])))->validate(self::CONTENT);

        self::assertClean($report);
        self::assertCount(count(glob(self::CONTENT . '/*/*/*.md') ?: []), $report->notes, 'every document of the tree passes');
        self::assertGreaterThanOrEqual(16, count($report->notes), 'the fourteen sheets and the two pages of content/fr/, at least');
    }

    #[Test]
    public function the_language_layout_is_read(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());

        $report = $this->validate();

        self::assertClean($report);
        self::assertSame('fr/projects/momocad.md', $report->paths()[0]);
    }

    #[Test]
    public function a_type_folder_at_the_root_is_refused(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->put('projects/atlas.md', ContentTree::projectFrontmatter(['slug' => 'atlas', 'title' => 'Atlas']), ContentTree::projectBody());

        $report = $this->validate();

        self::assertError($report, ContentValidator::TREE, 'dossier de type à la racine : projects/ ; les documents vivent dans content/<lang>/projects/');
        self::assertSame(ContentValidator::TREE, $report->paths()[0]);
        self::assertArrayNotHasKey('fr/projects/momocad.md', $report->errors);
    }

    #[Test]
    public function a_tree_of_the_former_layout_is_refused_outright(): void
    {
        $this->tree->put('projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->put('pages/cv.md', ContentTree::pageFrontmatter(), 'Mon parcours.');
        $this->tree->putRaw('articles/rust.md', "---\ntitle: Rust\n---\n");

        $report = $this->validate();

        self::assertError($report, ContentValidator::TREE, 'dossier de type à la racine : projects/');
        self::assertError($report, ContentValidator::TREE, 'dossier de type à la racine : pages/');
        self::assertError($report, ContentValidator::TREE, 'dossier de type à la racine : articles/ ; les documents vivent dans content/<lang>/articles/');
        self::assertError($report, ContentValidator::TREE, 'aucun document trouvé');
        self::assertSame([ContentValidator::TREE], $report->paths(), 'no file is read where no language folder exists');
    }

    #[Test]
    public function a_file_outside_the_language_folders_is_reported(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->put('es/projects/momocad-es.md', ContentTree::projectFrontmatter(['slug' => 'momocad-es', 'lang' => 'es']), ContentTree::projectBody());
        $this->tree->put('fr/posts/note.md', ContentTree::projectFrontmatter(['slug' => 'note']), ContentTree::projectBody());
        $this->tree->put('fr/stray.md', ContentTree::projectFrontmatter(['slug' => 'stray']), ContentTree::projectBody());

        $report = $this->validate();

        self::assertError($report, 'es/projects/momocad-es.md', 'emplacement non reconnu');
        self::assertError($report, 'fr/posts/note.md', 'emplacement non reconnu');
        self::assertError($report, 'fr/stray.md', 'emplacement non reconnu');
        self::assertArrayNotHasKey('fr/projects/momocad.md', $report->errors);
    }

    // ------------------------------------------------------------ one file at a time

    #[Test]
    public function a_valid_trilingual_group_passes(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->put('de/projects/momocad-de.md', ContentTree::projectFrontmatter(['slug' => 'momocad-de', 'lang' => 'de', 'translation_key' => 'momocad']), ContentTree::projectBody('de'));
        $this->tree->put('en/projects/momocad-en.md', ContentTree::projectFrontmatter(['slug' => 'momocad-en', 'lang' => 'en', 'translation_key' => 'momocad']), ContentTree::projectBody('en'));

        $report = $this->validate();

        self::assertClean($report);
        self::assertCount(3, $report->notes);
        self::assertStringContainsString('1 compétences, 2 tags', $report->notes['de/projects/momocad-de.md']);
    }

    #[Test]
    public function lang_is_required(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(['lang' => null]), ContentTree::projectBody());

        self::assertError($this->validate(), 'fr/projects/momocad.md', 'clé manquante : lang');
    }

    #[Test]
    public function lang_must_equal_the_language_folder(): void
    {
        $this->tree->put('fr/projects/momocad-de.md', ContentTree::projectFrontmatter(['slug' => 'momocad-de', 'lang' => 'de', 'translation_key' => 'momocad']), ContentTree::projectBody('de'));
        $this->tree->put('fr/projects/atlas.md', ContentTree::projectFrontmatter(['slug' => 'atlas', 'lang' => 'xx']), ContentTree::projectBody());

        $report = $this->validate();

        self::assertError($report, 'fr/projects/momocad-de.md', 'lang (de) ne correspond pas au dossier fr/');
        self::assertError($report, 'fr/projects/atlas.md', 'lang doit valoir fr, de, en');
    }

    #[Test]
    public function type_must_equal_the_type_folder(): void
    {
        $this->tree->put('fr/pages/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());

        self::assertError($this->validate(), 'fr/pages/momocad.md', 'le dossier pages/ ne correspond pas au type project');
    }

    #[Test]
    public function the_file_name_must_be_the_slug(): void
    {
        $this->tree->put('fr/projects/other.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());

        self::assertError($this->validate(), 'fr/projects/other.md', 'le nom de fichier doit être le slug');
    }

    #[Test]
    public function atlas_de_in_a_french_document_is_refused(): void
    {
        $this->tree->put('fr/projects/atlas-de.md', ContentTree::projectFrontmatter(['slug' => 'atlas-de', 'title' => 'Atlas']), ContentTree::projectBody());

        self::assertError($this->validate(), 'fr/projects/atlas-de.md', 'slug fr ne doit pas finir par -de ni -en');
    }

    #[Test]
    public function a_german_project_without_the_suffix_is_refused(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->put('de/projects/momocad.md', ContentTree::projectFrontmatter(['lang' => 'de', 'translation_key' => 'momocad']), ContentTree::projectBody('de'));

        self::assertError($this->validate(), 'de/projects/momocad.md', 'slug de doit finir par -de');
    }

    #[Test]
    public function pages_are_exempt_from_the_suffix_rule(): void
    {
        $this->tree->put('fr/pages/cv.md', ContentTree::pageFrontmatter(), 'Parcours.');
        $this->tree->put('de/pages/lebenslauf.md', ContentTree::pageFrontmatter(['slug' => 'lebenslauf', 'lang' => 'de', 'translation_key' => 'cv', 'title' => 'Lebenslauf']), 'Werdegang.');
        $this->tree->put('en/pages/resume.md', ContentTree::pageFrontmatter(['slug' => 'resume', 'lang' => 'en', 'translation_key' => 'cv', 'title' => 'Resume']), 'Career.');

        self::assertClean($this->validate());
    }

    #[Test]
    public function a_translation_key_is_required_outside_french(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->put('de/projects/momocad-de.md', ContentTree::projectFrontmatter(['slug' => 'momocad-de', 'lang' => 'de']), ContentTree::projectBody('de'));

        $report = $this->validate();

        self::assertError($report, 'de/projects/momocad-de.md', 'clé manquante : translation_key');
        self::assertArrayNotHasKey('fr/projects/momocad.md', $report->errors, 'French needs no key');
    }

    #[Test]
    public function a_translation_key_must_be_a_slug_and_french_must_use_its_own_slug(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(['translation_key' => 'other']), ContentTree::projectBody());
        $this->tree->put('de/projects/momocad-de.md', ContentTree::projectFrontmatter(['slug' => 'momocad-de', 'lang' => 'de', 'translation_key' => 'Momo Cad']), ContentTree::projectBody('de'));

        $report = $this->validate();

        self::assertError($report, 'fr/projects/momocad.md', "translation_key d'un document fr doit être son slug (momocad)");
        self::assertError($report, 'de/projects/momocad-de.md', 'translation_key invalide : doit être un slug');
    }

    #[Test]
    public function the_seo_description_is_capped_at_160_characters(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(['seo_description' => str_repeat('a', 160)]), ContentTree::projectBody());
        $this->tree->put('fr/projects/atlas.md', ContentTree::projectFrontmatter(['slug' => 'atlas', 'title' => 'Atlas', 'seo_description' => str_repeat('a', 161)]), ContentTree::projectBody());

        $report = $this->validate();

        self::assertArrayNotHasKey('fr/projects/momocad.md', $report->errors);
        self::assertError($report, 'fr/projects/atlas.md', 'seo_description > 160 caractères (161)');
    }

    #[Test]
    public function unknown_keys_types_statuses_and_skills_are_refused(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(['colour' => 'red', 'status' => 'finished', 'skills' => ['4.0.9/X01']]), ContentTree::projectBody());
        $this->tree->put('fr/projects/atlas.md', ContentTree::projectFrontmatter(['slug' => 'atlas', 'type' => 'post']), ContentTree::projectBody());

        $report = $this->validate();

        self::assertError($report, 'fr/projects/momocad.md', 'clé inattendue : colour');
        self::assertError($report, 'fr/projects/momocad.md', 'status hors liste');
        self::assertError($report, 'fr/projects/momocad.md', 'compétence inconnue : 4.0.9/X01');
        self::assertError($report, 'fr/projects/atlas.md', 'type doit valoir article, project, page');
    }

    #[Test]
    public function an_article_needs_no_section_and_a_project_needs_five(): void
    {
        $this->tree->put('fr/articles/rust.md', [
            'title' => 'Rust', 'slug' => 'rust', 'type' => 'article', 'lang' => 'fr', 'date' => '2026-10-26', 'subtitle' => '',
            'seo_description' => 'Une intuition.', 'featured' => false, 'tags' => ['rust'], 'skills' => [], 'reading_time' => 8,
        ], "Le vrai problème.\n");
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), str_replace('## Les décisions techniques', '## Les choix', ContentTree::projectBody()));

        $report = $this->validate();

        self::assertArrayNotHasKey('fr/articles/rust.md', $report->errors);
        self::assertError($report, 'fr/projects/momocad.md', 'section manquante : décisions');
    }

    #[Test]
    public function sections_are_checked_in_the_language_of_the_document(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody('de'));
        $this->tree->put('de/projects/momocad-de.md', ContentTree::projectFrontmatter(['slug' => 'momocad-de', 'lang' => 'de', 'translation_key' => 'momocad']), str_replace('## Das Problem', '## Le problème', ContentTree::projectBody('de')));

        $report = $this->validate();

        self::assertError($report, 'fr/projects/momocad.md', 'section manquante : problème');
        self::assertCount(5, $report->errors['fr/projects/momocad.md']);
        self::assertSame(['section manquante : Problem'], $report->errors['de/projects/momocad-de.md'], 'a French heading does not satisfy the German section: whole-word match');
    }

    #[Test]
    public function the_fil_rouge_section_is_required_when_flagged(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(['is_fil_rouge' => true]), ContentTree::projectBody());
        $this->tree->put('fr/projects/atlas.md', ContentTree::projectFrontmatter(['slug' => 'atlas', 'title' => 'Atlas', 'is_fil_rouge' => true]), ContentTree::projectBody('fr', true));

        $report = $this->validate();

        self::assertError($report, 'fr/projects/momocad.md', 'fil rouge sans section de correspondance');
        self::assertArrayNotHasKey('fr/projects/atlas.md', $report->errors);
    }

    #[Test]
    public function the_section_tables_cover_every_language(): void
    {
        $reflection = new ReflectionClass(ContentValidator::class);

        self::assertSame(Language::values(), array_keys((array) $reflection->getConstant('SECTIONS')));
        self::assertSame(Language::values(), array_keys((array) $reflection->getConstant('FIL_ROUGE_SECTION')));
    }

    // ------------------------------------------------------------ across the tree

    #[Test]
    public function a_translation_without_its_french_original_is_an_orphan(): void
    {
        $this->tree->put('de/projects/momocad-de.md', ContentTree::projectFrontmatter(['slug' => 'momocad-de', 'lang' => 'de', 'translation_key' => 'momocad']), ContentTree::projectBody('de'));
        $this->tree->put('fr/pages/momocad.md', ContentTree::pageFrontmatter(['slug' => 'momocad', 'title' => 'MomoCad']), 'Une page, pas un projet.');

        self::assertError($this->validate(), 'de/projects/momocad-de.md', 'traduction sans original français (project, clé momocad)');
    }

    #[Test]
    public function a_translation_key_is_carried_once_per_language(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->put('de/projects/momocad-2-de.md', ContentTree::projectFrontmatter(['slug' => 'momocad-2-de', 'lang' => 'de', 'translation_key' => 'momocad']), ContentTree::projectBody('de'));
        $this->tree->put('de/projects/momocad-de.md', ContentTree::projectFrontmatter(['slug' => 'momocad-de', 'lang' => 'de', 'translation_key' => 'momocad']), ContentTree::projectBody('de'));

        self::assertError($this->validate(), 'de/projects/momocad-de.md', 'clé de traduction momocad déjà portée par de/projects/momocad-2-de.md');
    }

    #[Test]
    public function the_same_page_slug_in_two_languages_is_refused(): void
    {
        $this->tree->put('fr/pages/cv.md', ContentTree::pageFrontmatter(), 'Parcours.');
        $this->tree->put('de/pages/cv.md', ContentTree::pageFrontmatter(['lang' => 'de', 'translation_key' => 'cv', 'title' => 'Lebenslauf']), 'Werdegang.');

        self::assertError($this->validate(), 'fr/pages/cv.md', 'slug cv déjà utilisé par de/pages/cv.md (WordPress suffixerait en -2)');
    }

    #[Test]
    public function relative_links_must_point_to_an_existing_document(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->put('de/projects/momocad-de.md', ContentTree::projectFrontmatter(['slug' => 'momocad-de', 'lang' => 'de', 'translation_key' => 'momocad']), ContentTree::projectBody('de'));
        $this->tree->put('fr/pages/cv.md', ContentTree::pageFrontmatter(), implode("\n", [
            '[MomoCad](../projects/momocad.md)',
            '[Liens](../projects/momocad.md#liens)',
            '[Deutsch](../../de/projects/momocad-de.md)',
            '[Ailleurs](https://example.org/x.md)',
            '[Fantôme](../projects/ghost.md)',
        ]));

        $report = $this->validate();

        self::assertSame(['lien relatif vers une cible absente : ../projects/ghost.md'], $report->errors['fr/pages/cv.md'] ?? []);
    }

    #[Test]
    public function a_file_without_frontmatter_or_with_broken_yaml_is_reported(): void
    {
        $this->tree->putRaw('fr/projects/bare.md', "No frontmatter here.\n");
        $this->tree->putRaw('fr/projects/broken.md', "---\ntitle: [unclosed\n---\nBody.\n");

        $report = $this->validate();

        self::assertError($report, 'fr/projects/bare.md', 'pas de frontmatter');
        self::assertError($report, 'fr/projects/broken.md', 'frontmatter YAML invalide');
    }

    // ------------------------------------------------------------ after the review

    #[Test]
    public function a_missing_or_empty_content_directory_is_refused(): void
    {
        self::assertError($this->validate(), ContentValidator::TREE, 'aucun document trouvé');

        $this->expectException(InvalidArgumentException::class);
        $this->validator->validate($this->tree->root() . '/nowhere');
    }

    #[Test]
    #[DataProvider('refusedByThePluginProvider')]
    public function what_the_plugin_parser_refuses_is_refused_too(array $overrides, string $messagePart): void
    {
        $frontmatter = ContentTree::projectFrontmatter($overrides);
        $slug = $frontmatter['slug'];
        $this->tree->put("fr/projects/{$slug}.md", $frontmatter, ContentTree::projectBody());

        self::assertError($this->validate(), "fr/projects/{$slug}.md", $messagePart);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusedByThePluginProvider(): iterable
    {
        yield 'an impossible quoted date' => [['date' => '2026-13-45'], 'refusé par le parseur du plugin'];
        yield 'a slug longer than WordPress allows' => [['slug' => str_repeat('a', 201)], 'nom de fichier invalide'];
        yield 'tags as a scalar' => [['tags' => 'abc'], 'tags doit être une liste YAML'];
        yield 'duplicate tags' => [['tags' => ['rust', 'rust']], 'refusé par le parseur du plugin'];
        yield 'an empty title' => [['title' => '   '], 'refusé par le parseur du plugin'];
        yield 'reading_time on a project' => [['reading_time' => 8], 'clé inattendue : reading_time'];
        yield 'a textual boolean' => [['featured' => 'yes'], 'featured doit être un booléen YAML'];
        yield 'a date in the wrong format' => [['date' => '26/10/2026'], "date n'est pas une date ISO"];
        yield 'a url without scheme' => [['repo_url' => 'github.com/x'], 'repo_url doit être http(s) ou vide'];
        yield 'a tag that is not a slug' => [['tags' => ['C#']], 'tag non slug : C#'];
        yield 'a status outside the list' => [['status' => 'finished'], 'status hors liste'];
        yield 'a seo description that is not text' => [['seo_description' => ['a']], 'seo_description doit être une chaîne'];
    }

    #[Test]
    public function the_content_rules_of_the_body_are_enforced(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), "<div>Non</div>\n# Titre répété\n" . ContentTree::projectBody());
        $this->tree->put('fr/pages/cv.md', ContentTree::pageFrontmatter(), "Appelez le +33 6 12 34 56 78.\n");
        $this->tree->put('fr/articles/rust.md', ['title' => 'Rust', 'slug' => 'rust', 'type' => 'article', 'lang' => 'fr', 'date' => '2026-10-26', 'subtitle' => '', 'seo_description' => 'x', 'featured' => false, 'tags' => [], 'skills' => [], 'reading_time' => 999], "Texte.\n");

        $report = $this->validate();

        self::assertError($report, 'fr/projects/momocad.md', 'HTML inline interdit');
        self::assertError($report, 'fr/projects/momocad.md', 'h1 dans le corps');
        self::assertError($report, 'fr/pages/cv.md', 'donnée de contact détectée');
        self::assertError($report, 'fr/articles/rust.md', 'refusé par le parseur du plugin');
    }

    #[Test]
    public function headings_and_links_inside_code_fences_do_not_count(): void
    {
        $fenced = "```markdown\n" . ContentTree::projectBody() . "\n[Fantôme](../projects/ghost.md)\n```\n";
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), $fenced);
        $this->tree->put('fr/projects/atlas.md', ContentTree::projectFrontmatter(['slug' => 'atlas', 'title' => 'Atlas']), "##\nproblème\n" . str_replace('## Le problème', '## Un sujet', ContentTree::projectBody()));

        $report = $this->validate();

        self::assertCount(5, array_filter($report->errors['fr/projects/momocad.md'], static fn (string $e): bool => str_starts_with($e, 'section manquante')));
        self::assertStringNotContainsString('ghost', implode(' ', $report->errors['fr/projects/momocad.md']));
        self::assertSame(['section manquante : problème'], $report->errors['fr/projects/atlas.md']);
    }

    #[Test]
    public function a_markdown_link_that_the_renderer_would_not_rewrite_is_reported(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->put('fr/pages/cv.md', ContentTree::pageFrontmatter(), "[Même dossier](momocad.md) et [Point](./momocad.md) et [Dehors](../../../README.md)\n");

        $errors = $this->validate()->errors['fr/pages/cv.md'] ?? [];

        self::assertStringContainsString('lien relatif non réécrit par le rendu : momocad.md', implode("\n", $errors));
        self::assertStringContainsString('lien relatif non réécrit par le rendu : ./momocad.md', implode("\n", $errors));
        self::assertStringContainsString('../../../README.md', implode("\n", $errors));
    }

    #[Test]
    public function a_failing_french_original_does_not_orphan_its_translations(): void
    {
        $this->tree->put('fr/pages/cv.md', ContentTree::pageFrontmatter(['seo_description' => str_repeat('a', 161)]), 'Parcours.');
        $this->tree->put('de/pages/lebenslauf.md', ContentTree::pageFrontmatter(['slug' => 'lebenslauf', 'lang' => 'de', 'translation_key' => 'cv', 'title' => 'Lebenslauf']), 'Werdegang.');

        $report = $this->validate();

        self::assertError($report, 'fr/pages/cv.md', 'seo_description > 160');
        self::assertArrayNotHasKey('de/pages/lebenslauf.md', $report->errors);
    }

    #[Test]
    public function documents_with_a_bom_or_crlf_line_endings_are_read_like_the_plugin_reads_them(): void
    {
        $frontmatter = Yaml::dump(ContentTree::projectFrontmatter(), 2);
        $this->tree->putRaw('fr/projects/momocad.md', "\xEF\xBB\xBF---\r\n" . str_replace("\n", "\r\n", $frontmatter) . "---\r\n" . str_replace("\n", "\r\n", ContentTree::projectBody()));
        $this->tree->putRaw('fr/pages/cv.md', "---\n" . Yaml::dump(ContentTree::pageFrontmatter(), 2) . '---');

        self::assertClean($this->validate());
    }

    #[Test]
    public function markdown_files_nested_under_a_type_folder_and_bad_file_names_are_reported(): void
    {
        $this->tree->put('fr/projects/momocad.md', ContentTree::projectFrontmatter(), ContentTree::projectBody());
        $this->tree->putRaw('fr/projects/momocad/notes.md', "---\ntitle: x\n---\n");
        $this->tree->putRaw('fr/projects/Foo.md', "---\ntitle: x\n---\n");

        $report = $this->validate();

        self::assertError($report, 'fr/projects/momocad/notes.md', 'emplacement non reconnu');
        self::assertError($report, 'fr/projects/Foo.md', 'nom de fichier invalide');
        self::assertArrayNotHasKey('fr/projects/momocad.md', $report->errors);
    }
}
