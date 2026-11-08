<?php

/**
 * Applies the one-time configuration of the site that ADR-022 (lot 5 of
 * ADR-020) does not want anyone to click through the admin: idempotent,
 * one line of log per setting, run once per environment and again after
 * every change of active theme or opening of a language.
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tools/site-setup.php
 *
 * Lot 5a (this version) sets only what is safe whatever the content:
 *   - the active theme must be portfolio-md-child, the child of
 *     GeneratePress mounted by the compose file;
 *   - the publication option portfolio_md_published_languages exists, with
 *     French alone when it is created (the plugin normalizes it since lot 5b);
 *   - the three taxonomies of the plugin (tech_tag, project_status, skill)
 *     are noindex in Yoast: their archives have no URL per language and
 *     their content follows the language of the visitor, so they must stay
 *     out of the index and of the XML sitemap (one Yoast option does both).
 * The static front page, the menus per language and redirect_lang come
 * with lot 5d, once the home pages exist.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}

$changed = 0;
$skipped = false;

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
    }
    if ($changed > 0 && class_exists('WPSEO_Sitemaps_Cache')) {
        WPSEO_Sitemaps_Cache::clear();
        WP_CLI::log('yoast sitemap cache: cleared');
    }
}

if ($skipped) {
    WP_CLI::warning("{$changed} setting(s) applied, the Yoast step skipped: run the script again once Yoast SEO is active.");
    WP_CLI::halt(1);
}
WP_CLI::success($changed === 0 ? 'Nothing to change: the site is configured.' : "{$changed} setting(s) applied.");
