<?php

/**
 * Smoke test of the Polylang bridge (ADR-020, lot 2) against the real
 * Polylang of the local stack, run through WP-CLI: the import pushes the
 * language of a post and ties the translations of a key through
 * Storage\PolylangLanguageGateway, the reconciliation puts back what
 * Polylang's own screens may have changed, and every query of the plugin
 * sees every language whatever language Polylang filters on.
 *
 * From the repository root, with the stack up and Polylang active with the
 * languages fr, de and en (plugin/tools/polylang-setup-languages.php):
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/i18n-smoke.php --user=<admin login>
 *
 * `--user` is required: posts need an author. The script creates two pages
 * (zz-smoke-i18n, zz-smoke-i18n-de) and deletes them for good at the end,
 * trash included. The reconciliation runs on the whole site: it writes
 * nothing on a post that did not drift, and the checks bear on the two
 * smoke posts only. Polylang's languages and options are never written.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Service\CatalogQuery;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\ReconcileReport;
use Voltz\PortfolioMd\Service\SystemClock;
use Voltz\PortfolioMd\Service\TranslationReconciler;
use Voltz\PortfolioMd\Storage\PolylangLanguageGateway;
use Voltz\PortfolioMd\Storage\WordPressContentCatalog;
use Voltz\PortfolioMd\Storage\WordPressContentRepository;
use Voltz\PortfolioMd\Storage\WordPressSkillTermGateway;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}

if (get_current_user_id() === 0) {
    WP_CLI::error('Run this script with --user=<admin login>: posts need an author.');
}

if (!function_exists('pll_languages_list')) {
    WP_CLI::error('Polylang is not active: this script needs the real Polylang.');
}
$languages = pll_languages_list();
if (array_diff(['fr', 'de', 'en'], $languages) !== []) {
    WP_CLI::error('Polylang must hold the languages fr, de and en (found: ' . implode(', ', $languages) . '); run plugin/tools/polylang-setup-languages.php first.');
}
if (!pll_is_translated_post_type('page')) {
    WP_CLI::error('Polylang does not translate pages, which this script uses.');
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

$repository = new WordPressContentRepository();
$gateway = new PolylangLanguageGateway();
$service = new ContentImportService(
    new FrontmatterParser(),
    new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
    $repository,
    new WordPressSkillTermGateway(),
    new ConflictResolver(),
    new SystemClock(),
    $gateway,
);
$reconciler = new TranslationReconciler($repository, $repository, $gateway, $gateway);

$french = "---\ntitle: \"Page de fumée (i18n)\"\nslug: \"zz-smoke-i18n\"\ntype: \"page\"\nlang: \"fr\"\ndate: 2026-11-07\n---\n\nCorps.\n";
$german = "---\ntitle: \"Rauchseite (i18n)\"\nslug: \"zz-smoke-i18n-de\"\ntype: \"page\"\nlang: \"de\"\ntranslation_key: \"zz-smoke-i18n\"\ndate: 2026-11-07\n---\n\nText, siehe [die Seite](../pages/zz-smoke-i18n.md).\n";

$ids = [];
/** The differences of a report that concern the smoke posts only: the reconciliation runs on the whole site. */
$mine = static function (ReconcileReport $report) use (&$ids): array {
    return array_values(array_filter($report->differences, static fn (array $d): bool => in_array($d['postId'], $ids, true)));
};
/** The group as Polylang shows it from a post, sorted by language. */
$view = static function (int $postId): array {
    $view = array_map('intval', pll_get_post_translations($postId));
    ksort($view);

    return $view;
};
$describe = static fn (array $group): string => json_encode($group) ?: '?';

