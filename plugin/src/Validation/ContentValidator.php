<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Validation;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Frontmatter;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\Patterns;
use Voltz\PortfolioMd\Domain\ProjectStatus;

/**
 * Checks a content tree against the conventions of content/README.md, as
 * an author does before pushing: every file one by one (frontmatter keys
 * and values, slug and file name, language and folders, sections of the
 * language, no inline HTML, no contact data on a page) and then the tree
 * as a whole (a translation has its French original, a translation key is
 * carried once per language, a slug is unique per kind across languages,
 * a relative link points to a file that exists).
 *
 * The lists it checks against come from the plugin, never from a copy:
 * Frontmatter::allowedKeys(), ContentType, Language, ProjectStatus,
 * Patterns::SLUG, Frontmatter::SEO_DESCRIPTION_MAX_LENGTH. Only the
 * section words of each language and the content rules are its own.
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

    private const FRONTMATTER = '/\A---\n(.*?)\n---\n(.*)\z/s';

    /** A relative link to another document, `../<folder>/<slug>.md` or `../../<lang>/<folder>/<slug>.md`, with an optional fragment. */
    private const RELATIVE_LINK = '/\]\((\.\.\/[^)\s#]+\.md)(?:#[^)]*)?\)/';

    /**
     * @param list<string> $skillCodes the competency codes of content/skills.yaml
     */
    public function __construct(private readonly array $skillCodes)
    {
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

    public function validate(string $contentDir): ValidationReport
    {
        $contentDir = rtrim($contentDir, '/');
        $layout = self::layoutOf($contentDir);
        $errors = [];
        $notes = [];
        $documents = [];

        foreach (self::discover($contentDir, $layout, $errors) as $entry) {
            $this->validateFile($entry, $layout, $errors, $notes, $documents);
        }
        self::validateAcross($documents, $errors);

        ksort($errors);
        ksort($notes);

        return new ValidationReport($layout, $errors, $notes);
    }

    private static function layoutOf(string $contentDir): string
    {
        foreach (Language::values() as $language) {
            if (is_dir("{$contentDir}/{$language}")) {
                return ValidationReport::LANGUAGES;
            }
        }

        return ValidationReport::LEGACY;
    }

    /**
     * The Markdown files to check, with the language and the kind their
     * folders say. Files at an unexpected place are reported, not checked.
     *
     * @param array<string, list<string>> $errors
     *
     * @return list<array{relative: string, file: string, lang: ?Language, type: ContentType}>
     */
    private static function discover(string $contentDir, string $layout, array &$errors): array
    {
        $entries = [];
        foreach (self::directories($contentDir) as $top) {
            if ($layout === ValidationReport::LEGACY) {
                $type = ContentType::fromFolder($top);
                if ($type === null) {
                    $errors[self::TREE][] = "dossier inattendu à la racine : {$top}/";
                    continue;
                }
                foreach (self::markdownFiles("{$contentDir}/{$top}") as $file) {
                    $entries[] = ['relative' => "{$top}/" . basename($file), 'file' => $file, 'lang' => null, 'type' => $type];
                }
                continue;
            }

            $lang = Language::tryFrom($top);
            if ($lang === null) {
                if (ContentType::fromFolder($top) !== null) {
                    $errors[self::TREE][] = "arbre mixte : {$top}/ à côté des dossiers de langue";
                } else {
                    self::reportMisplaced($contentDir, $top, $errors);
                }
                continue;
            }
            foreach (self::markdownFiles("{$contentDir}/{$top}") as $file) {
                $errors["{$top}/" . basename($file)][] = 'emplacement non reconnu : attendu <lang>/<dossier>/<slug>.md';
            }
            foreach (self::directories("{$contentDir}/{$top}") as $folder) {
                $type = ContentType::fromFolder($folder);
                if ($type === null) {
                    self::reportMisplaced($contentDir, "{$top}/{$folder}", $errors);
                    continue;
                }
                foreach (self::markdownFiles("{$contentDir}/{$top}/{$folder}") as $file) {
                    $entries[] = ['relative' => "{$top}/{$folder}/" . basename($file), 'file' => $file, 'lang' => $lang, 'type' => $type];
                }
            }
        }

        return $entries;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private static function reportMisplaced(string $contentDir, string $relativeDir, array &$errors): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$contentDir}/{$relativeDir}", \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.md')) {
                $relative = $relativeDir . '/' . ltrim(str_replace("{$contentDir}/{$relativeDir}", '', $file->getPathname()), '/');
                $errors[$relative][] = 'emplacement non reconnu : attendu <lang>/<dossier>/<slug>.md';
            }
        }
    }

    /**
     * @return list<string> names of the sub-directories, sorted, dot folders excluded
     */
    private static function directories(string $dir): array
    {
        $names = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name[0] !== '.' && is_dir("{$dir}/{$name}")) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string> absolute paths of the *.md files directly in the directory, sorted
     */
    private static function markdownFiles(string $dir): array
    {
        $files = glob("{$dir}/*.md") ?: [];
        sort($files);

        return $files;
    }

    /**
     * @param array{relative: string, file: string, lang: ?Language, type: ContentType} $entry
     * @param array<string, list<string>>                                               $errors
     * @param array<string, string>                                                     $notes
     * @param list<array<string, mixed>>                                                $documents
     */
    private function validateFile(array $entry, string $layout, array &$errors, array &$notes, array &$documents): void
    {
        $relative = $entry['relative'];
        $file = $entry['file'];
        $text = file_get_contents($file);
        if ($text === false || preg_match(self::FRONTMATTER, $text, $match) !== 1) {
            $errors[$relative][] = 'pas de frontmatter';

            return;
        }

        try {
            // PARSE_DATETIME: without it symfony/yaml turns a bare date into an integer timestamp.
            $fm = Yaml::parse($match[1], Yaml::PARSE_DATETIME);
        } catch (ParseException $exception) {
            $errors[$relative][] = 'frontmatter YAML invalide : ' . $exception->getMessage();

            return;
        }
        if (!is_array($fm) || array_is_list($fm)) {
            $errors[$relative][] = 'frontmatter YAML invalide : attendu des clés et des valeurs';

            return;
        }
        $body = $match[2];
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
        $slugOk = preg_match(Patterns::SLUG, $slug) === 1;
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

        $seoDescription = (string) ($fm['seo_description'] ?? '');
        if (mb_strlen($seoDescription) > Frontmatter::SEO_DESCRIPTION_MAX_LENGTH) {
            $found[] = 'seo_description > ' . Frontmatter::SEO_DESCRIPTION_MAX_LENGTH . ' caractères (' . mb_strlen($seoDescription) . ')';
        }
        if (!self::isIsoDate($fm['date'] ?? '')) {
            $found[] = "date n'est pas une date ISO";
        }
        if (preg_match('/<(script|iframe|div|span|style)\b/i', $body) === 1) {
            $found[] = 'HTML inline interdit';
        }
        $prose = preg_replace('/^```.*?^```[ \t]*$/ms', '', $body) ?? $body;
        if (preg_match('/^#\s/mu', $prose) === 1) {
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
                $value = (string) ($fm[$key] ?? '');
                if ($value !== '' && preg_match('#^https?://#', $value) !== 1) {
                    $found[] = "{$key} doit être http(s) ou vide";
                }
            }
            foreach ((array) ($fm['skills'] ?? []) as $code) {
                if (!in_array((string) $code, $this->skillCodes, true)) {
                    $found[] = "compétence inconnue : {$code}";
                }
            }
            foreach ((array) ($fm['tags'] ?? []) as $tag) {
                if (preg_match(Patterns::SLUG, (string) $tag) !== 1) {
                    $found[] = "tag non slug : {$tag}";
                }
            }
            if ($type === ContentType::Article && array_key_exists('reading_time', $fm) && !is_int($fm['reading_time'])) {
                $found[] = 'reading_time doit être un entier (minutes)';
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
                        if (!self::hasHeading($body, $word)) {
                            $found[] = "section manquante : {$word}";
                        }
                    }
                    if (($fm['is_fil_rouge'] ?? false) === true && !self::hasHeading($body, self::FIL_ROUGE_SECTION[$lang->value])) {
                        $found[] = 'fil rouge sans section de correspondance avec les étapes';
                    }
                }
            }
        }

        if (preg_match_all(self::RELATIVE_LINK, $body, $links) > 0) {
            foreach (array_unique($links[1]) as $target) {
                $resolved = realpath(dirname($file) . '/' . $target);
                if ($resolved === false || !is_file($resolved)) {
                    $found[] = "lien relatif vers une cible absente : {$target}";
                }
            }
        }

        if ($found !== []) {
            $errors[$relative] = $found;

            return;
        }

        $words = str_word_count(strip_tags($body));
        $notes[$relative] = $type === ContentType::Page
            ? "{$words} mots, page"
            : "{$words} mots, " . count((array) ($fm['skills'] ?? [])) . ' compétences, ' . count((array) ($fm['tags'] ?? [])) . ' tags';
        $documents[] = ['relative' => $relative, 'type' => $type, 'lang' => $lang, 'slug' => $slug, 'key' => $translationKey];
    }

    /**
     * The rules that need the whole tree: translation groups and slugs.
     *
     * @param list<array<string, mixed>>  $documents the files that passed on their own
     * @param array<string, list<string>> $errors
     */
    private static function validateAcross(array $documents, array &$errors): void
    {
        $groups = [];
        $slugs = [];
        foreach ($documents as $document) {
            /** @var ContentType $type */
            $type = $document['type'];
            /** @var Language $lang */
            $lang = $document['lang'];
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
                    $errors[$duplicate][] = "clé de traduction {$key} déjà portée par {$paths[0]}";
                }
            }
            if (!isset($byLanguage[Language::default()->value])) {
                foreach ($byLanguage as $paths) {
                    foreach ($paths as $path) {
                        $errors[$path][] = "traduction sans original français ({$typeValue}, clé {$key})";
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
                $errors[$duplicate][] = "slug {$slug} déjà utilisé par {$paths[0]} (WordPress suffixerait en -2)";
            }
        }
    }

    /** A "## " heading that contains the phrase as whole words, in any case. */
    private static function hasHeading(string $body, string $phrase): bool
    {
        return preg_match('/^##\s.*(?<!\p{L})' . preg_quote($phrase, '/') . '(?!\p{L})/imu', $body) === 1;
    }

    private static function isIsoDate(mixed $value): bool
    {
        if ($value === '' || $value === null || $value instanceof \DateTimeInterface) {
            return true;
        }

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
