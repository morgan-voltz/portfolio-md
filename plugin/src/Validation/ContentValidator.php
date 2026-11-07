<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Validation;

use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Voltz\PortfolioMd\Domain\ContentPath;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Frontmatter;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\Patterns;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;

/**
 * Checks a content tree against the conventions of content/README.md, as
 * an author does before pushing: every file one by one (frontmatter keys
 * and values, slug and file name, language and folders, sections of the
 * language, no inline HTML, no contact data on a page) and then the tree
 * as a whole (a translation has its French original, a translation key is
 * carried once per language, a slug is unique per kind across languages,
 * a relative link points to a document that exists).
 *
 * The lists it checks against come from the plugin, never from a copy:
 * Frontmatter::allowedKeys(), ContentType, Language, ProjectStatus,
 * Patterns::SLUG, Frontmatter::SEO_DESCRIPTION_MAX_LENGTH, and a file
 * that passes every rule of its own is finally read by the plugin's
 * FrontmatterParser, so that "OK" here always means "importable". Only
 * the section words of each language and the content rules are its own.
 *
 * Messages are French, like content/README.md and the people who read
 * them; the plugin's own messages stay English. A file collects every
 * error it has, where the plugin's parser stops at the first one.
 *
 * Two layouts are accepted: the one of ADR-020, content/<lang>/<folder>/,
 * and, until the content migration, the former content/<folder>/ where
 * every file is read as French. A tree that mixes both is refused.
 */
final class ContentValidator
{
    /** The key of the errors that concern the tree itself, not a file. */
    public const TREE = '(content)';

    /** The five sections of a project sheet, as whole words of their language (content/README.md). */
    private const SECTIONS = [
        'fr' => ['problème', 'décisions', 'appris', 'chiffres', 'liens'],
        'de' => ['Problem', 'Entscheidungen', 'gelernt', 'Zahlen', 'Links'],
        'en' => ['problem', 'decisions', 'learned', 'figures', 'links'],
    ];

    /** The extra section of the fil rouge sheet, per language. */
    private const FIL_ROUGE_SECTION = [
        'fr' => 'étapes du fil rouge',
        'de' => 'Etappen des roten Fadens',
        'en' => 'storyline steps',
    ];

    /** A Markdown link to a .md file: the target, an optional fragment, an optional title. */
    private const MARKDOWN_LINK = '/\]\(([^)\s]+\.md)(?:#[^)\s]*)?(?:\s+"[^"]*")?\)/';

    /** A fenced code block, removed before looking for headings and links. */
    private const FENCE = '/^```.*?^```[ \t]*$/ms';

    private readonly FrontmatterParser $parser;

    /**
     * @param list<string> $skillCodes the competency codes of content/skills.yaml
     */
    public function __construct(private readonly array $skillCodes, ?FrontmatterParser $parser = null)
    {
        $this->parser = $parser ?? new FrontmatterParser();
    }

    /**
     * The competency codes of a decoded skills.yaml.
     *
     * @param array<string, mixed> $grid
     *
     * @return list<string>
     */
    public static function skillCodes(array $grid): array
    {
        $codes = [];
        foreach ((array) ($grid['referentials'] ?? []) as $referential) {
            foreach ((array) ($referential['skills'] ?? []) as $skill) {
                if (is_array($skill) && isset($skill['code'])) {
                    $codes[] = (string) $skill['code'];
                }
            }
        }

        return $codes;
    }

    /**
     * @throws InvalidArgumentException when the directory does not exist
     */
    public function validate(string $contentDir): ValidationReport
    {
        $contentDir = rtrim($contentDir, '/');
        $root = realpath($contentDir);
        if ($root === false || !is_dir($root)) {
            throw new InvalidArgumentException("dossier de contenu introuvable : {$contentDir}");
        }

        $layout = self::layoutOf($root);
        $errors = [];
        $notes = [];
        $documents = [];

        $entries = self::discover($root, $layout, $errors);
        if ($entries === []) {
            $errors[self::TREE][] = 'aucun document trouvé';
        }
        foreach ($entries as $entry) {
            $this->validateFile($entry, $layout, $root, $errors, $notes, $documents);
        }
        self::validateAcross($documents, $errors);

        ksort($errors);
        ksort($notes);

        return new ValidationReport($layout, $errors, $notes);
    }

    private static function layoutOf(string $root): string
    {
        foreach (Language::values() as $language) {
            if (is_dir("{$root}/{$language}")) {
                return ValidationReport::LANGUAGES;
            }
        }

        return ValidationReport::LEGACY;
    }