try {
    // 0. The command is wired and the gateway answers --------------------------------------------
    $run = WP_CLI::runcommand('portfolio translations reconcile --dry-run', ['return' => 'all', 'exit_error' => false, 'launch' => true]);
    $check('wp portfolio translations reconcile --dry-run runs through the Polylang gateway', str_contains($run->stdout . $run->stderr, 'post(s) checked'), trim($run->stderr . "\n" . $run->stdout));

    // 1. A French page and its German translation, through the import --------------------------------
    $fr = $service->import($french, new ImportOptions(), 'zz-smoke-i18n', null, ContentType::Page, Language::Fr);
    $frId = (int) $fr->postId;
    $ids[] = $frId;
    $check('the French page is created and Polylang says fr', $fr->outcome === ImportOutcome::Created && pll_get_post_language($frId) === 'fr', var_export(pll_get_post_language($frId), true));

    $de = $service->import($german, new ImportOptions(), 'zz-smoke-i18n-de', null, ContentType::Page, Language::De);
    $deId = (int) $de->postId;
    $ids[] = $deId;
    $check('the German page is created and Polylang says de, not the default it gave on insert', $de->outcome === ImportOutcome::Created && pll_get_post_language($deId) === 'de', var_export(pll_get_post_language($deId), true));
    $expected = ['de' => $deId, 'fr' => $frId];
    $check('both members see the group {fr, de}', $view($frId) === $expected && $view($deId) === $expected, $describe($view($frId)) . ' / ' . $describe($view($deId)));
    $check('Polylang finds the German page from the French one', (int) pll_get_post($frId, 'de') === $deId);
    $check('the German page links the French one in the German URL tree', str_contains((string) get_post($deId)->post_content, 'href="/de/zz-smoke-i18n/"'), (string) get_post($deId)->post_content);

    // 2. A replay changes nothing and keeps the group ------------------------------------------------
    $again = $service->import($german, new ImportOptions(), 'zz-smoke-i18n-de', null, ContentType::Page, Language::De);
    $check('a replay is unchanged and the group stays', $again->outcome === ImportOutcome::Unchanged && $view($frId) === $expected && $view($deId) === $expected);
    $check('the reconciliation finds nothing on the smoke posts', $mine($reconciler->reconcile(true)) === []);

    // 3. Language drift: quick edit put the German page in French -------------------------------------
    pll_set_post_language($deId, 'fr');
    $dry = $reconciler->reconcile(true);
    $drift = $mine($dry);
    $check('a dry run reports the language drift and writes nothing', count($drift) >= 1 && $drift[0]['kind'] === 'language' && $drift[0]['postId'] === $deId && !$drift[0]['fixed'] && pll_get_post_language($deId) === 'fr', $describe($drift));
    $real = $reconciler->reconcile(false);
    $check('a real run repairs the language', pll_get_post_language($deId) === 'de' && in_array(true, array_column($mine($real), 'fixed'), true), $describe($mine($real)));
    $check('a second run finds nothing on the smoke posts', $mine($reconciler->reconcile(true)) === []);
    $check('the group survived the repair', $view($frId) === $expected && $view($deId) === $expected, $describe($view($frId)) . ' / ' . $describe($view($deId)));

    // 4. Group drift: the German page is taken out of the group ----------------------------------------
    pll_save_post_translations(['fr' => $frId]);
    $check('the group is broken on purpose, as seen from the French page', $gateway->translationsOf($frId) === [], $describe($view($frId)));
    $real = $reconciler->reconcile(false);
    $kinds = array_column($mine($real), 'kind');
    $check('the reconciliation reports the group and links it again', in_array('group', $kinds, true) && $view($frId) === $expected && $view($deId) === $expected, $describe($mine($real)));

    // 5. The translation is retired: the French page is alone under its key but still grouped ----------
    wp_trash_post($deId);
    $unchanged = $service->import($french, new ImportOptions(), 'zz-smoke-i18n', null, ContentType::Page, Language::Fr);
    $check('an unchanged import of the survivor does not untie the stale group', $unchanged->outcome === ImportOutcome::Unchanged && $gateway->translationsOf($frId) !== [], $describe($view($frId)));
    $real = $reconciler->reconcile(false);
    $check('the reconciliation dissolves the stale group of the survivor', in_array('stale-group', array_column($mine($real), 'kind'), true) && $gateway->translationsOf($frId) === [], $describe($mine($real)) . ' view ' . $describe($view($frId)));
    $check('the retired member is detached and sees only itself', count(pll_get_post_translations($deId)) <= 1, $describe($view($deId)));

    // 6. Every query of the plugin sees every language -------------------------------------------------
    wp_untrash_post($deId);
    wp_publish_post($deId); // a post restored from the trash is a draft since WordPress 5.6: the catalog wants it published
    $savedLanguage = PLL()->curlang;
    try {
        PLL()->curlang = PLL()->model->get_language('de');
        $control = new WP_Query(['post_type' => 'page', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']);
        $controlIds = array_map('intval', $control->posts);
        $filtered = in_array($deId, $controlIds, true) && !in_array($frId, $controlIds, true);
        $check('control: Polylang filters a plain query by the forced language (otherwise this proof is void)', $filtered, implode(',', $controlIds));
        if ($filtered) {
            $check('findBySlug still finds the French page', $repository->findBySlug(ContentType::Page, 'zz-smoke-i18n')?->id === $frId);
            $check('findByKey still sees both languages', count($repository->findByKey(ContentType::Page, 'zz-smoke-i18n')) === 2);
            $catalog = new WordPressContentCatalog();
            $check('the public catalog still serves the French page', $catalog->find(ContentType::Page, 'zz-smoke-i18n', Language::Fr)?->slug === 'zz-smoke-i18n');
            $german = $catalog->find(ContentType::Page, 'zz-smoke-i18n', Language::De);
            $check('and the German page for the same key when asked in German, under its own /de/ link (ADR-021)', $german?->slug === 'zz-smoke-i18n-de' && $german->isFallback === false && str_contains($german->link, '/de/'), (string) $german?->link);
            $listed = array_map(static fn ($document) => $document->slug, $catalog->list(ContentType::Page, new CatalogQuery(perPage: 50))->items);
            $check('the public list still holds the French page', in_array('zz-smoke-i18n', $listed, true), implode(',', $listed));
            $listedInGerman = array_map(static fn ($document) => $document->slug, $catalog->list(ContentType::Page, new CatalogQuery(perPage: 50, language: Language::De))->items);
            $check('the German list holds the German page in its place', in_array('zz-smoke-i18n-de', $listedInGerman, true) && !in_array('zz-smoke-i18n', $listedInGerman, true), implode(',', $listedInGerman));
            $frenchLink = (string) $catalog->find(ContentType::Page, 'zz-smoke-i18n', Language::Fr)?->link;
            $check('the link follows the language of the document, not the forced one: the French page keeps /fr/', str_contains($frenchLink, '/fr/') && !str_contains($frenchLink, '/de/'), $frenchLink);
            $check('the sitemap still holds it', in_array('zz-smoke-i18n', array_map(static fn ($entry) => $entry->slug, $catalog->sitemap()), true));
            $check('the document lister sees both pages', count(array_filter($repository->listDocuments(ContentType::Page), static fn ($d) => in_array($d->id, $ids, true))) === 2);
        }
    } finally {
        PLL()->curlang = $savedLanguage;
    }
    $germanLink = (string) (new WordPressContentCatalog())->find(ContentType::Page, 'zz-smoke-i18n', Language::De)?->link;
    $check('and the German page keeps /de/ once the current language is back to normal', str_contains($germanLink, '/de/'), $germanLink);
} finally {
    foreach (['zz-smoke-i18n', 'zz-smoke-i18n-de'] as $slug) {
        foreach (get_posts(['post_type' => 'page', 'post_status' => array_keys(get_post_stati()), 'name' => $slug, 'numberposts' => -1, 'lang' => '']) as $post) {
            wp_delete_post((int) $post->ID, true);
        }
        foreach (get_posts(['post_type' => 'page', 'post_status' => 'trash', 'meta_key' => '_wp_desired_post_slug', 'meta_value' => $slug, 'numberposts' => -1, 'lang' => '']) as $post) {
            wp_delete_post((int) $post->ID, true);
        }
    }
    foreach ($ids as $id) {
        if (get_post($id) instanceof WP_Post) {
            wp_delete_post($id, true);
        }
    }
}

$left = get_posts(['post_type' => 'page', 'post_status' => array_keys(get_post_stati()), 'name' => 'zz-smoke-i18n', 'numberposts' => -1, 'lang' => '']);
$check('the smoke posts are deleted for good', $left === [] && get_post($deId ?? 0) === null);

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');
