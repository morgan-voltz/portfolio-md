<?php
/**
 * Fixtures of the opening rehearsal (ADR-022, lot 5e): one French project
 * and its German and English translations, imported through the import
 * service and the Polylang gateway as the real doors do, so that the HTTP
 * rehearsal (tools/opening-rehearsal.sh) can observe a translated sheet in
 * each language next to the untranslated ones. `cleanup` deletes them for
 * good. The site has no translation yet (lot 6 is Morgan's), hence fixtures.
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/rehearsal-fixtures.php setup --user=<admin login>
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/rehearsal-fixtures.php cleanup --user=<admin login>
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\SystemClock;
use Voltz\PortfolioMd\Storage\PolylangLanguageGateway;
use Voltz\PortfolioMd\Storage\WordPressContentRepository;
use Voltz\PortfolioMd\Storage\WordPressSkillTermGateway;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}
if (!function_exists('PLL')) {
    WP_CLI::error('Polylang must be active.');
}

$mode = $args[0] ?? 'setup';
$slugs = ['zz-rehearsal-project', 'zz-rehearsal-project-de', 'zz-rehearsal-project-en'];

if ($mode === 'cleanup') {
    $deleted = 0;
    foreach ($slugs as $slug) {
        foreach (get_posts(['post_type' => 'project', 'post_status' => array_keys(get_post_stati()), 'name' => $slug, 'numberposts' => -1, 'lang' => '']) as $post) {
            wp_delete_post((int) $post->ID, true);
            $deleted++;
        }
    }
    WP_CLI::success("cleanup: {$deleted} rehearsal post(s) deleted for good.");

    return;
}
if ($mode !== 'setup') {
    WP_CLI::error("Unknown mode \"{$mode}\": setup or cleanup.");
}

$service = new ContentImportService(
    new FrontmatterParser(),
    new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
    new WordPressContentRepository(),
    new WordPressSkillTermGateway(),
    new ConflictResolver(),
    new SystemClock(),
    new PolylangLanguageGateway(),
);
$documents = [
    ['zz-rehearsal-project', Language::Fr, "---\ntitle: \"Projet de répétition\"\nslug: \"zz-rehearsal-project\"\ntype: \"project\"\nlang: \"fr\"\ndate: 2026-11-08\nsubtitle: \"Une fiche traduite dans les trois langues, pour la répétition de l'ouverture\"\nseo_description: \"Fiche de répétition de l'ouverture des langues : une version française, allemande et anglaise.\"\nstatus: \"prototype\"\ntags: []\nskills: []\n---\n\nLe problème : répéter l'ouverture d'une langue avec une fiche traduite.\n"],
    ['zz-rehearsal-project-de', Language::De, "---\ntitle: \"Probeprojekt\"\nslug: \"zz-rehearsal-project-de\"\ntype: \"project\"\nlang: \"de\"\ntranslation_key: \"zz-rehearsal-project\"\ndate: 2026-11-08\nsubtitle: \"Ein in drei Sprachen übersetztes Blatt für die Probe der Öffnung\"\nseo_description: \"Probeblatt für die Öffnung der Sprachen: eine französische, deutsche und englische Fassung.\"\nstatus: \"prototype\"\ntags: []\nskills: []\n---\n\nDas Problem: die Öffnung einer Sprache mit einem übersetzten Blatt proben.\n"],
    ['zz-rehearsal-project-en', Language::En, "---\ntitle: \"Rehearsal project\"\nslug: \"zz-rehearsal-project-en\"\ntype: \"project\"\nlang: \"en\"\ntranslation_key: \"zz-rehearsal-project\"\ndate: 2026-11-08\nsubtitle: \"A sheet translated in the three languages, for the rehearsal of the opening\"\nseo_description: \"Rehearsal sheet of the opening of the languages: a French, a German and an English version.\"\nstatus: \"prototype\"\ntags: []\nskills: []\n---\n\nThe problem: rehearse the opening of a language with a translated sheet.\n"],
];
foreach ($documents as [$slug, $language, $markdown]) {
    $result = $service->import($markdown, new ImportOptions(), $slug, null, ContentType::Project, $language);
    if (!in_array($result->outcome, [ImportOutcome::Created, ImportOutcome::Updated, ImportOutcome::Unchanged], true)) {
        WP_CLI::error("fixture {$slug}: {$result->outcome->value} {$result->detail}");
    }
    WP_CLI::log(sprintf('%-9s project/%s (post %d, %s)', strtoupper($result->outcome->value), $slug, (int) $result->postId, $language->value));
}
$french = get_posts(['post_type' => 'project', 'name' => 'zz-rehearsal-project', 'numberposts' => 1, 'fields' => 'ids', 'lang' => '']);
$group = $french === [] ? [] : pll_get_post_translations((int) $french[0]);
ksort($group);
WP_CLI::success('setup: the rehearsal project and its two translations are imported; Polylang group ' . json_encode($group) . '.');
