<?php
/**
 * Smoke test of the list with fallback and of the archive rule (ADR-022, lot
 * 5c) against the real WordPress, Polylang and Yoast of the local stack, run
 * through WP-CLI: the functions a template calls, the same list as the REST
 * route, the count of own documents, the robots rule on the project archive
 * and the rendering functions of the child theme as WordPress loads them.
 *
 * From the repository root, with the stack up, the plugin, Polylang and
 * Yoast active and the child theme portfolio-md-child active:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/list-smoke.php --user=<admin login>
 *
 * The script imports two fixture projects (a French one and its German
 * translation, through the import service and the Polylang gateway, as the
 * real doors do) and deletes them for good at the end; it records the
 * publication option and puts it back. The HTTP side (cards, badges and
 * switcher as a visitor sees them) is tools/opening-check.sh.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\ListItem;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Service\SystemClock;
use Voltz\PortfolioMd\Storage\PolylangLanguageGateway;
use Voltz\PortfolioMd\Storage\WordPressContentCatalog;
use Voltz\PortfolioMd\Storage\WordPressContentRepository;
use Voltz\PortfolioMd\Storage\WordPressPublishedLanguagesStore;
use Voltz\PortfolioMd\Storage\WordPressSkillTermGateway;
use Voltz\PortfolioMd\Storage\YoastPublication;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}
if (!function_exists('PLL') || !class_exists('WPSEO_Options')) {
    WP_CLI::error('Polylang and Yoast SEO must be active.');
}
if (get_option('stylesheet') !== 'portfolio-md-child') {
    WP_CLI::error('The child theme portfolio-md-child must be active.');
}

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        WP_CLI::log("PASS  {$label}");

        return;
    }
    $failures++;
    WP_CLI::warning("FAIL  {$label}" . ($detail !== '' ? " ({$detail})" : ''));
};

$ids = static fn (array $items): array => array_map(static fn (ListItem $item): int => $item->postId, $items);
$langs = static fn (array $items): array => array_map(static fn (ListItem $item): string => $item->lang->value, $items);
$fallbacks = static fn (array $items): array => array_map(static fn (ListItem $item): bool => $item->isFallback, $items);
$restList = static function (string $language): array {
    $request = new WP_REST_Request('GET', '/portfolio/v1/projects');
    $request->set_query_params(['language' => $language, 'per_page' => 50]);
    $response = rest_do_request($request);

    return is_array($response->get_data()) ? array_column($response->get_data(), 'id') : [];
};
$archive = static fn (string $postType): object => (object) ['model' => (object) ['object_type' => YoastPublication::POST_TYPE_ARCHIVE, 'object_sub_type' => $postType, 'object_id' => 0]];
$robotsIn = static function (string $language, object $presentation): string {
    $saved = PLL()->curlang;
    PLL()->curlang = PLL()->model->get_language($language);
    try {
        return (string) apply_filters('wpseo_robots', 'index, follow', $presentation);
    } finally {
        PLL()->curlang = $saved;
    }
};

$option = WordPressPublishedLanguagesStore::OPTION;
$before = get_option($option, null);
$published = new PublishedLanguages(new WordPressPublishedLanguagesStore());
$catalog = new WordPressContentCatalog();

$service = new ContentImportService(
    new FrontmatterParser(),
    new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
    new WordPressContentRepository(),
    new WordPressSkillTermGateway(),
    new ConflictResolver(),
    new SystemClock(),
    new PolylangLanguageGateway(),
);
$french = "---\ntitle: \"Projet de fumée (liste)\"\nslug: \"zz-smoke-liste\"\ntype: \"project\"\nlang: \"fr\"\ndate: 2026-11-08\nsubtitle: \"Un sous-titre de fumée\"\nstatus: \"prototype\"\ntags: []\nskills: []\n---\n\nCorps.\n";
$german = "---\ntitle: \"Rauchprojekt (Liste)\"\nslug: \"zz-smoke-liste-de\"\ntype: \"project\"\nlang: \"de\"\ntranslation_key: \"zz-smoke-liste\"\ndate: 2026-11-08\nsubtitle: \"Ein Untertitel\"\nstatus: \"prototype\"\ntags: []\nskills: []\n---\n\nText.\n";
$created = [];

try {
    // 1. The functions a template calls, as the plugin and the theme load them ----------------------------
    $check('the plugin exposes portfolio_md_list_with_fallback() and portfolio_md_published_languages()', function_exists('portfolio_md_list_with_fallback') && function_exists('portfolio_md_published_languages'));
    $check('the child theme exposes its rendering functions', function_exists('portfolio_md_child_render_cards') && function_exists('portfolio_md_child_switcher_items') && function_exists('portfolio_md_child_text'));
    $template = (string) locate_template('archive-project.php');
    $check('archive-project.php of the child theme is the template of the project archive', str_ends_with($template, '/portfolio-md-child/archive-project.php'), $template);

    // 2. Before the fixtures: the French list is the REST list, nothing falls back -----------------------
    $frenchList = portfolio_md_list_with_fallback('project', 'fr');
    $check('the French list holds the published projects, none as a fallback', $frenchList !== [] && !in_array(true, $fallbacks($frenchList), true), count($frenchList) . ' item(s)');
    $check('the French list and GET /projects?language=fr hold the same documents in the same order', $ids($frenchList) === $restList('fr'), json_encode([$ids($frenchList), $restList('fr')]));
    $germanBefore = portfolio_md_list_with_fallback('project', 'de');
    $check('without German project, the German list is the French list served as fallbacks', $ids($germanBefore) === $ids($frenchList) && !in_array(false, $fallbacks($germanBefore), true) && array_unique($langs($germanBefore)) === ['fr']);
    $check('an unknown language reads as French', $ids(portfolio_md_list_with_fallback('project', '')) === $ids($frenchList));
    $check('the count of own German projects is 0 before the fixtures', $catalog->countOwn(ContentType::Project, Language::De) === 0);

    // 3. The fixtures: a French project and its German translation --------------------------------------
    $fr = $service->import($french, new ImportOptions(), 'zz-smoke-liste', null, ContentType::Project, Language::Fr);
    $de = $service->import($german, new ImportOptions(), 'zz-smoke-liste-de', null, ContentType::Project, Language::De);
    foreach ([$fr, $de] as $result) {
        if ($result->postId !== null) {
            $created[] = (int) $result->postId;
        }
    }
    $check('the two fixture projects are imported', $fr->outcome === ImportOutcome::Created && $de->outcome === ImportOutcome::Created && count($created) === 2, $fr->outcome->value . ' / ' . $de->outcome->value . ' ' . $de->detail);
    $frId = (int) $fr->postId;
    $deId = (int) $de->postId;

    // 4. The lists with the fixtures ----------------------------------------------------------------------
    $frenchList = portfolio_md_list_with_fallback('project', 'fr');
    $germanList = portfolio_md_list_with_fallback('project', 'de');
    $englishList = portfolio_md_list_with_fallback('project', 'en');
    $check('the newest French project comes first in the French list', ($ids($frenchList)[0] ?? 0) === $frId);
    $check('a German reader gets the German translation first, not flagged, linking under /de/', ($germanList[0] ?? null) instanceof ListItem && $germanList[0]->postId === $deId && $germanList[0]->lang === Language::De && !$germanList[0]->isFallback && str_contains($germanList[0]->permalink, '/de/projets/zz-smoke-liste-de/'), json_encode(isset($germanList[0]) ? [$germanList[0]->postId, $germanList[0]->lang->value, $germanList[0]->permalink] : null));
    $check('the other German cards are the French documents, flagged, in the French order', array_slice($ids($germanList), 1) === array_slice($ids($frenchList), 1) && array_slice($fallbacks($germanList), 1) === array_fill(0, count($frenchList) - 1, true));
    $check('the German list and GET /projects?language=de hold the same documents in the same order', $ids($germanList) === $restList('de'));
    $check('an English reader gets every project in French, flagged', $ids($englishList) === $ids($frenchList) && !in_array(false, $fallbacks($englishList), true));
    $check('the excerpt of a card is its subtitle', ($germanList[0]->excerpt ?? '') === 'Ein Untertitel' && ($frenchList[0]->excerpt ?? '') === 'Un sous-titre de fumée');
    $check('the count of own documents follows the stored language', $catalog->countOwn(ContentType::Project, Language::De) === 1 && $catalog->countOwn(ContentType::Project, Language::En) === 0 && $catalog->countOwn(ContentType::Project, Language::Fr) === count($frenchList));

    // 5. The theme renders the cards WordPress hands it ----------------------------------------------------
    $html = portfolio_md_child_render_cards($germanList, 'de');
    $check('the German archive renders one card per project, the untranslated ones with the badge', substr_count($html, '<article id="post-') === count($germanList) && substr_count($html, 'class="lang-badge"') === count($germanList) - 1 && substr_count($html, 'is-fallback') === count($germanList) - 1);
    $check('the badge speaks the language of the page', str_contains($html, 'Diese Seite ist nur auf Französisch verfügbar.') && !str_contains($html, 'Cette page'));

    // 6. The robots rule of the archive, with every language open ------------------------------------------
    $published->publish(Language::De);
    $published->publish(Language::En);
    $check('portfolio_md_published_languages() follows the option', portfolio_md_published_languages() === ['fr', 'de', 'en'], json_encode(portfolio_md_published_languages()));
    $check('the German archive is indexable while a German project exists', $robotsIn('de', $archive('project')) === 'index, follow', $robotsIn('de', $archive('project')));
    $check('the English archive, without English project, is noindex, follow', $robotsIn('en', $archive('project')) === YoastPublication::CLOSED_ROBOTS, $robotsIn('en', $archive('project')));
    $check('the French archive keeps its directives', $robotsIn('fr', $archive('project')) === 'index, follow');
    $check('the archive of articles, none translated, is noindex in German', $robotsIn('de', $archive('article')) === YoastPublication::CLOSED_ROBOTS);
    $check('the home of an open language is not an archive: its directives are kept', $robotsIn('de', (object) ['model' => (object) ['object_type' => 'home-page']]) === 'index, follow');
    $published->unpublish(Language::De);
    $check('a closed German is noindex whatever it owns', $robotsIn('de', $archive('project')) === YoastPublication::CLOSED_ROBOTS);
} finally {
    foreach ($created as $id) {
        if (get_post($id) instanceof WP_Post) {
            wp_delete_post($id, true);
        }
    }
    foreach (['zz-smoke-liste', 'zz-smoke-liste-de'] as $slug) {
        foreach (get_posts(['post_type' => 'project', 'post_status' => array_keys(get_post_stati()), 'name' => $slug, 'numberposts' => -1, 'lang' => '']) as $post) {
            wp_delete_post((int) $post->ID, true);
        }
    }
    if ($before === null) {
        delete_option($option);
    } else {
        update_option($option, $before);
    }
}

$check('the fixtures are deleted for good and the German archive is noindex again', $catalog->countOwn(ContentType::Project, Language::De) === 0 && get_posts(['post_type' => 'project', 'post_status' => array_keys(get_post_stati()), 'name' => 'zz-smoke-liste', 'numberposts' => -1, 'lang' => '']) === []);
$check('the option is back to its previous value', get_option($option, null) === $before, json_encode([get_option($option, null), $before]));

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');
