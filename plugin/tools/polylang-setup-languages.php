<?php

/**
 * Creates the three languages of the site in Polylang and sets the options
 * ADR-020 relies on (lot 2). Idempotent: a language that exists is left
 * alone, an option already right is not rewritten. Run once on a fresh site
 * before the first synchronization, and by bootstrap.sh on the local stack:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tools/polylang-setup-languages.php
 *
 * French first, so that it is the default language of new posts; then
 * German and English. The post types the plugin manages are not written
 * here: the plugin hands them to Polylang itself (Storage\PolylangPostTypes).
 * Browser detection and the redirect of the home page are left to their
 * defaults, a matter for lot 5.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}

if (!function_exists('PLL') || !is_object(PLL()) || !isset(PLL()->model)) {
    WP_CLI::error('Polylang is not active.');
}

$wanted = ['fr' => 'fr_FR', 'de' => 'de_DE', 'en' => 'en_GB'];
foreach ($wanted as $slug => $locale) {
    if (PLL()->model->get_language($slug)) {
        WP_CLI::log("language {$slug}: already there");
        continue;
    }
    $result = PLL()->model->languages->add(['locale' => $locale]);
    if (is_wp_error($result)) {
        WP_CLI::error("language {$slug} ({$locale}) could not be created: " . $result->get_error_message());
    }
    WP_CLI::log("language {$slug}: created ({$locale})");
}
PLL()->model->clean_languages_cache();

$slugs = pll_languages_list();
sort($slugs);
if ($slugs !== ['de', 'en', 'fr']) {
    WP_CLI::error('The site must hold exactly the languages fr, de and en; found: ' . implode(', ', $slugs) . '.');
}

$options = PLL()->options;
foreach (['default_lang' => 'fr', 'force_lang' => 1, 'hide_default' => false, 'rewrite' => true] as $key => $value) {
    if ($options[$key] === $value) {
        continue;
    }
    $error = $options->set($key, $value);
    if ($error->has_errors()) {
        WP_CLI::error("option {$key} refused: " . $error->get_error_message());
    }
    WP_CLI::log("option {$key}: set to " . var_export($value, true));
}
$options->save();

WP_CLI::success('Polylang holds fr, de and en; French is the default language and the URLs carry their language folder.');
