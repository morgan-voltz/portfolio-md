<?php

/**
 * Spike of lot 5a (ADR-022): what a real WordPress answers to the mechanics
 * the opening of a language relies on, before the plugin code is written.
 * Run through WP-CLI; the HTTP observations are made by
 * tools/opening-spike.sh, which needs the state this script leaves behind.
 *
 * From the repository root, with the stack up, the plugin, Polylang and
 * Yoast active, the child theme portfolio-md-child active and the real
 * content imported (the sheet `momocad` and the page `a-propos` exist):
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/opening-spike.php --user=<admin login>
 *   podman-compose --profile tools run --rm wpcli wp rewrite flush --hard
 *   plugin/tools/opening-spike.sh http://localhost:8000
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/opening-spike.php cleanup --user=<admin login>
 *   podman-compose --profile tools run --rm wpcli wp rewrite flush --hard
 *
 * What it sets up, all of it reverted by `cleanup` (which also restores the
 * previous reading settings, redirect_lang, menu locations and Polylang's
 * nav_menus option):
 *   - a French home page `zz-spike-accueil` with its German translation
 *     `zz-spike-startseite`, linked in Polylang, as the static front page;
 *     `redirect_lang` set to true (ADR-022, point 11, the case to prove);
 *   - a French project `zz-spike-projekt` with its German twin
 *     `zz-spike-projekt-de` (Polylang language and group only: these posts
 *     carry no document, so the plugin's REST catalog ignores them on
 *     purpose); a Yoast meta description written by PHP on the French one;
 *   - two menus, French and German, bound to the `primary` location through
 *     the theme_mod and Polylang's nav_menus option under the child theme;
 *   - a must-use plugin `zz-opening-spike.php` that plays the filters of
 *     lots 5b and 5c for a closed language (browser preference, cookie,
 *     translation URLs, x-default, robots, sitemap entries and archive
 *     blocks, a PHP switcher on generate_menu_bar_items), the closed
 *     languages being the option `zz_spike_closed` (de and en at first) so
 *     that the shell script can open one and observe the flip.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}
if (!function_exists('PLL') || !function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
    WP_CLI::error('Polylang is not active.');
}

$stateKey = 'zz_opening_spike';
$muFile = WPMU_PLUGIN_DIR . '/zz-opening-spike.php';
$state = get_option($stateKey, null);

$cleanup = static function (array $state) use ($stateKey, $muFile): void {
    foreach ($state['posts'] ?? [] as $postId) {
        wp_delete_post((int) $postId, true);
    }
    foreach ($state['menus'] ?? [] as $menuId) {
        wp_delete_nav_menu((int) $menuId);
    }
    $previous = $state['previous'] ?? [];
    update_option('show_on_front', $previous['show_on_front'] ?? 'posts');
    update_option('page_on_front', (int) ($previous['page_on_front'] ?? 0));
    // The raw theme_mods option, not get_theme_mod(): Polylang filters the latter and answers
    // ['primary' => 0] even when the option holds no menu location at all.
    $modsKey = 'theme_mods_' . ($previous['stylesheet'] ?? get_option('stylesheet'));
    $mods = get_option($modsKey);
    $mods = is_array($mods) ? $mods : [];
    if (array_key_exists('nav_menu_locations', $previous) && $previous['nav_menu_locations'] !== false) {
        $mods['nav_menu_locations'] = $previous['nav_menu_locations'];
    } else {
        unset($mods['nav_menu_locations']);
    }
    update_option($modsKey, $mods);
    PLL()->options->set('redirect_lang', (bool) ($previous['redirect_lang'] ?? false));
    PLL()->options->set('nav_menus', is_array($previous['nav_menus'] ?? null) ? $previous['nav_menus'] : []);
    PLL()->options->save();
    delete_option('zz_spike_closed');
    if (is_file($muFile)) {
        unlink($muFile);
    }
    if (is_dir(WPMU_PLUGIN_DIR) && count((array) scandir(WPMU_PLUGIN_DIR)) === 2) {
        rmdir(WPMU_PLUGIN_DIR);
    }
    PLL()->model->clean_languages_cache();
    if (class_exists('WPSEO_Sitemaps_Cache')) {
        WPSEO_Sitemaps_Cache::clear();
    }
    flush_rewrite_rules();
    delete_option($stateKey);
};

if (in_array('cleanup', $args ?? [], true)) {
    if (!is_array($state)) {
        WP_CLI::success('cleanup: nothing to clean.');
        return;
    }
    $cleanup($state);
    WP_CLI::success('cleanup: spike posts, menus, option, must-use plugin removed; reading settings, redirect_lang, menu locations and nav_menus restored.');
    return;
}
if (is_array($state)) {
    WP_CLI::log('leftovers of a previous run: cleaning first');
    $cleanup($state);
}

$author = get_current_user_id();
if ($author === 0) {
    WP_CLI::error('Run with --user=<admin login>: the spike posts need an author.');
}

$stylesheet = (string) get_option('stylesheet');
$rawMods = get_option('theme_mods_' . $stylesheet);
$previous = [
    'stylesheet' => $stylesheet,
    'show_on_front' => get_option('show_on_front'),
    'page_on_front' => (int) get_option('page_on_front'),
    'redirect_lang' => (bool) PLL()->options['redirect_lang'],
    // The raw option: get_theme_mod() is filtered by Polylang (see cleanup).
    'nav_menu_locations' => is_array($rawMods) && array_key_exists('nav_menu_locations', $rawMods) ? $rawMods['nav_menu_locations'] : false,
    'nav_menus' => PLL()->options['nav_menus'],
];

// The state is recorded before the first write and completed as the run goes, so that a
// run that fails halfway still leaves `cleanup` everything it needs.
$state = ['posts' => [], 'menus' => [], 'previous' => $previous];
update_option($stateKey, $state, false);
$track = static function (string $kind, int $id) use (&$state, $stateKey): void {
    $state[$kind][] = $id;
    update_option($stateKey, $state, false);
};

$insert = static function (string $type, string $slug, string $title, string $lang, string $content) use ($author, $track): int {
    if (get_page_by_path($slug, OBJECT, $type) instanceof WP_Post) {
        WP_CLI::error("a {$type} with the slug {$slug} already exists: leftovers of another run, or a real document?");
    }
    $id = wp_insert_post([
        'post_type' => $type,
        'post_status' => 'publish',
        'post_author' => $author,
        'post_name' => $slug,
        'post_title' => $title,
        'post_content' => $content,
    ], true);
    if (is_wp_error($id)) {
        WP_CLI::error("could not insert {$slug}: " . $id->get_error_message());
    }
    $track('posts', (int) $id);
    pll_set_post_language((int) $id, $lang);
    $post = get_post((int) $id);
    if ($post->post_name !== $slug) {
        WP_CLI::error("WordPress stored the slug {$post->post_name} instead of {$slug}: run `cleanup`, then look for a post that squats the slug.");
    }

    return (int) $id;
};

$frHome = $insert('page', 'zz-spike-accueil', 'zz-spike Accueil', 'fr', '<p>Accueil de sonde.</p>');
$deHome = $insert('page', 'zz-spike-startseite', 'zz-spike Startseite', 'de', '<p>Startseite der Sonde.</p>');
pll_save_post_translations(['fr' => $frHome, 'de' => $deHome]);
$frProject = $insert('project', 'zz-spike-projekt', 'zz-spike Projekt (fr)', 'fr', '<p>Fiche de sonde.</p>');
$deProject = $insert('project', 'zz-spike-projekt-de', 'zz-spike Projekt (de)', 'de', '<p>Projektblatt der Sonde.</p>');
pll_save_post_translations(['fr' => $frProject, 'de' => $deProject]);
WP_CLI::log("posts: pages {$frHome} (fr) and {$deHome} (de) linked; projects {$frProject} (fr) and {$deProject} (de) linked");

update_post_meta($frProject, '_yoast_wpseo_metadesc', 'Description de sonde écrite par PHP, sans passer par l’éditeur de Yoast.');
WP_CLI::log('yoast: _yoast_wpseo_metadesc written on the French project by update_post_meta()');

update_option('show_on_front', 'page');
update_option('page_on_front', $frHome);
$error = PLL()->options->set('redirect_lang', true);
if ($error->has_errors()) {
    WP_CLI::error('redirect_lang refused: ' . $error->get_error_message());
}
PLL()->options->save();
WP_CLI::log('reading: static front page = zz-spike-accueil; polylang: redirect_lang = true');

$menu = static function (string $name) use ($track): int {
    $id = wp_create_nav_menu($name);
    if (is_wp_error($id)) {
        WP_CLI::error("could not create the menu {$name}: " . $id->get_error_message());
    }
    $track('menus', (int) $id);

    return (int) $id;
};
$frMenu = $menu('zz-spike Menu FR');
$deMenu = $menu('zz-spike Menu DE');
wp_update_nav_menu_item($frMenu, 0, ['menu-item-title' => 'zz-spike Accueil', 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $frHome, 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($frMenu, 0, ['menu-item-title' => 'zz-spike Projets', 'menu-item-type' => 'custom', 'menu-item-url' => home_url('/fr/projets/'), 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($deMenu, 0, ['menu-item-title' => 'zz-spike Startseite', 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $deHome, 'menu-item-status' => 'publish']);
wp_update_nav_menu_item($deMenu, 0, ['menu-item-title' => 'zz-spike Projekte', 'menu-item-type' => 'custom', 'menu-item-url' => home_url('/de/projets/'), 'menu-item-status' => 'publish']);
set_theme_mod('nav_menu_locations', ['primary' => $frMenu]);
$navMenus = is_array(PLL()->options['nav_menus']) ? PLL()->options['nav_menus'] : [];
$navMenus[(string) get_option('stylesheet')]['primary'] = ['fr' => $frMenu, 'de' => $deMenu];
$error = PLL()->options->set('nav_menus', $navMenus);
if ($error->has_errors()) {
    WP_CLI::error('nav_menus refused: ' . $error->get_error_message());
}
PLL()->options->save();
WP_CLI::log('menus: ' . get_option('stylesheet') . '/primary => fr ' . $frMenu . ', de ' . $deMenu . ' (theme_mod primary = fr)');

update_option('zz_spike_closed', ['de', 'en'], false);

$muCode = <<<'MUPLUGIN'
<?php
/**
 * zz-opening-spike: the filters of lots 5b and 5c of ADR-022, played here to
 * observe them before they are written in the plugin and the theme. Written
 * and removed by tests/Integration/wp-cli/opening-spike.php; the closed
 * languages are the option zz_spike_closed.
 */
