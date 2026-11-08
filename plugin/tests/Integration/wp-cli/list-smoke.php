<?php
/**
 * Smoke test of the list with fallback and of the archive rule (ADR-022, lot
 * 5c) against the real WordPress, Polylang and Yoast of the local stack, run
 * through WP-CLI: the functions a template calls, the same list as the REST
 * route, the count of own documents, the robots rule on the project archive,
 * the document of a post (phase 10) and the rendering functions of the child
 * theme as WordPress loads them.
 *
 * From the repository root, with the stack up, the plugin, Polylang and
 * Yoast active and the child theme portfolio-md-child active:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/list-smoke.php --user=<admin login>
 *
 * The script needs at least one published French project (import the
 * content first). It imports three fixture projects (a French one, its
 * German translation and a German draft without original, through the
 * import service and the Polylang gateway, as the real doors do), deletes
 * them for good at the end, and records the publication option to put it
 * back. The counts it asserts are deltas on what the site holds, so that
 * German or English content of the site does not break it. The HTTP side
 * (cards, badges and switcher as a visitor sees them) is
 * tools/opening-check.sh.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\ListItem;
use Voltz\PortfolioMd\Service\PublishedDocument;
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
$block = static fn (string $path): string => '<url><loc>' . home_url($path) . '</loc><changefreq>weekly</changefreq></url>';
/** The directives the archive rule gives a language that owns $count documents of the kind. */
$robotsFor = static fn (int $count): string => $count === 0 ? YoastPublication::CLOSED_ROBOTS : 'index, follow';
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
$draft = "---\ntitle: \"Entwurf (Liste)\"\nslug: \"zz-smoke-entwurf-de\"\ntype: \"project\"\nlang: \"de\"\ntranslation_key: \"zz-smoke-entwurf\"\ndate: 2026-11-08\nstatus: \"prototype\"\ntags: []\nskills: []\n---\n\nNoch nicht veröffentlicht.\n";
$slugs = ['zz-smoke-liste', 'zz-smoke-liste-de', 'zz-smoke-entwurf-de'];
$created = [];

