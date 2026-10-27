<?php

/**
 * Validates the Markdown content files against the conventions of content/README.md.
 *
 * Checks the frontmatter keys and types, the slug/file name match, the status
 * enum, ISO dates, http(s) URLs, the skills codes against content/skills.yaml,
 * the required sections of a project sheet, the absence of inline HTML and,
 * for pages, the absence of contact data; and that no body repeats its
 * title as an h1, which the theme already displays from the frontmatter.
 *
 * Stop-gap until the plugin's FrontmatterParser exists (roadmap phase 2); it
 * only needs symfony/yaml from the plugin's vendor/.
 *
 * From the repository root, without PHP on the host:
 *
 *   podman run --rm -v "$PWD:/repo:z" -w /repo docker.io/library/php:8.3-cli \
 *       php plugin/tools/validate-content.php content/skills.yaml content/projects/*.md content/pages/*.md
 *
 * Exit code 1 when any file fails.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

if ($argc < 3) {
    fwrite(STDERR, "usage: validate-content.php <skills.yaml> <file.md>...\n");
    exit(2);
}

$grid = Yaml::parseFile($argv[1]);
$codes = [];
foreach ($grid['referentials'] as $ref) {
    foreach ($ref['skills'] as $s) {
        $codes[$s['code']] = true;
    }
}

$projectKeys = ['title', 'slug', 'type', 'date', 'subtitle', 'seo_description', 'featured', 'status', 'role',
    'period_start', 'period_end', 'repo_url', 'demo_url', 'client', 'is_fil_rouge', 'tags', 'skills'];
$pageKeys = ['title', 'slug', 'type', 'date', 'seo_description'];
$statuses = ['en-cours', 'stable', 'archive', 'prototype'];
$exit = 0;

$isIsoDate = static function (mixed $v): bool {
    if ($v === '' || $v instanceof DateTimeInterface) {
        return true;
    }

    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1;
};

foreach (array_slice($argv, 2) as $file) {
    $errors = [];
    $text = file_get_contents($file);

    if ($text === false || !preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $text, $m)) {
        echo "FAIL $file : pas de frontmatter\n";
        $exit = 1;
        continue;
    }

    // PARSE_DATETIME: without it symfony/yaml turns a bare date into an integer timestamp.
    $fm = Yaml::parse($m[1], Yaml::PARSE_DATETIME);
    $body = $m[2];
    $type = $fm['type'] ?? '';
    $expectedKeys = $type === 'page' ? $pageKeys : $projectKeys;

    foreach ($expectedKeys as $k) {
        if (!array_key_exists($k, $fm)) {
            $errors[] = "clé manquante : $k";
        }
    }
    foreach (array_keys($fm) as $k) {
        if (!in_array($k, $expectedKeys, true)) {
            $errors[] = "clé inattendue : $k";
        }
    }
    if (!in_array($type, ['project', 'page'], true)) {
        $errors[] = "type doit valoir project ou page";
    }
    if (!preg_match('/^[a-z0-9-]+$/', (string) ($fm['slug'] ?? ''))) {
        $errors[] = "slug invalide";
    }
    if (basename($file, '.md') !== ($fm['slug'] ?? '')) {
        $errors[] = "le nom de fichier doit être le slug";
    }
    if (mb_strlen((string) ($fm['seo_description'] ?? '')) > 160) {
        $errors[] = "seo_description > 160 caractères (" . mb_strlen((string) $fm['seo_description']) . ")";
    }
    if (!$isIsoDate($fm['date'] ?? '')) {
        $errors[] = "date n'est pas une date ISO";
    }
    if (preg_match('/<(script|iframe|div|span|style)\b/i', $body)) {
        $errors[] = "HTML inline interdit";
    }
    $prose = preg_replace('/^```.*?^```[ \t]*$/ms', '', $body) ?? $body;
    if (preg_match('/^#\s/mu', $prose)) {
        $errors[] = "h1 dans le corps : le titre vient du frontmatter, le thème l'affiche";
    }

    if ($type === 'page') {
        if (preg_match('/(\+33|0[1-9](?:[ .]?\d{2}){4}|@[a-z0-9.-]+\.[a-z]{2,})/i', $body)) {
            $errors[] = "donnée de contact détectée (téléphone ou e-mail)";
        }
    } else {
        if (!in_array($fm['status'] ?? '', $statuses, true)) {
            $errors[] = "status hors liste";
        }
        foreach (['period_start', 'period_end'] as $k) {
            if (!$isIsoDate($fm[$k] ?? '')) {
                $errors[] = "$k n'est pas une date ISO";
            }
        }
        foreach (['repo_url', 'demo_url'] as $k) {
            $v = (string) ($fm[$k] ?? '');
            if ($v !== '' && !preg_match('#^https?://#', $v)) {
                $errors[] = "$k doit être http(s) ou vide";
            }
        }
        foreach (['featured', 'is_fil_rouge'] as $k) {
            if (!is_bool($fm[$k] ?? null)) {
                $errors[] = "$k doit être un booléen YAML";
            }
        }
        foreach ((array) ($fm['skills'] ?? []) as $code) {
            if (!isset($codes[$code])) {
                $errors[] = "compétence inconnue : $code";
            }
        }
        foreach ((array) ($fm['tags'] ?? []) as $tag) {
            if (!preg_match('/^[a-z0-9-]+$/', (string) $tag)) {
                $errors[] = "tag non slug : $tag";
            }
        }
        foreach (['problème', 'décisions', 'appris', 'chiffres', 'liens'] as $section) {
            if (!preg_match('/^##\s.*' . $section . '/imu', $body)) {
                $errors[] = "section manquante : $section";
            }
        }
        if (($fm['is_fil_rouge'] ?? false) && !preg_match('/^##\s.*étapes du fil rouge/imu', $body)) {
            $errors[] = "fil rouge sans section de correspondance avec les étapes";
        }
    }

    $words = str_word_count(strip_tags($body));
    if ($errors === []) {
        $extra = $type === 'page' ? 'page' : count((array) $fm['skills']) . ' compétences, ' . count((array) $fm['tags']) . ' tags';
        printf("OK   %s (%d mots, %s)\n", basename($file), $words, $extra);
    } else {
        $exit = 1;
        echo "FAIL " . basename($file) . "\n";
        foreach ($errors as $e) {
            echo "     - $e\n";
        }
    }
}

exit($exit);
