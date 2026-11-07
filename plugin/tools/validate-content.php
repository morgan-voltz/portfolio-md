<?php

/**
 * Validates the content tree against the conventions of content/README.md.
 *
 * The rules live in Voltz\PortfolioMd\Validation\ContentValidator, which
 * reads its lists (keys, kinds, languages, statuses, slug shape, SEO length)
 * from the plugin itself: this script only reads the arguments, prints the
 * report and sets the exit code.
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

use Symfony\Component\Yaml\Yaml;
use Voltz\PortfolioMd\Validation\ContentValidator;
use Voltz\PortfolioMd\Validation\ValidationReport;

if ($argc !== 3) {
    fwrite(STDERR, "usage: validate-content.php <skills.yaml> <content-dir>\n");
    exit(2);
}

$grid = Yaml::parseFile($argv[1]);
$report = (new ContentValidator(ContentValidator::skillCodes(is_array($grid) ? $grid : [])))->validate($argv[2]);

echo $report->layout === ValidationReport::LANGUAGES
    ? "disposition : par langue (content/<lang>/<dossier>/)\n"
    : "disposition : avant migration (content/<dossier>/)\n";

$files = 0;
$failed = 0;
foreach ($report->paths() as $path) {
    if (isset($report->errors[$path])) {
        $failed++;
        echo "FAIL {$path}\n";
        foreach ($report->errors[$path] as $error) {
            echo "     - {$error}\n";
        }
    } else {
        echo "OK   {$path} ({$report->notes[$path]})\n";
    }
    if ($path !== ContentValidator::TREE) {
        $files++;
    }
}
printf("%d fichier(s), %d en échec\n", $files, $failed);

exit($report->hasErrors() ? 1 : 0);