$zzClosed = static function (): array {
    return array_values(array_filter((array) get_option('zz_spike_closed', ['de', 'en']), 'is_string'));
};

add_filter('pll_languages_for_browser_preferences', static function (array $languages) use ($zzClosed): array {
    $closed = $zzClosed();

    return array_values(array_filter($languages, static fn ($language): bool => !in_array($language->slug, $closed, true)));
});

add_filter('pll_preferred_language', static function ($slug, $cookie) use ($zzClosed) {
    return is_string($slug) && in_array($slug, $zzClosed(), true) ? false : $slug;
}, 10, 2);

add_filter('pll_translation_url', static function ($url, $slug) use ($zzClosed) {
    return in_array((string) $slug, $zzClosed(), true) ? '' : $url;
}, 10, 2);

add_filter('pll_rel_hreflang_attributes', static function (array $hreflangs): array {
    if (!isset($hreflangs['x-default']) && isset($hreflangs['fr'])) {
        $hreflangs['x-default'] = $hreflangs['fr'];
    }

    return $hreflangs;
});

add_filter('wpseo_robots', static function ($robots) use ($zzClosed) {
    $current = function_exists('pll_current_language') ? (string) pll_current_language() : '';

    return in_array($current, $zzClosed(), true) ? 'noindex, follow' : $robots;
});