try {
    // 1. The functions a template calls, as the plugin and the theme load them ----------------------------
    $check('the plugin exposes portfolio_md_list_with_fallback(), portfolio_md_published_languages() and portfolio_md_document()', function_exists('portfolio_md_list_with_fallback') && function_exists('portfolio_md_published_languages') && function_exists('portfolio_md_document'));
    $check('the child theme exposes its rendering functions', function_exists('portfolio_md_child_render_cards') && function_exists('portfolio_md_child_switcher_items') && function_exists('portfolio_md_child_text') && function_exists('portfolio_md_child_render_project_header') && function_exists('portfolio_md_child_render_project_footer'));
    $check('the child theme lays the site out without sidebar', apply_filters('generate_sidebar_layout', 'right-sidebar') === 'no-sidebar');
    $template = (string) locate_template('archive-project.php');
    $check('archive-project.php of the child theme is the template of the project archive', str_ends_with($template, '/portfolio-md-child/archive-project.php'), $template);

    // 2. Before the fixtures: the French list is the REST list, and the baselines of the site -------------
    $frenchList = portfolio_md_list_with_fallback('project', 'fr');
    if ($frenchList === []) {
        WP_CLI::error('No published French project: import the content first (wp portfolio import ...).');
    }
    $check('the French list holds the published projects, none as a fallback', !in_array(true, $fallbacks($frenchList), true), count($frenchList) . ' item(s)');
    $check('the French list and GET /projects?language=fr hold the same documents in the same order', $ids($frenchList) === $restList('fr'), json_encode([$ids($frenchList), $restList('fr')]));
    $check('an unknown language reads as French', $ids(portfolio_md_list_with_fallback('project', '')) === $ids($frenchList));
    $baseline = [];
    foreach (['de' => Language::De, 'en' => Language::En] as $slug => $language) {
        $baseline[$slug] = ['project' => $catalog->countOwn(ContentType::Project, $language), 'article' => $catalog->countOwn(ContentType::Article, $language)];
    }
    WP_CLI::log('      own documents of the site before the fixtures: ' . json_encode($baseline));
    $germanBefore = portfolio_md_list_with_fallback('project', 'de');
    $check('the German list holds the same documents as the French one, each flagged unless German', $ids($germanBefore) !== [] && count($germanBefore) === count($frenchList) && array_filter($germanBefore, static fn (ListItem $item): bool => $item->isFallback !== ($item->lang !== Language::De)) === [], count($germanBefore) . ' item(s)');

    // 3. Every language open, before any fixture: the archive rule follows the count of own documents -----
    $published->publish(Language::De);
    $published->publish(Language::En);
    $check('portfolio_md_published_languages() follows the option', portfolio_md_published_languages() === ['fr', 'de', 'en'], json_encode(portfolio_md_published_languages()));
    $check('the German project archive is indexable only when German owns a project (' . $baseline['de']['project'] . ' before the fixtures)', $robotsIn('de', $archive('project')) === $robotsFor($baseline['de']['project']), $robotsIn('de', $archive('project')));
    $check('the sitemap block of the German archive follows the same count', apply_filters('wpseo_sitemap_project_content', $block('/de/projets/')) === ($baseline['de']['project'] === 0 ? '' : $block('/de/projets/')), apply_filters('wpseo_sitemap_project_content', $block('/de/projets/')));
    $check('the French archive keeps its directives', $robotsIn('fr', $archive('project')) === 'index, follow');
    $check('the home of an open language is not an archive: its directives are kept', $robotsIn('de', (object) ['model' => (object) ['object_type' => 'home-page']]) === 'index, follow');

    // 4. The fixtures: a French project, its German translation and a German draft without original -------
    $fr = $service->import($french, new ImportOptions(), 'zz-smoke-liste', null, ContentType::Project, Language::Fr);
    $de = $service->import($german, new ImportOptions(), 'zz-smoke-liste-de', null, ContentType::Project, Language::De);
    $entwurf = $service->import($draft, new ImportOptions(postStatus: 'draft'), 'zz-smoke-entwurf-de', null, ContentType::Project, Language::De);
    foreach ([$fr, $de, $entwurf] as $result) {
        if ($result->postId !== null) {
            $created[] = (int) $result->postId;
        }
    }
    $check('the three fixture projects are imported', $fr->outcome === ImportOutcome::Created && $de->outcome === ImportOutcome::Created && $entwurf->outcome === ImportOutcome::Created && count($created) === 3, implode(' / ', [$fr->outcome->value, $de->outcome->value . ' ' . $de->detail, $entwurf->outcome->value . ' ' . $entwurf->detail]));
    $frId = (int) $fr->postId;
    $deId = (int) $de->postId;

    // 5. The lists with the fixtures ----------------------------------------------------------------------
    $frenchList = portfolio_md_list_with_fallback('project', 'fr');
    $germanList = portfolio_md_list_with_fallback('project', 'de');
    $englishList = portfolio_md_list_with_fallback('project', 'en');
    $at = array_search($frId, $ids($frenchList), true);
    $check('the French fixture is in the French list, not flagged', $at !== false && !$frenchList[$at]->isFallback, json_encode($ids($frenchList)));
    $at = $at === false ? 0 : $at;
    $expectedGerman = $ids($frenchList);
    $expectedGerman[$at] = $deId;
    $check('a German reader gets the German translation in place of the French one, the other cards being the French documents in the French order', $ids($germanList) === $expectedGerman, json_encode([$ids($germanList), $expectedGerman]));
    $check('the German card is not flagged and links under /de/; a card is flagged exactly when it is not German', isset($germanList[$at]) && $germanList[$at]->lang === Language::De && !$germanList[$at]->isFallback && str_contains($germanList[$at]->permalink, '/de/projets/zz-smoke-liste-de/') && array_filter($germanList, static fn (ListItem $item): bool => $item->isFallback !== ($item->lang !== Language::De)) === [], json_encode(isset($germanList[$at]) ? [$germanList[$at]->postId, $germanList[$at]->lang->value, $germanList[$at]->permalink] : null));
    $check('the German list and GET /projects?language=de hold the same documents in the same order', $ids($germanList) === $restList('de'));
    $check('an English reader gets the same documents, each flagged unless English', $ids($englishList) === $ids($frenchList) && array_filter($englishList, static fn (ListItem $item): bool => $item->isFallback !== ($item->lang !== Language::En)) === []);
    $check('the German draft is in no list', !in_array((int) $entwurf->postId, [...$ids($frenchList), ...$ids($germanList), ...$ids($englishList)], true));
    $check('the excerpt of a card is its subtitle', $germanList[$at]->excerpt === 'Ein Untertitel' && $frenchList[$at]->excerpt === 'Un sous-titre de fumée');
    $frenchDocument = portfolio_md_document($frId);
    $germanDocument = portfolio_md_document($deId);
    $check('portfolio_md_document() reads the French fixture as its own document, with its status', $frenchDocument instanceof PublishedDocument && $frenchDocument->slug === 'zz-smoke-liste' && $frenchDocument->lang === Language::Fr && !$frenchDocument->isFallback && $frenchDocument->status === ProjectStatus::Prototype && $frenchDocument->subtitle === 'Un sous-titre de fumée', $frenchDocument === null ? 'null' : $frenchDocument->slug . ' ' . $frenchDocument->lang->value);
    $check('portfolio_md_document() reads the German translation in German, never the French original in its place', $germanDocument instanceof PublishedDocument && $germanDocument->slug === 'zz-smoke-liste-de' && $germanDocument->lang === Language::De && !$germanDocument->isFallback && ($germanDocument->translations['fr'] ?? '') === 'zz-smoke-liste', $germanDocument === null ? 'null' : json_encode($germanDocument->translations));
    $check('portfolio_md_document() is null for the draft, for an id that is not a post and for a post the plugin does not know', portfolio_md_document((int) $entwurf->postId) === null && portfolio_md_document(0) === null && portfolio_md_document((int) get_option('page_on_front') + 1_000_000) === null);
    $check('the status of a card is the status of its project', $frenchList[$at]->status === ProjectStatus::Prototype && $germanList[$at]->status === ProjectStatus::Prototype);
    $check('the count of own documents follows the stored language and leaves the draft out', $catalog->countOwn(ContentType::Project, Language::De) === $baseline['de']['project'] + 1 && $catalog->countOwn(ContentType::Project, Language::En) === $baseline['en']['project'] && $catalog->countOwn(ContentType::Project, Language::Fr) === count($frenchList), json_encode([$catalog->countOwn(ContentType::Project, Language::De), $baseline['de']['project']]));

    // 6. The theme renders the cards WordPress hands it ----------------------------------------------------
    $html = portfolio_md_child_render_cards($germanList, 'de');
    $flagged = count(array_filter($germanList, static fn (ListItem $item): bool => $item->isFallback));
    $check('the German archive renders one card per project, the untranslated ones with the badge', substr_count($html, '<article id="post-') === count($germanList) && substr_count($html, 'class="lang-badge"') === $flagged && substr_count($html, 'is-fallback') === $flagged && $flagged === count($germanList) - 1 - $baseline['de']['project']);
    $check('the badge speaks the language of the page', str_contains($html, 'Diese Seite ist nur auf Französisch verfügbar.') && !str_contains($html, 'Cette page'));
    $prototypes = count(array_filter($germanList, static fn (ListItem $item): bool => $item->status === ProjectStatus::Prototype));
    $check('the cards wear the status of their project in the language of the page', $prototypes >= 1 && substr_count($html, '<span class="project-status is-prototype">Prototyp</span>') === $prototypes && !str_contains($html, '>Prototype</span>'), "{$prototypes} prototype(s) in the list");
    $header = $germanDocument instanceof PublishedDocument ? portfolio_md_child_render_project_header($germanDocument, 'de') : '';
    $check('the theme renders the header of the page of a project from the document the plugin hands it', str_contains($header, '<div class="project-facts" role="group" aria-label="Projektdaten"><dl class="project-facts-list">') && str_contains($header, 'Prototyp') && str_contains($header, 'Ein Untertitel'), $header);

    // 7. The archive rule once German owns a project --------------------------------------------------------
    $check('the German project archive is indexable now', $robotsIn('de', $archive('project')) === 'index, follow', $robotsIn('de', $archive('project')));
    $check('and its block stays in the project sitemap', apply_filters('wpseo_sitemap_project_content', $block('/de/projets/')) === $block('/de/projets/'));
    $check('the English project archive follows its own count (' . $baseline['en']['project'] . ')', $robotsIn('en', $archive('project')) === $robotsFor($baseline['en']['project']), $robotsIn('en', $archive('project')));
    $check('the German archive of articles follows its own count (' . $baseline['de']['article'] . ')', $robotsIn('de', $archive('article')) === $robotsFor($baseline['de']['article']) && apply_filters('wpseo_sitemap_article_content', $block('/de/articles/')) === ($baseline['de']['article'] === 0 ? '' : $block('/de/articles/')));
    $published->unpublish(Language::De);
    $check('a closed German is noindex whatever it owns, and out of the sitemap', $robotsIn('de', $archive('project')) === YoastPublication::CLOSED_ROBOTS && apply_filters('wpseo_sitemap_project_content', $block('/de/projets/') . $block('/fr/projets/')) === $block('/fr/projets/'));
} finally {
    foreach ($created as $id) {
        if (get_post($id) instanceof WP_Post) {
            wp_delete_post($id, true);
        }
    }
    foreach ($slugs as $slug) {
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

$left = [];
foreach ($slugs as $slug) {
    $left = [...$left, ...get_posts(['post_type' => 'project', 'post_status' => array_keys(get_post_stati()), 'name' => $slug, 'numberposts' => -1, 'lang' => ''])];
}
$check('the fixtures are deleted for good and the count of own German projects is back to its baseline', $left === [] && $catalog->countOwn(ContentType::Project, Language::De) === ($baseline['de']['project'] ?? 0), json_encode([count($left), $catalog->countOwn(ContentType::Project, Language::De)]));
$check('the option is back to its previous value', get_option($option, null) === $before, json_encode([get_option($option, null), $before]));

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');
