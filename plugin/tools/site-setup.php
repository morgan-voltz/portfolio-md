<?php

/**
 * Applies the one-time configuration of the site that ADR-022 (lot 5 of
 * ADR-020, point 12) does not want anyone to click through the admin:
 * idempotent, one line of log per setting, run once per environment and
 * again after every change of active theme (Polylang keys its menus by the
 * theme) and at every opening of a language.
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tools/site-setup.php
 *
 * In this order:
 *   1. the active theme must be portfolio-md-child, the child of
 *      GeneratePress mounted by the compose file;
 *   2. the publication option portfolio_md_published_languages exists, with
 *      French alone when it is created (the plugin normalizes it since lot 5b);
 *   3. the three taxonomies of the plugin (tech_tag, project_status, skill)
 *      are noindex in Yoast: their archives have no URL per language and
 *      their content follows the language of the visitor, so they must stay
 *      out of the index and of the XML sitemap (one Yoast option does both);
 *   4. the static front page is the French page "accueil", found by its slug
 *      and its stored language, never by an id written here (lot 5d);
 *   5. Polylang's redirect_lang is on: the home of a language is /<lang>/ and
 *      the permalink of its home page redirects there (point 11);
 *   6. one menu per language that owns its home page, "Menu FR", "Menu DE",
 *      "Menu EN", built only when it holds nothing (Home, Projects as a
 *      root-relative link to /<lang>/projets/, About, CV, Contact: pages
 *      found by slug, a missing page skipped); each language bound in
 *      Polylang's nav_menus under the active theme and the French menu bound
 *      to the `primary` location in the raw theme_mods option (get_theme_mod()
 *      is filtered by Polylang), both only when the slot is empty or points to
 *      a menu that no longer exists; a language without home page is logged
 *      "skip <lang>: no home page" and handled at its opening;
 *   7. Polylang's languages cache is cleaned when anything changed.
 * The script never deletes anything, never rebuilds a menu that holds items
 * and never rebinds a slot that holds a living menu: what Morgan arranges in
 * the admin stays. The front page and redirect_lang, on the other hand, are
 * enforced on every run: they are the design of ADR-022, not a choice.
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

$changed = 0;
$skipped = false;

/** The published page of a core key in a language, by its slug and its stored language; null when absent. */
$pageOf = static function (string $key, Language $language): ?int {
    $found = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'name' => CorePages::slug($key, $language),
        'numberposts' => 1,
        'fields' => 'ids',
        'lang' => '',
        'meta_key' => InternalMetaRegistrar::LANG,
        'meta_value' => $language->value,
    ]);

    return $found === [] ? null : (int) $found[0];
};

// 1. The active theme ---------------------------------------------------------------------
$stylesheet = (string) get_option('stylesheet');
if ($stylesheet !== 'portfolio-md-child') {
    WP_CLI::error("The active theme is \"{$stylesheet}\", not portfolio-md-child: run `wp theme activate portfolio-md-child` first (the theme is mounted by docker-compose.yml).");
}
WP_CLI::log('theme: portfolio-md-child is active');

// 2. The publication option -------------------------------------------------------------------
if (get_option('portfolio_md_published_languages', null) === null) {
    add_option('portfolio_md_published_languages', ['fr'], '', false);
    WP_CLI::log('option portfolio_md_published_languages: created with [fr]');
    $changed++;
} else {
    WP_CLI::log('option portfolio_md_published_languages: already there (' . implode(', ', (array) get_option('portfolio_md_published_languages')) . ')');
}

// 3. The taxonomy archives out of the index and of the sitemap ------------------------------------
$yoastChanged = 0;
if (!class_exists('WPSEO_Options')) {
    $skipped = true;
    WP_CLI::warning('Yoast SEO is not active: the noindex of the taxonomy archives is left for a later run.');
} else {
    foreach (['tech_tag', 'project_status', 'skill'] as $taxonomy) {
        $key = "noindex-tax-{$taxonomy}";
        if (WPSEO_Options::get($key) === true) {
            WP_CLI::log("yoast {$key}: already true");
            continue;
        }
        if (!WPSEO_Options::set($key, true)) {
            WP_CLI::error("yoast {$key} could not be set: is the taxonomy registered (plugin active)?");
        }
        WP_CLI::log("yoast {$key}: set to true");
        $changed++;
        $yoastChanged++;
    }
}