    /**
     * The Markdown files to check, with the language and the kind their
     * folders say. A Markdown file at an unexpected place is reported, not
     * checked; other files (images) are ignored.
     *
     * @param array<string, list<string>> $errors
     *
     * @return list<array{relative: string, file: string, lang: ?Language, type: ContentType}>
     */
    private static function discover(string $root, string $layout, array &$errors): array
    {
        $entries = [];
        foreach (self::directories($root) as $top) {
            if ($layout === ValidationReport::LEGACY) {
                $type = ContentType::fromFolder($top);
                if ($type === null) {
                    $errors[self::TREE][] = "dossier inattendu à la racine : {$top}/";
                    continue;
                }
                foreach (self::markdownFiles("{$root}/{$top}") as $file) {
                    $slug = basename($file, '.md');
                    if (preg_match(Patterns::SLUG, $slug) !== 1 || strlen($slug) > Patterns::SLUG_MAX_LENGTH) {
                        $errors["{$top}/" . basename($file)][] = 'nom de fichier invalide : attendu <slug>.md (minuscules, chiffres, tirets)';
                        continue;
                    }
                    $entries[] = ['relative' => "{$top}/" . basename($file), 'file' => $file, 'lang' => null, 'type' => $type];
                }
                self::reportNested("{$root}/{$top}", $top, '<dossier>/<slug>.md', $errors);
                continue;
            }

            $lang = Language::tryFrom($top);
            if ($lang === null) {
                if (ContentType::fromFolder($top) !== null) {
                    $errors[self::TREE][] = "arbre mixte : {$top}/ à côté des dossiers de langue";
                } else {
                    self::reportMisplaced("{$root}/{$top}", $top, '<lang>/<dossier>/<slug>.md', $errors);
                }
                continue;
            }
            foreach (self::markdownFiles("{$root}/{$top}") as $file) {
                $errors["{$top}/" . basename($file)][] = 'emplacement non reconnu : attendu <lang>/<dossier>/<slug>.md';
            }
            foreach (self::directories("{$root}/{$top}") as $folder) {
                if (ContentType::fromFolder($folder) === null) {
                    self::reportMisplaced("{$root}/{$top}/{$folder}", "{$top}/{$folder}", '<lang>/<dossier>/<slug>.md', $errors);
                    continue;
                }
                foreach (self::markdownFiles("{$root}/{$top}/{$folder}") as $file) {
                    $relative = "{$top}/{$folder}/" . basename($file);
                    $path = ContentPath::fromRelative($relative);
                    if ($path === null) {
                        $errors[$relative][] = 'nom de fichier invalide : attendu <slug>.md (minuscules, chiffres, tirets)';
                        continue;
                    }
                    $entries[] = ['relative' => $relative, 'file' => $file, 'lang' => $path->lang, 'type' => $path->type];
                }
                self::reportNested("{$root}/{$top}/{$folder}", "{$top}/{$folder}", '<lang>/<dossier>/<slug>.md', $errors);
            }
        }

        return $entries;
    }

    /**
     * Every Markdown file under a directory that holds no document: reported.
     *
     * @param array<string, list<string>> $errors
     */
    private static function reportMisplaced(string $dir, string $relativeDir, string $expected, array &$errors): void
    {
        foreach (self::markdownFilesBelow($dir) as $file) {
            $errors[$relativeDir . '/' . substr($file, strlen($dir) + 1)][] = "emplacement non reconnu : attendu {$expected}";
        }
    }

    /**
     * Markdown files nested below a document folder (where only images live): reported.
     *
     * @param array<string, list<string>> $errors
     */
    private static function reportNested(string $dir, string $relativeDir, string $expected, array &$errors): void
    {
        foreach (self::directories($dir) as $sub) {
            self::reportMisplaced("{$dir}/{$sub}", "{$relativeDir}/{$sub}", $expected, $errors);
        }
    }

    /**
     * @return list<string> names of the sub-directories, sorted, dot folders excluded
     */
    private static function directories(string $dir): array
    {
        $names = scandir($dir);
        if ($names === false) {
            throw new InvalidArgumentException("dossier illisible : {$dir}");
        }

        return array_values(array_filter($names, static fn (string $name): bool => $name[0] !== '.' && is_dir("{$dir}/{$name}")));
    }

