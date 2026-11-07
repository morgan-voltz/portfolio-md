<?php

/**
 * Exploratory spike of Polylang 3.9.1 against a real WordPress, run through
 * WP-CLI (lot 0 of ADR-020). It checks, in the CLI context, the behaviours
 * the trilingual design relies on: language creation by code, options,
 * custom post type support, language assignment and linking through the
 * public API, what WP_Query does with the `lang` query var, and the slug a
 * second language gets when it reuses one. The HTTP-level behaviours (REST
 * interception of `lang`, home redirection, hreflang, archives) are checked
 * by tools/polylang-http-spike.sh.
 *
 * From the repository root, with the stack up, the plugin and Polylang
 * activated and the content imported:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/polylang-spike.php --user=<admin login>
 *   podman-compose --profile tools run --rm wpcli wp rewrite flush --hard
 *   plugin/tools/polylang-http-spike.sh http://localhost:8000
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/polylang-spike.php cleanup --user=<admin login>
 *
 * Side effects kept on purpose, because they are the target state of
 * ADR-020: the languages fr, de and en; the Polylang options (directory
 * mode, visible default language, browser detection, articles and projects
 * translatable); `fr` on every existing post that had no language. The
 * zz-spike-* posts it creates stay until `cleanup`, so that the HTTP checks
 * can see them.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}

if (get_current_user_id() === 0) {
    WP_CLI::error('Run this script with --user=<admin login>.');
}

if (!function_exists('PLL') || !function_exists('pll_set_post_language')) {
    WP_CLI::error('Polylang is not active.');
}

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        WP_CLI::log("PASS  {$label}" . ($detail !== '' ? "  [{$detail}]" : ''));

        return;
    }
    $failures++;
    WP_CLI::warning("FAIL  {$label}" . ($detail !== '' ? " ({$detail})" : ''));
};
$note = static fn (string $label, string $value): mixed => WP_CLI::log("NOTE  {$label}: {$value}");

// ---------------------------------------------------------------- cleanup mode
if (in_array('cleanup', $args ?? [], true)) {
    $deleted = 0;
    foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 's' => 'zz-spike', 'numberposts' => -1, 'lang' => '']) as $post) {
        if (str_starts_with($post->post_title, 'zz-spike')) {
            wp_delete_post($post->ID, true);
            $deleted++;
        }
    }
    WP_CLI::success("cleanup: {$deleted} zz-spike post(s) deleted. Languages, options and the fr assignment are kept.");
    exit(0);
}

// ---------------------------------------------------------------- 0. versions
$note('WordPress', get_bloginfo('version'));
$note('Polylang', defined('POLYLANG_VERSION') ? POLYLANG_VERSION : '?');
$note('Yoast SEO', defined('WPSEO_VERSION') ? WPSEO_VERSION : 'not active');
$note('PHP', PHP_VERSION);
$note('PLL() class in WP-CLI', get_class(PLL()));

// ---------------------------------------------------------------- 1. languages by code
$wanted = ['fr' => 'fr_FR', 'de' => 'de_DE', 'en' => 'en_GB'];
foreach ($wanted as $slug => $locale) {
    if (PLL()->model->get_language($slug)) {
        $note("language {$slug}", 'already exists');
        continue;
    }
    $result = PLL()->model->languages->add(['locale' => $locale]);
    $check("languages->add(['locale' => '{$locale}']) creates {$slug}", !is_wp_error($result), is_wp_error($result) ? $result->get_error_message() : '');
}
PLL()->model->clean_languages_cache();
$slugs = pll_languages_list();
$check('three languages fr, de, en', $slugs === ['fr', 'de', 'en'] || (count($slugs) === 3 && !array_diff($slugs, ['fr', 'de', 'en'])), implode(',', $slugs));
$check('default language is fr', pll_default_language() === 'fr', (string) pll_default_language());
foreach ($wanted as $slug => $locale) {
    $language = PLL()->model->get_language($slug);
    $check("locale of {$slug} is {$locale}", $language && $language->locale === $locale, $language ? $language->locale : 'missing');
}

// ---------------------------------------------------------------- 2. options by code
$options = PLL()->options;
$note('options class', get_class($options));
foreach (['force_lang' => 1, 'hide_default' => false, 'browser' => true, 'redirect_lang' => false, 'post_types' => ['article', 'project']] as $key => $value) {
    $error = $options->set($key, $value);
    $check("options->set('{$key}')", !$error->has_errors(), $error->get_error_message());
}
$check('options->save() returns true', $options->save() === true);
$note('force_lang / hide_default / browser', json_encode([$options->get('force_lang'), $options->get('hide_default'), $options->get('browser')]));
PLL()->model->cache->clean();
$check("pll_is_translated_post_type('project') after the option", pll_is_translated_post_type('project'));
$check("pll_is_translated_post_type('article') after the option", pll_is_translated_post_type('article'));
$check("pages are translatable by default", pll_is_translated_post_type('page'));

// ---------------------------------------------------------------- 3. existing posts: what language do they have?
$without = 0;
$all = get_posts(['post_type' => ['project', 'article', 'page'], 'post_status' => 'any', 'numberposts' => -1, 'lang' => '']);
foreach ($all as $post) {
    if (!pll_get_post_language($post->ID)) {
        $without++;
    }
}
$note('posts imported before Polylang', count($all) . ' posts, ' . $without . ' without language');
$assigned = 0;
foreach ($all as $post) {
    if (!pll_get_post_language($post->ID)) {
        pll_set_post_language($post->ID, 'fr');
        $assigned++;
    }
}
$check("pll_set_post_language(…, 'fr') on the {$assigned} posts without language", $assigned === $without);
$stillWithout = 0;
foreach ($all as $post) {
    if (pll_get_post_language($post->ID) !== 'fr') {
        $stillWithout++;
    }
}
$check('every existing post now answers fr', $stillWithout === 0, "{$stillWithout} not fr");

// ---------------------------------------------------------------- 4. automatic assignment in CLI
$noLang = wp_insert_post(['post_type' => 'project', 'post_title' => 'zz-spike no language', 'post_name' => 'zz-spike-no-language', 'post_status' => 'draft', 'post_content' => '<p>spike</p>'], true);
$check('wp_insert_post without language succeeds', !is_wp_error($noLang));
$auto = is_wp_error($noLang) ? 'n/a' : var_export(pll_get_post_language((int) $noLang), true);
$note('language given by Polylang to a post inserted from WP-CLI without language', $auto);

// ---------------------------------------------------------------- 5. a translation linked by the API
$fr = get_page_by_path('momocad', OBJECT, 'project');
$check('the FR sheet momocad exists', $fr instanceof WP_Post);
$de = wp_insert_post(['post_type' => 'project', 'post_title' => 'zz-spike MomoCad (DE)', 'post_name' => 'momocad-de', 'post_status' => 'publish', 'post_content' => '<p>Spike: deutsche Fassung.</p>'], true);
$check('DE copy inserted', !is_wp_error($de));
if ($fr instanceof WP_Post && !is_wp_error($de)) {
    pll_set_post_language((int) $de, 'de');
    $check('DE copy answers de', pll_get_post_language((int) $de) === 'de');
    $check('DE copy keeps the slug momocad-de', get_post($de)->post_name === 'momocad-de', get_post($de)->post_name);
    pll_save_post_translations(['fr' => $fr->ID, 'de' => (int) $de]);
    $translations = pll_get_post_translations($fr->ID);
    $check('pll_get_post_translations(fr) lists fr and de', ($translations['fr'] ?? 0) === $fr->ID && ($translations['de'] ?? 0) === (int) $de, json_encode($translations));
    $check("pll_get_post(fr, 'de') gives the DE copy", pll_get_post($fr->ID, 'de') === (int) $de);
    $check("pll_get_post(fr, 'en') gives nothing", empty(pll_get_post($fr->ID, 'en')));
    $note('permalink of momocad (fr) in this process', get_permalink($fr->ID));
    $note('permalink of momocad-de (de) in this process', get_permalink((int) $de));
}

// ---------------------------------------------------------------- 6. slug reuse across languages
$cvFr = get_page_by_path('cv', OBJECT, 'page');
$cvDe = wp_insert_post(['post_type' => 'page', 'post_title' => 'zz-spike Lebenslauf', 'post_name' => 'cv', 'post_status' => 'publish', 'post_content' => '<p>Spike</p>'], true);
if (!is_wp_error($cvDe)) {
    pll_set_post_language((int) $cvDe, 'de');
    if ($cvFr instanceof WP_Post) {
        pll_save_post_translations(['fr' => $cvFr->ID, 'de' => (int) $cvDe]);
    }
    $note("slug given to a DE page inserted with post_name 'cv' while the FR page 'cv' exists", get_post($cvDe)->post_name);
    $check('the core suffixes the duplicate page slug (no shared slug in Polylang free)', get_post($cvDe)->post_name !== 'cv');
}
$dup = wp_insert_post(['post_type' => 'project', 'post_title' => 'zz-spike duplicate momocad', 'post_name' => 'momocad', 'post_status' => 'publish', 'post_content' => '<p>Spike</p>'], true);
if (!is_wp_error($dup)) {
    pll_set_post_language((int) $dup, 'en');
    $note("slug given to an EN project inserted with post_name 'momocad'", get_post($dup)->post_name);
    $check('the core suffixes the duplicate project slug too', get_post($dup)->post_name !== 'momocad');
    wp_delete_post((int) $dup, true);
}

// ---------------------------------------------------------------- 7. WP_Query and the lang query var, CLI context
$count = static function (array $extra): int {
    $query = new WP_Query(['post_type' => 'project', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => false] + $extra);

    return (int) $query->found_posts;
};
$note('published projects, no lang var', (string) $count([]));
$note("published projects, lang => 'fr'", (string) $count(['lang' => 'fr']));
$note("published projects, lang => 'de'", (string) $count(['lang' => 'de']));
$note("published projects, lang => ''", (string) $count(['lang' => '']));
$check("lang => 'de' keeps only the DE copy", $count(['lang' => 'de']) === 1);
$check("lang => '' lists every language", $count(['lang' => '']) === $count(['lang' => 'fr']) + $count(['lang' => 'de']));
$check('no lang var in WP-CLI lists every language too (no current language)', $count([]) === $count(['lang' => '']));

// ---------------------------------------------------------------- 8. the plugin's REST routes called in-process (no PLL_REST_Request here)
$request = new WP_REST_Request('GET', '/portfolio/v1/projects');
$request->set_query_params(['per_page' => 100]);
$response = rest_do_request($request);
$note('in-process GET /portfolio/v1/projects → X-WP-Total', (string) ($response->get_headers()['X-WP-Total'] ?? '?'));
$request = new WP_REST_Request('GET', '/portfolio/v1/projects');
$request->set_query_params(['per_page' => 100, 'lang' => 'de']);
$response = rest_do_request($request);
$note("in-process GET /portfolio/v1/projects?lang=de → X-WP-Total (the HTTP check is the one that counts)", (string) ($response->get_headers()['X-WP-Total'] ?? '?'));

// ---------------------------------------------------------------- 9. taxonomies
$note('translatable taxonomies (Polylang option)', json_encode($options->get('taxonomies')));
$check('tech_tag is not translated by Polylang', !pll_is_translated_taxonomy('tech_tag'));

WP_CLI::log('');
if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed. The zz-spike posts are kept for the HTTP checks; run with `cleanup` afterwards.");
}
WP_CLI::success('All CLI checks passed. Now: wp rewrite flush --hard, then tools/polylang-http-spike.sh, then this script with `cleanup`.');