// 4. to 7. The static front page, redirect_lang, the menus, the cache: Polylang's business ----------
$polylang = function_exists('PLL') && is_object(PLL()) ? PLL() : null;
if ($polylang === null) {
    $skipped = true;
    WP_CLI::warning('Polylang is not active: the static front page, redirect_lang and the menus are left for a later run.');
} else {
    $frenchHome = $pageOf(CorePages::HOME, Language::default());
    if ($frenchHome === null) {
        $skipped = true;
        WP_CLI::warning('skip front page, redirect_lang and menus: no published French page "accueil" (import content/fr/pages first).');
    } else {
        // 4. The static front page.
        if (get_option('show_on_front') !== 'page') {
            update_option('show_on_front', 'page');
            WP_CLI::log('reading show_on_front: set to page');
            $changed++;
        } else {
            WP_CLI::log('reading show_on_front: already page');
        }
        if ((int) get_option('page_on_front') !== $frenchHome) {
            update_option('page_on_front', $frenchHome);
            WP_CLI::log("reading page_on_front: set to {$frenchHome} (the French page accueil)");
            $changed++;
        } else {
            WP_CLI::log("reading page_on_front: already {$frenchHome} (the French page accueil)");
        }

        // 5. redirect_lang: the home of a language is /<lang>/, its page permalink redirects there.
        if (empty($polylang->options['redirect_lang'])) {
            $error = $polylang->options->set('redirect_lang', true);
            if (is_wp_error($error) && $error->has_errors()) {
                WP_CLI::error('polylang redirect_lang refused: ' . $error->get_error_message());
            }
            $polylang->options->save();
            WP_CLI::log('polylang redirect_lang: set to true');
            $changed++;
        } else {
            WP_CLI::log('polylang redirect_lang: already true');
        }

        // 6. One menu per language that owns its home page.
        $projectsLabel = ['fr' => 'Projets', 'de' => 'Projekte', 'en' => 'Projects'];
        $navMenus = is_array($polylang->options['nav_menus']) ? $polylang->options['nav_menus'] : [];
        $bound = is_array($navMenus[$stylesheet]['primary'] ?? null) ? array_map('intval', $navMenus[$stylesheet]['primary']) : [];
        $boundChanged = false;
        /** Whether an id still names a menu: a binding to a deleted menu is a free slot. */
        $menuExists = static fn (int $id): bool => $id > 0 && wp_get_nav_menu_object($id) !== false;
        /** Adds an item, or stops: a menu left half built would read as built on the next run. */
        $addItem = static function (int $menuId, array $item, string $name): void {
            $id = wp_update_nav_menu_item($menuId, 0, $item + ['menu-item-status' => 'publish']);
            if (is_wp_error($id) || (int) $id === 0) {
                WP_CLI::error("menu {$name}: an item could not be added" . (is_wp_error($id) ? ' (' . $id->get_error_message() . ')' : '') . '; delete the menu and run the script again.');
            }
        };
        foreach (Language::cases() as $language) {
            $slug = $language->value;
            $home = $language->isDefault() ? $frenchHome : $pageOf(CorePages::HOME, $language);
            if ($home === null) {
                WP_CLI::log("skip {$slug}: no home page");
                continue;
            }
            $name = 'Menu ' . strtoupper($slug);
            $menu = wp_get_nav_menu_object($name);
            if ($menu === false) {
                $menuId = wp_create_nav_menu($name);
                if (is_wp_error($menuId)) {
                    WP_CLI::error("menu {$name} could not be created: " . $menuId->get_error_message());
                }
                $menuId = (int) $menuId;
                WP_CLI::log("menu {$name}: created");
                $changed++;
            } else {
                $menuId = (int) $menu->term_id;
            }
            // Everything attached to the menu, drafts and orphans included: a menu that holds anything is left as is.
            $attached = get_objects_in_term($menuId, 'nav_menu');
            $attached = is_array($attached) ? $attached : [];
            if ($attached === []) {
                $added = 0;
                $addItem($menuId, ['menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $home], $name);
                $added++;
                // The archive of the language, root-relative: the menu survives a change of host or scheme.
                $addItem($menuId, ['menu-item-title' => $projectsLabel[$slug], 'menu-item-type' => 'custom', 'menu-item-url' => "/{$slug}/projets/"], $name);
                $added++;
                foreach ([CorePages::ABOUT, CorePages::CV, CorePages::CONTACT] as $key) {
                    $pageId = $pageOf($key, $language);
                    if ($pageId === null) {
                        WP_CLI::log("menu {$name}: no page {$key} in {$slug}, item skipped");
                        continue;
                    }
                    $addItem($menuId, ['menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $pageId], $name);
                    $added++;
                }
                WP_CLI::log("menu {$name}: built with {$added} item(s)");
                $changed++;
            } else {
                WP_CLI::log("menu {$name}: already holds " . count($attached) . ' item(s), left as is');
            }
            // The binding of the language: written when empty or pointing to a menu that no longer
            // exists, kept when another menu was chosen in the admin.
            $current = $bound[$slug] ?? 0;
            if ($current === $menuId) {
                WP_CLI::log("polylang nav_menus {$slug}: already {$name} ({$menuId})");
            } elseif ($menuExists($current)) {
                WP_CLI::log("polylang nav_menus {$slug}: bound to another menu ({$current}), left as is");
            } else {
                $bound[$slug] = $menuId;
                $boundChanged = true;
                WP_CLI::log("polylang nav_menus {$slug}: bound to {$name} ({$menuId})");
            }
        }

        // Polylang's option first: when it refuses, nothing else has moved.
        if ($boundChanged) {
            $navMenus[$stylesheet]['primary'] = $bound;
            $error = $polylang->options->set('nav_menus', $navMenus);
            $stored = $polylang->options['nav_menus'];
            $kept = is_array($stored[$stylesheet]['primary'] ?? null) ? array_map('intval', $stored[$stylesheet]['primary']) : [];
            if ($kept != $bound) {
                WP_CLI::error('polylang nav_menus refused: ' . (is_wp_error($error) && $error->has_errors() ? $error->get_error_message() : 'the value was not kept'));
            }
            if (is_wp_error($error) && $error->has_errors()) {
                WP_CLI::warning('polylang nav_menus: ' . $error->get_error_message() . ' (not blocking: the bindings are saved)');
            }
            $polylang->options->save();
            WP_CLI::log("polylang nav_menus {$stylesheet}/primary: saved " . json_encode($bound));
            $changed++;
        }

        // The theme location, in the raw option (Polylang filters get_theme_mod()): the French menu
        // when the location is empty or points to a menu that no longer exists, kept otherwise.
        if (isset($bound['fr'])) {
            $modsKey = "theme_mods_{$stylesheet}";
            $mods = get_option($modsKey);
            $mods = is_array($mods) ? $mods : [];
            $current = (int) ($mods['nav_menu_locations']['primary'] ?? 0);
            if ($current === $bound['fr']) {
                WP_CLI::log("theme location primary: already menu {$current}");
            } elseif ($menuExists($current)) {
                WP_CLI::log("theme location primary: bound to another menu ({$current}), left as is");
            } else {
                $mods['nav_menu_locations'] = is_array($mods['nav_menu_locations'] ?? null) ? $mods['nav_menu_locations'] : [];
                $mods['nav_menu_locations']['primary'] = $bound['fr'];
                update_option($modsKey, $mods);
                WP_CLI::log("theme location primary: bound to menu {$bound['fr']}");
                $changed++;
            }
        }

        // 7. The cache Polylang keeps of the home page of each language.
        if ($changed > 0) {
            $polylang->model->clean_languages_cache();
            WP_CLI::log('polylang languages cache: cleaned');
        }
    }
}

if ($changed > 0 && class_exists('WPSEO_Sitemaps_Cache')) {
    WPSEO_Sitemaps_Cache::clear();
    WP_CLI::log('yoast sitemap cache: cleared');
}

if ($skipped) {
    WP_CLI::warning("{$changed} setting(s) applied, some steps skipped: run the script again once their precondition is met.");
    WP_CLI::halt(1);
}
WP_CLI::success($changed === 0 ? 'Nothing to change: the site is configured.' : "{$changed} setting(s) applied.");