    /**
     * @return list<string> absolute paths of the *.md files directly in the directory, sorted
     */
    private static function markdownFiles(string $dir): array
    {
        $files = array_values(array_filter(glob("{$dir}/*.md") ?: [], 'is_file'));
        sort($files);

        return $files;
    }

    /**
     * @return list<string> absolute paths of every *.md file at any depth below the directory, sorted
     */
    private static function markdownFilesBelow(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.md')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param array{relative: string, file: string, lang: ?Language, type: ContentType} $entry
     * @param array<string, list<string>>                                               $errors
     * @param array<string, string>                                                     $notes
     * @param list<array<string, mixed>>                                                $documents
     */
    private function validateFile(array $entry, string $layout, string $root, array &$errors, array &$notes, array &$documents): void
    {
        $relative = $entry['relative'];
        $file = $entry['file'];
        $text = file_get_contents($file);
        if ($text === false) {
            $errors[$relative][] = 'fichier illisible';

            return;
        }
        $text = FrontmatterParser::normalize($text);
        if (preg_match(FrontmatterParser::PATTERN, $text, $match) !== 1) {
            $errors[$relative][] = 'pas de frontmatter';

            return;
        }

        try {
            // PARSE_DATETIME: without it symfony/yaml turns a bare date into an integer timestamp.
            $fm = Yaml::parse($match['yaml'], Yaml::PARSE_DATETIME);
        } catch (ParseException $exception) {
            $errors[$relative][] = 'frontmatter YAML invalide : ' . $exception->getMessage();

            return;
        }
        if (!is_array($fm) || array_is_list($fm)) {
            $errors[$relative][] = 'frontmatter YAML invalide : attendu des clés et des valeurs';

            return;
        }
        $body = $match['body'];
        $prose = preg_replace(self::FENCE, '', $body) ?? $body;
        $found = [];

        $type = ContentType::tryFrom(is_string($fm['type'] ?? null) ? $fm['type'] : '');
        if ($type === null) {
            $found[] = 'type doit valoir ' . implode(', ', ContentType::values());
        }

        // The language: said by the folder in the languages layout, French in the legacy one.
        $lang = $entry['lang'] ?? Language::default();
        $langOk = true;
        if ($layout === ValidationReport::LANGUAGES) {
            if (!array_key_exists('lang', $fm)) {
                $langOk = false; // reported below, with the other missing keys
            } else {
                $declared = Language::tryFrom(is_string($fm['lang']) ? $fm['lang'] : '');
                if ($declared === null) {
                    $found[] = 'lang doit valoir ' . implode(', ', Language::values());
                    $langOk = false;
                } elseif ($declared !== $lang) {
                    $found[] = "lang ({$declared->value}) ne correspond pas au dossier {$lang->value}/";
                    $langOk = false;
                }
            }
        } elseif (array_key_exists('lang', $fm) && $fm['lang'] !== Language::default()->value) {
            $found[] = 'lang (' . (is_string($fm['lang']) ? $fm['lang'] : '?') . ') impossible avant migration : pas de dossier de langue';
            $langOk = false;
        } elseif (!array_key_exists('lang', $fm) && array_key_exists('translation_key', $fm)) {
            $found[] = 'translation_key sans lang : impossible avant migration';
        }

        if ($type !== null) {
            $allowed = Frontmatter::allowedKeys($type);
            $required = array_diff($allowed, ['reading_time']);
            if ($lang->isDefault()) {
                $required = array_diff($required, ['translation_key']);
            }
            if ($layout === ValidationReport::LEGACY) {
                $required = array_diff($required, ['lang']);
            }
            foreach ($required as $key) {
                if (!array_key_exists($key, $fm)) {
                    $found[] = "clé manquante : {$key}";
                }
            }
            foreach (array_keys($fm) as $key) {
                if (!in_array($key, $allowed, true)) {
                    $found[] = "clé inattendue : {$key}";
                }
            }
            if ($type !== $entry['type']) {
                $found[] = "le dossier {$entry['type']->folder()}/ ne correspond pas au type {$type->value}";
            }
        }

        $slug = is_string($fm['slug'] ?? null) ? $fm['slug'] : '';
        $slugOk = preg_match(Patterns::SLUG, $slug) === 1 && strlen($slug) <= Patterns::SLUG_MAX_LENGTH;
        if (!$slugOk) {
            $found[] = 'slug invalide';
        }
        if (basename($file, '.md') !== $slug) {
            $found[] = 'le nom de fichier doit être le slug';
        }
        if ($type !== null && $langOk && $slugOk && $type->suffixesSlugs() && !$lang->acceptsSlug($slug)) {
            $found[] = $lang->isDefault()
                ? 'slug fr ne doit pas finir par -de ni -en'
                : "slug {$lang->value} doit finir par {$lang->slugSuffix()}";
        }

        $translationKey = null;
        if (array_key_exists('translation_key', $fm)) {
            $translationKey = is_string($fm['translation_key']) ? $fm['translation_key'] : '';
            if (preg_match(Patterns::SLUG, $translationKey) !== 1) {
                $found[] = 'translation_key invalide : doit être un slug';
                $translationKey = null;
            } elseif ($langOk && $lang->isDefault() && $translationKey !== $slug) {
                $found[] = "translation_key d'un document fr doit être son slug ({$slug})";
            }
        } elseif ($lang->isDefault()) {
            $translationKey = $slug;
        }

        if (array_key_exists('seo_description', $fm) && !is_string($fm['seo_description']) && $fm['seo_description'] !== null) {
            $found[] = 'seo_description doit être une chaîne';
        } elseif (mb_strlen((string) ($fm['seo_description'] ?? '')) > Frontmatter::SEO_DESCRIPTION_MAX_LENGTH) {
            $found[] = 'seo_description > ' . Frontmatter::SEO_DESCRIPTION_MAX_LENGTH . ' caractères (' . mb_strlen((string) $fm['seo_description']) . ')';
        }
        if (!self::isIsoDate($fm['date'] ?? '')) {
            $found[] = "date n'est pas une date ISO";
        }
        if (preg_match('/<(script|iframe|div|span|style)\b/i', $body) === 1) {
            $found[] = 'HTML inline interdit';
        }
        if (preg_match('/^#[ \t]/mu', $prose) === 1) {
            $found[] = "h1 dans le corps : le titre vient du frontmatter, le thème l'affiche";
        }

        if ($type === ContentType::Page) {
            if (preg_match('/(\+33|0[1-9](?:[ .]?\d{2}){4}|@[a-z0-9.-]+\.[a-z]{2,})/i', $body) === 1) {
                $found[] = 'donnée de contact détectée (téléphone ou e-mail)';
            }
        } elseif ($type !== null) {
            foreach (['featured', 'is_fil_rouge'] as $key) {
                if (array_key_exists($key, $fm) && !is_bool($fm[$key])) {
                    $found[] = "{$key} doit être un booléen YAML";
                }
            }
            foreach (['repo_url', 'demo_url'] as $key) {
                $value = $fm[$key] ?? '';
                if ($value !== '' && $value !== null && (!is_string($value) || preg_match('#^https?://#', $value) !== 1)) {
                    $found[] = "{$key} doit être http(s) ou vide";
                }
            }
            foreach (['skills', 'tags'] as $key) {
                $value = $fm[$key] ?? [];
                if ($value !== null && (!is_array($value) || !array_is_list($value))) {
                    $found[] = "{$key} doit être une liste YAML";
                    unset($fm[$key]);
                }
            }
            foreach ((array) ($fm['skills'] ?? []) as $code) {
                if (!is_string($code) || !in_array($code, $this->skillCodes, true)) {
                    $found[] = 'compétence inconnue : ' . (is_scalar($code) ? (string) $code : '?');
                }
            }
            foreach ((array) ($fm['tags'] ?? []) as $tag) {
                if (!is_string($tag) || preg_match(Patterns::SLUG, $tag) !== 1) {
                    $found[] = 'tag non slug : ' . (is_scalar($tag) ? (string) $tag : '?');
                }
            }
            if ($type === ContentType::Project) {
                if (!in_array($fm['status'] ?? '', ProjectStatus::values(), true)) {
                    $found[] = 'status hors liste : ' . implode(', ', ProjectStatus::values());
                }
                foreach (['period_start', 'period_end'] as $key) {
                    if (!self::isIsoDate($fm[$key] ?? '')) {
                        $found[] = "{$key} n'est pas une date ISO";
                    }
                }
                if ($langOk) {
                    foreach (self::SECTIONS[$lang->value] as $word) {
                        if (!self::hasHeading($prose, $word)) {
                            $found[] = "section manquante : {$word}";
                        }
                    }
                    if (($fm['is_fil_rouge'] ?? false) === true && !self::hasHeading($prose, self::FIL_ROUGE_SECTION[$lang->value])) {
                        $found[] = 'fil rouge sans section de correspondance avec les étapes';
                    }
                }
            }
        }

        if (preg_match_all(self::MARKDOWN_LINK, $prose, $links) > 0) {
            foreach (array_unique($links[1]) as $target) {
                if (preg_match('#^[a-z][a-z0-9+.-]*:|^/#i', $target) === 1) {
                    continue; // absolute or external: not a document link
                }
                if (!str_starts_with($target, '../')) {
                    $found[] = "lien relatif non réécrit par le rendu : {$target} (écrire ../<dossier>/<slug>.md ou ../../<lang>/<dossier>/<slug>.md)";
                    continue;
                }
                $resolved = realpath(dirname($file) . '/' . $target);
                if ($resolved === false || !is_file($resolved)) {
                    $found[] = "lien relatif vers une cible absente : {$target}";
                } elseif (!str_starts_with($resolved, $root . '/')) {
                    $found[] = "lien relatif vers une cible hors de content/ : {$target}";
                }
            }
        }

        // The last word is the plugin's: a file this validator lets through must be one the import accepts.
        if ($found === []) {
            try {
                $this->parser->parse($text);
            } catch (MalformedDocumentException | InvalidFrontmatterException $exception) {
                $found[] = 'refusé par le parseur du plugin : ' . $exception->getMessage();
            }
        }

        $failed = $found !== [];
        if ($failed) {
            $errors[$relative] = $found;
        } else {
            $words = str_word_count(strip_tags($body));
            $notes[$relative] = $type === ContentType::Page
                ? "{$words} mots, page"
                : "{$words} mots, " . count((array) ($fm['skills'] ?? [])) . ' compétences, ' . count((array) ($fm['tags'] ?? [])) . ' tags';
        }
        if ($type !== null && $langOk && $slugOk) {
            $documents[] = ['relative' => $relative, 'type' => $type, 'lang' => $lang, 'slug' => $slug, 'key' => $translationKey, 'failed' => $failed];
        }
    }

    /**
     * The rules that need the whole tree: translation groups and slugs. A
     * file that already fails still counts as existing, so that its
     * translations are not called orphans on top of its own errors.
     *
     * @param list<array<string, mixed>>  $documents
     * @param array<string, list<string>> $errors
     */
    private static function validateAcross(array $documents, array &$errors): void
    {
        $groups = [];
        $slugs = [];
        $failed = [];
        foreach ($documents as $document) {
            /** @var ContentType $type */
            $type = $document['type'];
            /** @var Language $lang */
            $lang = $document['lang'];
            if ($document['failed']) {
                $failed[$document['relative']] = true;
            }
            if ($document['key'] !== null) {
                $groups["{$type->value}|{$document['key']}"][$lang->value][] = $document['relative'];
            }
            $slugs["{$type->value}|{$document['slug']}"][] = $document['relative'];
        }

        foreach ($groups as $groupId => $byLanguage) {
            [$typeValue, $key] = explode('|', $groupId, 2);
            foreach ($byLanguage as $paths) {
                sort($paths);
                foreach (array_slice($paths, 1) as $duplicate) {
                    if (!isset($failed[$duplicate])) {
                        $errors[$duplicate][] = "clé de traduction {$key} déjà portée par {$paths[0]}";
                    }
                }
            }
            if (!isset($byLanguage[Language::default()->value])) {
                foreach ($byLanguage as $paths) {
                    foreach ($paths as $path) {
                        if (!isset($failed[$path])) {
                            $errors[$path][] = "traduction sans original français ({$typeValue}, clé {$key})";
                        }
                    }
                }
            }
        }

        foreach ($slugs as $slugId => $paths) {
            if (count($paths) < 2) {
                continue;
            }
            sort($paths);
            $slug = explode('|', $slugId, 2)[1];
            foreach (array_slice($paths, 1) as $duplicate) {
                if (!isset($failed[$duplicate])) {
                    $errors[$duplicate][] = "slug {$slug} déjà utilisé par {$paths[0]} (WordPress suffixerait en -2)";
                }
            }
        }
    }

    /** A "## " heading of the prose that contains the phrase as whole words, in any case. */
    private static function hasHeading(string $prose, string $phrase): bool
    {
        return preg_match('/^##[ \t]+.*(?<!\p{L})' . preg_quote($phrase, '/') . '(?!\p{L})/imu', $prose) === 1;
    }

    private static function isIsoDate(mixed $value): bool
    {
        if ($value === '' || $value === null || $value instanceof \DateTimeInterface) {
            return true;
        }

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
