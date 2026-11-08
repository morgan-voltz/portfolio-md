<?php

/**
 * Smoke test of the opening of a language (ADR-022, lot 5b) against the real
 * WordPress, Polylang and Yoast of the local stack, run through WP-CLI: the
 * option and its hooks, the filters the plugin registered at boot, and the
 * command `wp portfolio languages`.
 *
 * From the repository root, with the stack up, the plugin, Polylang and
 * Yoast active and the child theme portfolio-md-child active:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/publication-smoke.php --user=<admin login>
 *
 * The script records the option before it starts and puts it back at the
 * end; it creates one draft page carrying a stored German language and
 * deletes it. The HTTP side (robots, hreflang, redirects, sitemap as a
 * visitor sees them) is tools/opening-check.sh.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Storage\WordPressPublishedLanguagesStore;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}
if (!function_exists('PLL') || !class_exists('WPSEO_Options')) {
    WP_CLI::error('Polylang and Yoast SEO must be active.');
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
// The command runs in another process: its writes land in the database, not in this
// process's option cache, which is flushed after each run before anything is read back.
$run = static function (string $command): object {
    $result = WP_CLI::runcommand("portfolio languages {$command}", ['return' => 'all', 'exit_error' => false]);
    wp_cache_flush();

    return $result;
};

$option = WordPressPublishedLanguagesStore::OPTION;
$before = get_option($option, null);
$languages = new PublishedLanguages(new WordPressPublishedLanguagesStore());

try {
    // 1. The option and its hooks ------------------------------------------------------------
    update_option($option, ['xx', 'en', 'fr', 'en']);
    $check('a direct write is normalized on its way in (known languages, French first, once)', get_option($option) === ['fr', 'en'], json_encode(get_option($option)));
    $check('the service reads it back as languages', array_map(static fn (Language $l): string => $l->value, $languages->all()) === ['fr', 'en']);

    update_option($option, 'garbage');
    $check('a broken value reads as French alone', array_map(static fn (Language $l): string => $l->value, $languages->all()) === ['fr'] && get_option($option) === ['fr'], json_encode(get_option($option)));

    $languages->unpublish(Language::En);
    $languages->unpublish(Language::De);
    $check('both other languages closed for the rest of the run', !$languages->isPublished(Language::De) && !$languages->isPublished(Language::En));

    // 2. The filters, as registered at boot --------------------------------------------------------
    $home = home_url('/');
    $check('pll_translation_url is emptied for a closed language and kept for French', apply_filters('pll_translation_url', $home . 'de/', 'de') === '' && apply_filters('pll_translation_url', $home . 'fr/', 'fr') === $home . 'fr/');
    $check('pll_preferred_language refuses a closed language and keeps French', apply_filters('pll_preferred_language', 'de', 'de') === false && apply_filters('pll_preferred_language', 'fr', false) === 'fr');
    $browser = array_map(static fn ($language): string => $language->slug, apply_filters('pll_languages_for_browser_preferences', PLL()->model->get_languages_list()));
    $check('the browser is matched against the open languages only', $browser === ['fr'], implode(',', $browser));
    $hreflangs = apply_filters('pll_rel_hreflang_attributes', ['fr' => $home . 'fr/x/', 'en' => $home . 'en/x/']);
    $check('x-default is added towards the French page', ($hreflangs['x-default'] ?? null) === $home . 'fr/x/');
    $check('wpseo_sitemap_entry drops an entry under a closed prefix and keeps a French one', apply_filters('wpseo_sitemap_entry', ['loc' => $home . 'de/projets/x/'], 'post', null) === [] && apply_filters('wpseo_sitemap_entry', ['loc' => $home . 'fr/projets/x/'], 'post', null) === ['loc' => $home . 'fr/projets/x/']);
    $xml = "<url>\n<loc>{$home}de/projets/</loc>\n<lastmod>x</lastmod>\n</url>\n<url>\n<loc>{$home}fr/projets/</loc>\n</url>\n";
    $check('the German archive block is stripped from the project sitemap content, the French one kept', apply_filters('wpseo_sitemap_project_content', $xml) === "<url>\n<loc>{$home}fr/projets/</loc>\n</url>\n", json_encode(apply_filters('wpseo_sitemap_project_content', $xml)));

    $draft = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'zz-smoke publication', 'post_name' => 'zz-smoke-publication'], true);
    if (is_wp_error($draft)) {
        WP_CLI::error('could not create the draft page: ' . $draft->get_error_message());
    }
    try {
        update_post_meta((int) $draft, InternalMetaRegistrar::LANG, 'de');
        $presentation = (object) ['model' => (object) ['object_type' => 'post', 'object_sub_type' => 'page', 'object_id' => (int) $draft]];
        $check('wpseo_robots makes a document of a closed language noindex, follow', apply_filters('wpseo_robots', 'index, follow', $presentation) === 'noindex, follow');
        $languages->publish(Language::De);
        $check('and leaves it alone once the language is open', apply_filters('wpseo_robots', 'index, follow', $presentation) === 'index, follow');
        $check('the translation URL of German comes back with the opening', apply_filters('pll_translation_url', $home . 'de/', 'de') === $home . 'de/');
        $languages->unpublish(Language::De);
    } finally {
        wp_delete_post((int) $draft, true);
    }

    // 3. The command -------------------------------------------------------------------------
    $status = $run('status --format=json');
    $decoded = json_decode((string) $status->stdout, true);
    $de = null;
    foreach ($decoded['languages'] ?? [] as $row) {
        if (($row['slug'] ?? '') === 'de') {
            $de = $row;
        }
    }
    $check('status --format=json lists the three languages', $status->return_code === 0 && count($decoded['languages'] ?? []) === 3, (string) $status->stderr);
    $check('German is closed and not ready, the grid naming what is missing', $de !== null && $de['published'] === false && $de['ready'] === false && in_array('page accueil', array_column(array_filter($de['checks'], static fn (array $c): bool => !$c['ok']), 'label'), true), json_encode($de));

    $refused = $run('publish de');
    $check('publish refuses a language whose grid is not green', $refused->return_code !== 0 && str_contains((string) $refused->stderr, 'not ready') && !$languages->isPublished(Language::De), (string) $refused->stderr);

    $forced = $run('publish de --force');
    $check('publish --force opens it and warns that English is closed', $forced->return_code === 0 && $languages->isPublished(Language::De) && str_contains((string) $forced->stderr, 'en is closed'), (string) $forced->stdout . ' | ' . (string) $forced->stderr);
    $check('publishing twice is harmless', $run('publish de --force')->return_code === 0 && $languages->isPublished(Language::De));

    $closed = $run('unpublish de');
    $check('unpublish closes it again', $closed->return_code === 0 && !$languages->isPublished(Language::De), (string) $closed->stderr);
    $check('French cannot be closed', $run('unpublish fr')->return_code !== 0 && $languages->isPublished(Language::Fr));
    $check('an unknown language is refused', $run('publish xx')->return_code !== 0);
} finally {
    if ($before === null) {
        delete_option($option);
    } else {
        update_option($option, $before);
    }
}

$check('the option is back to its previous value', get_option($option, null) === ($before === null ? null : PublishedLanguages::normalize($before)), json_encode([get_option($option, null), $before]));

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}
WP_CLI::success('all checks passed');
