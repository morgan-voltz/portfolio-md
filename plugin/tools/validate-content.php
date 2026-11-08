<?php

/**
 * Validates the content tree against the conventions of content/README.md.
 *
 * The rules live in Voltz\PortfolioMd\Validation\ContentValidator, which
 * reads its lists (keys, kinds, languages, statuses, slug shape, SEO length)
 * from the plugin itself: this script only reads the arguments, prints the
 * report and sets the exit code. The grid goes through the plugin's own
 * loader first (Parsing\SkillsGridLoader), so that a grid the plugin would
 * refuse (a missing translation, an unknown key, lot 4 of ADR-020) fails
 * here too, before any document is checked.
 *
 * From the repository root, without PHP on the host:
 *
 *   podman run --rm -v "$PWD:/repo:z" -w /repo docker.io/library/php:8.3-cli \
 *       php plugin/tools/validate-content.php content/skills.yaml content
 *
 * Exit code 1 when any file or the tree fails, 2 on a wrong invocation.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Voltz\PortfolioMd\Domain\InvalidSkillsGridException;
use Voltz\PortfolioMd\Domain\Skill;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\Validation\ContentValidator;

if ($argc !== 3) {
    fwrite(STDERR, "usage: validate-content.php <skills.yaml> <content-dir>\n");
    exit(2);
}

if (!is_file($argv[1])) {
    fwrite(STDERR, "grille introuvable : {$argv[1]}\n");
    exit(2);
}
if (!is_dir($argv[2])) {
    fwrite(STDERR, "dossier de contenu introuvable : {$argv[2]}\n");
    exit(2);
}

try {
    $grid = (new SkillsGridLoader())->load($argv[1]);
} catch (InvalidSkillsGridException $exception) {
    echo "FAIL {$argv[1]}\n     - {$exception->getMessage()}\n";
    exit(1);
}
echo "OK   {$argv[1]} (" . count($grid->referentials) . ' référentiels, ' . $grid->countSkills() . " compétences, intitulés en fr, de, en)\n";

$report = (new ContentValidator(array_map(static fn (Skill $skill): string => $skill->code, $grid->allSkills())))->validate($argv[2]);

$files = 0;
$failed = 0;
foreach ($report->paths() as $path) {
    $isTree = $path === ContentValidator::TREE;
    if (isset($report->errors[$path])) {
        echo($isTree ? "FAIL (arbre)\n" : "FAIL {$path}\n");
        foreach ($report->errors[$path] as $error) {
            echo "     - {$error}\n";
        }
        $failed += $isTree ? 0 : 1;
    } else {
        echo "OK   {$path} ({$report->notes[$path]})\n";
    }
    $files += $isTree ? 0 : 1;
}
$tree = count($report->errors[ContentValidator::TREE] ?? []);
printf("%d fichier(s), %d en échec%s\n", $files, $failed, $tree > 0 ? ", {$tree} erreur(s) d'arbre" : '');

exit($report->hasErrors() ? 1 : 0);
