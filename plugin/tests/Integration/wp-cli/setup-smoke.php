<?php
/**
 * Smoke test of the site configuration script (ADR-022, point 12, lot 5d)
 * against the real stack: tools/site-setup.php is run in a second `wp`
 * process, its effects are read back (static front page, redirect_lang, the
 * French menu and its items, the theme location, Polylang's nav_menus), and
 * a second run changes nothing. The script applies the wanted state of the
 * site, so nothing is restored: that state is what the stack should hold.
 *
 * From the repository root, with the stack up, the plugin, Polylang and
 * Yoast active, the child theme active and content/fr/pages imported:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/setup-smoke.php --user=<admin login>
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Domain\CorePages;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;

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
$pageOf = static function (string $key, Language $language): ?int {
    $found = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'name' => CorePages::slug($key, $language), 'numberposts' => 1, 'fields' => 'ids', 'lang' => '', 'meta_key' => InternalMetaRegistrar::LANG, 'meta_value' => $language->value]);

    return $found === [] ? null : (int) $found[0];
};
// The script runs in another process: its writes land in the database, not in this
// process's caches, which are flushed before anything is read back.
$run = static function (): object {
    $result = WP_CLI::runcommand('eval-file wp-content/plugins/portfolio-md/tools/site-setup.php', ['return' => 'all', 'exit_error' => false, 'launch' => true]);
    wp_cache_flush();

    return $result;
};
$polylangOption = static fn (): array => (array) get_option('polylang');
$stylesheet = (string) get_option('stylesheet');

$frenchHome = $pageOf(CorePages::HOME, Language::Fr);
if ($frenchHome === null) {
    WP_CLI::error('No published French page "accueil": import content/fr/pages first (wp portfolio import /srv/portfolio-content/fr/pages --user=...).');
}
$frenchPages = array_values(array_filter(array_map(static fn (string $key): ?int => $pageOf($key, Language::Fr), [CorePages::ABOUT, CorePages::CV, CorePages::CONTACT])));
$expectedItems = 2 + count($frenchPages);
$menuBefore = wp_get_nav_menu_object('Menu FR');
/** What a run may not change on a second run: everything the script writes. */
$snapshot = static function () use ($stylesheet): array {
    $menu = wp_get_nav_menu_object('Menu FR');
    $items = $menu === false ? [] : (array) wp_get_nav_menu_items((int) $menu->term_id, ['post_status' => 'publish,draft']);
    $polylang = (array) get_option('polylang');

    return [
        'menus' => count(wp_get_nav_menus()),
        'items' => array_map(static fn ($item): int => (int) $item->ID, $items),
        'mods' => get_option("theme_mods_{$stylesheet}"),
        'nav_menus' => $polylang['nav_menus'] ?? null,
        'redirect_lang' => $polylang['redirect_lang'] ?? null,
        'show_on_front' => get_option('show_on_front'),
        'page_on_front' => get_option('page_on_front'),
    ];
};

// 1. The first run ----------------------------------------------------------------------------------------
$first = $run();
$out = $first->stdout . "\n" . $first->stderr;
$check('site-setup.php runs through', $first->return_code === 0, trim($first->stderr));
$check('the theme and the option are reported', str_contains($out, 'theme: portfolio-md-child is active') && str_contains($out, 'portfolio_md_published_languages'));
$check('the static front page is the French page accueil', get_option('show_on_front') === 'page' && (int) get_option('page_on_front') === $frenchHome, json_encode([get_option('show_on_front'), get_option('page_on_front'), $frenchHome]));
$check('redirect_lang is on', ($polylangOption()['redirect_lang'] ?? false) === true, var_export($polylangOption()['redirect_lang'] ?? null, true));
$menu = wp_get_nav_menu_object('Menu FR');
$menuId = $menu === false ? 0 : (int) $menu->term_id;
$items = $menuId > 0 ? wp_get_nav_menu_items($menuId) : [];
$items = is_array($items) ? $items : [];
if ($menuBefore === false) {
    $check("Menu FR is created and built with its {$expectedItems} items (home, projects, then the pages that exist)", $menuId > 0 && str_contains($out, 'menu Menu FR: created') && str_contains($out, "menu Menu FR: built with {$expectedItems} item(s)") && count($items) === $expectedItems, json_encode([$menuId, count($items)]));
    $titles = array_map(static fn ($item): string => (string) $item->title, $items);
    $check('the French menu starts with Accueil and Projets', ($titles[0] ?? '') === 'Accueil' && ($titles[1] ?? '') === 'Projets', json_encode($titles));
    $targets = array_map(static fn ($item): int => (int) $item->object_id, array_values(array_filter($items, static fn ($item): bool => $item->type === 'post_type')));
    $check('its page items point to the French home, About, CV and Contact pages, in that order', $targets === [$frenchHome, ...$frenchPages], json_encode([$targets, [$frenchHome, ...$frenchPages]]));
    $projects = array_values(array_filter($items, static fn ($item): bool => $item->type === 'custom'));
    $check('Projets is a root-relative link to the French archive, with no host baked in', count($projects) === 1 && (string) $projects[0]->url === '/fr/projets/', json_encode(array_map(static fn ($i): string => (string) $i->url, $projects)));
} else {
    WP_CLI::log('      (Menu FR was already there: the build path is not exercised in this run; delete the menu to replay it)');
    $check('Menu FR, already there, is left as is', $menuId === (int) $menuBefore->term_id && str_contains($out, 'menu Menu FR: already holds') && count($items) >= 2, json_encode([$menuId, count($items)]));
}
$mods = get_option("theme_mods_{$stylesheet}");
$check('the primary location of the theme holds Menu FR, in the raw theme_mods option', is_array($mods) && (int) ($mods['nav_menu_locations']['primary'] ?? 0) === $menuId, json_encode($mods['nav_menu_locations'] ?? null));
$navMenus = $polylangOption()['nav_menus'] ?? null;
$check("Polylang's nav_menus binds Menu FR to primary under {$stylesheet}", is_array($navMenus) && (int) ($navMenus[$stylesheet]['primary']['fr'] ?? 0) === $menuId, json_encode($navMenus));
foreach (['de', 'en'] as $slug) {
    $hasHome = $pageOf(CorePages::HOME, Language::from($slug)) !== null;
    $check($hasHome ? "{$slug} owns its home page: a menu is bound" : "{$slug} has no home page: skipped and unbound", $hasHome ? (int) ($navMenus[$stylesheet]['primary'][$slug] ?? 0) > 0 : (str_contains($out, "skip {$slug}: no home page") && !isset($navMenus[$stylesheet]['primary'][$slug])));
}
$check('the taxonomy archives are noindex in Yoast', WPSEO_Options::get('noindex-tax-tech_tag') === true && WPSEO_Options::get('noindex-tax-project_status') === true && WPSEO_Options::get('noindex-tax-skill') === true);

// 2. The second run ---------------------------------------------------------------------------------------
$afterFirst = $snapshot();
$second = $run();
$out2 = $second->stdout . "\n" . $second->stderr;
$check('a second run reports nothing to change', $second->return_code === 0 && str_contains($out2, 'Nothing to change'), trim($out2));
$check('and leaves every setting as the first run left it (menus, items, theme location, nav_menus, redirect_lang, front page)', $snapshot() === $afterFirst, json_encode([$afterFirst, $snapshot()]));
$check('exactly one menu is named Menu FR', count(array_filter(wp_get_nav_menus(), static fn ($m): bool => $m->name === 'Menu FR')) === 1);

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');