add_filter('wpseo_sitemap_entry', static function ($url, $type, $object) use ($zzClosed) {
    $loc = is_array($url) ? (string) ($url['loc'] ?? '') : '';
    foreach ($zzClosed() as $slug) {
        if (str_starts_with($loc, home_url("/{$slug}/"))) {
            return [];
        }
    }

    return $url;
}, 10, 3);

foreach (['project', 'article', 'page', 'post'] as $zzType) {
    add_filter("wpseo_sitemap_{$zzType}_content", static function ($xml) use ($zzClosed) {
        foreach ($zzClosed() as $slug) {
            $xml = (string) preg_replace('#<url>\s*<loc>' . preg_quote(home_url("/{$slug}/"), '#') . '[^<]*</loc>.*?</url>\s*#s', '', (string) $xml);
        }

        return $xml;
    }, 20);
}

add_action('generate_menu_bar_items', static function () use ($zzClosed): void {
    if (!function_exists('pll_the_languages')) {
        return;
    }
    $elements = pll_the_languages(['raw' => 1, 'hide_if_empty' => 0, 'hide_if_no_translation' => 0, 'hide_current' => 0, 'show_wrapper' => 0]);
    $closed = $zzClosed();
    echo '<nav class="zz-spike-switcher" data-count="' . count((array) $elements) . '">';
    foreach ((array) $elements as $element) {
        $slug = is_object($element) ? (string) $element->slug : (string) ($element['slug'] ?? '');
        $url = is_object($element) ? (string) $element->url : (string) ($element['url'] ?? '');
        $noTranslation = is_object($element)
            ? !$element->has_translations
            : (array_key_exists('has_translations', $element) ? !$element['has_translations'] : !empty($element['no_translation']));
        $current = is_object($element)
            ? (bool) $element->is_current
            : (array_key_exists('is_current', $element) ? (bool) $element['is_current'] : !empty($element['current_lang']));
        if (in_array($slug, $closed, true)) {
            continue;
        }
        printf(
            '<a class="zz-lang zz-lang-%s%s%s" href="%s">%s</a>',
            esc_attr($slug),
            $noTranslation ? ' zz-no-translation' : '',
            $current ? ' zz-current' : '',
            esc_url($url),
            esc_html($slug)
        );
    }
    echo '</nav>';
});
MUPLUGIN;

if (!is_dir(WPMU_PLUGIN_DIR) && !mkdir(WPMU_PLUGIN_DIR, 0755, true)) {
    WP_CLI::error('could not create ' . WPMU_PLUGIN_DIR);
}
if (file_put_contents($muFile, $muCode . "\n") === false) {
    WP_CLI::error("could not write {$muFile}");
}
WP_CLI::log('must-use plugin written: ' . $muFile . ' (closed languages: de, en)');

PLL()->model->clean_languages_cache();
if (class_exists('WPSEO_Sitemaps_Cache')) {
    WPSEO_Sitemaps_Cache::clear();
}
flush_rewrite_rules();

WP_CLI::success('spike state in place. Now: `wp rewrite flush --hard`, then tools/opening-spike.sh, then this script with `cleanup`.');
