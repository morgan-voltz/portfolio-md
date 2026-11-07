<?php

/**
 * Exploratory spike of Polylang 3.9.1 against a real WordPress, run through
 * WP-CLI (lot 0 of ADR-020). It checks, in the CLI context, the behaviours
 * the trilingual design relies on: language creation by code, options,
 * custom post type support, language assignment and linking through the
 * public API, what WP_Query does with the `lang` query var, the slug a
 * second language gets when it reuses one, and whether an empty language
 * is offered to the browser detection. The HTTP-level behaviours (REST
 * parameters, home redirection, hreflang, archives, admin screens) are
 * checked by tools/polylang-http-spike.sh, which needs the posts this
 * script leaves behind.
 *
 * From the repository root, with the stack up, the plugin and Polylang
 * activated and the content imported (the sheet `momocad` and the page
 * `cv` must exist):
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
 * translatable); `fr` on every existing post that had no language; the
 * real sheet `momocad` and the real page `cv` gain a translation link to a
 * spike post. The posts it creates have a title starting with "zz-spike":
 * a run starts by purging the leftovers of a previous one, `cleanup`
 * purges them and exits, and deleting them also removes the links.
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
$note = static function (string $label, string $value): void {
    WP_CLI::log("NOTE  {$label}: {$value}");
};

/** Deletes every post whose title starts with "zz-spike" (whatever its type, status or language). */
$purge = static function (): int {
    $deleted = 0;
    foreach (get_posts(['post_type' => 'any', 'post_status' => 'any', 's' => 'zz-spike', 'numberposts' => -1, 'lang' => '']) as $post) {
        if (str_starts_with($post->post_title, 'zz-spike')) {
            wp_delete_post($post->ID, true);
            $deleted++;
        }
    }

    return $deleted;
};

// ---------------------------------------------------------------- cleanup mode
if (in_array('cleanup', $args ?? [], true)) {
    $deleted = $purge();
    WP_CLI::success("cleanup: {$deleted} zz-spike post(s) deleted. Languages, options and the fr assignment are kept.");
    exit(0);
}

// ---------------------------------------------------------------- preconditions
$fr = get_page_by_path('momocad', OBJECT, 'project');
$cvFr = get_page_by_path('cv', OBJECT, 'page');
if (!$fr instanceof WP_Post || !$cvFr instanceof WP_Post) {
    WP_CLI::error('Import the content first: the sheet "momocad" and the page "cv" are missing (wp portfolio import /srv/portfolio-content/fr/projects and /fr/pages).');
}
$leftovers = $purge();
if ($leftovers > 0) {
    $note('leftovers of a previous run purged', (string) $leftovers);
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
$check('three languages fr, de, en', count($slugs) === 3 && !array_diff($slugs, ['fr', 'de', 'en']), implode(',', $slugs));
$check('default language is fr', pll_default_language() === 'fr', (string) pll_default_language());
foreach ($wanted as $slug => $locale) {
    $language = PLL()->model->get_language($slug);
    $check("locale of {$slug} is {$locale}", $language && $language->locale === $locale, $language ? $language->locale : 'missing');
}

// ---------------------------------------------------------------- 2. options by code
$options = PLL()->options;
$note('options class', get_class($options));
$target = ['force_lang' => 1, 'hide_default' => false, 'browser' => true, 'redirect_lang' => false, 'post_types' => ['article', 'project']];
foreach ($target as $key => $value) {
    $error = $options->set($key, $value);
    $check("options->set('{$key}') is accepted", !$error->has_errors(), $error->get_error_message());
}
// save() answers false when nothing changed (a second run): the state is what counts.
$note('options->save()', var_export($options->save(), true) . ' (false on a replay means nothing had changed)');
$stored = get_option('polylang');
foreach (['force_lang' => 1, 'hide_default' => false, 'browser' => true, 'redirect_lang' => false] as $key => $value) {
    $check("stored option {$key}", isset($stored[$key]) && (bool) $stored[$key] === (bool) $value && (int) $stored[$key] === (int) $value, var_export($stored[$key] ?? null, true));
}
$check('stored option post_types holds article and project', !array_diff(['article', 'project'], (array) ($stored['post_types'] ?? [])), json_encode($stored['post_types'] ?? null));
PLL()->model->cache->clean();
$check("pll_is_translated_post_type('project') after the option", pll_is_translated_post_type('project'));
$check("pll_is_translated_post_type('article') after the option", pll_is_translated_post_type('article'));
$check('pages are translatable by default', pll_is_translated_post_type('page'));

// ---------------------------------------------------------------- 3. existing posts: what language do they have?
$all = get_posts(['post_type' => ['project', 'article', 'page'], 'post_status' => 'any', 'numberposts' => -1, 'lang' => '']);
$without = array_values(array_filter($all, static fn (WP_Post $post): bool => !pll_get_post_language($post->ID)));
$note('posts imported before Polylang', count($all) . ' posts, ' . count($without) . ' without language');
$assigned = 0;
foreach ($without as $post) {
    if (pll_set_post_language($post->ID, 'fr')) {
        $assigned++;
    }
}
$note("pll_set_post_language(…, 'fr') answered true", $assigned . ' time(s) on ' . count($without));
$stillWithout = count(array_filter($all, static fn (WP_Post $post): bool => pll_get_post_language($post->ID) !== 'fr'));
$check('every existing post now answers fr', $stillWithout === 0, "{$stillWithout} not fr");

// ---------------------------------------------------------------- 4. automatic assignment in CLI
$noLang = wp_insert_post(['post_type' => 'project', 'post_title' => 'zz-spike no language', 'post_name' => 'zz-spike-no-language', 'post_status' => 'draft', 'post_content' => '<p>spike</p>'], true);
$check('wp_insert_post without language succeeds', !is_wp_error($noLang));
$note('language given by Polylang to a post inserted from WP-CLI without language', is_wp_error($noLang) ? 'n/a' : var_export(pll_get_post_language((int) $noLang), true));

// ---------------------------------------------------------------- 5. a translation linked by the API, carrying the tags of its original
$de = wp_insert_post(['post_type' => 'project', 'post_title' => 'zz-spike MomoCad (DE)', 'post_name' => 'momocad-de', 'post_status' => 'publish', 'post_content' => '<p>Spike: deutsche Fassung.</p>'], true);
$check('DE copy inserted', !is_wp_error($de));
if (!is_wp_error($de)) {
    pll_set_post_language((int) $de, 'de');
    $check('DE copy answers de', pll_get_post_language((int) $de) === 'de');
    $check('DE copy keeps the slug momocad-de', get_post($de)->post_name === 'momocad-de', get_post($de)->post_name);
    pll_save_post_translations(['fr' => $fr->ID, 'de' => (int) $de]);
    $translations = pll_get_post_translations($fr->ID);
    $check('pll_get_post_translations(fr) lists fr and de', ($translations['fr'] ?? 0) === $fr->ID && ($translations['de'] ?? 0) === (int) $de, json_encode($translations));
    $check("pll_get_post(fr, 'de') gives the DE copy", pll_get_post($fr->ID, 'de') === (int) $de);
    $check("pll_get_post(fr, 'en') gives nothing", empty(pll_get_post($fr->ID, 'en')));
    $tags = wp_get_object_terms($fr->ID, 'tech_tag', ['fields' => 'ids']);
    wp_set_object_terms((int) $de, $tags, 'tech_tag');
    $note('tech_tag terms copied from momocad to its DE copy (for the archive check over HTTP)', (string) count($tags));
    $note('permalink of momocad (fr) in this process', get_permalink($fr->ID));
    $note('permalink of momocad-de (de) in this process', get_permalink((int) $de));
}

// ---------------------------------------------------------------- 6. slug reuse across languages
$cvDe = wp_insert_post(['post_type' => 'page', 'post_title' => 'zz-spike Lebenslauf', 'post_name' => 'cv', 'post_status' => 'publish', 'post_content' => '<p>Spike</p>'], true);
if (!is_wp_error($cvDe)) {
    pll_set_post_language((int) $cvDe, 'de');
    pll_save_post_translations(['fr' => $cvFr->ID, 'de' => (int) $cvDe]);
    $note("slug given to a DE page inserted with post_name 'cv' while the FR page 'cv' exists", get_post($cvDe)->post_name);
    $check('the core suffixes the duplicate page slug (no shared slug in Polylang free)', get_post($cvDe)->post_name === 'cv-2', get_post($cvDe)->post_name);
}
$dup = wp_insert_post(['post_type' => 'project', 'post_title' => 'zz-spike duplicate momocad', 'post_name' => 'momocad', 'post_status' => 'publish', 'post_content' => '<p>Spike</p>'], true);
if (!is_wp_error($dup)) {
    pll_set_post_language((int) $dup, 'en');
    $note("slug given to an EN project inserted with post_name 'momocad'", get_post($dup)->post_name);
    $check('the core suffixes the duplicate project slug too', get_post($dup)->post_name === 'momocad-2', get_post($dup)->post_name);
    wp_delete_post((int) $dup, true);
}

// ---------------------------------------------------------------- 7. WP_Query and the lang query var, CLI context
$count = static function (array $extra): int {
    $query = new WP_Query(['post_type' => 'project', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids'] + $extra);

    return (int) $query->found_posts;
};
$note('published projects, no lang var', (string) $count([]));
$note("published projects, lang => 'fr'", (string) $count(['lang' => 'fr']));
$note("published projects, lang => 'de'", (string) $count(['lang' => 'de']));
$note("published projects, lang => ''", (string) $count(['lang' => '']));
$check("lang => 'de' keeps only the DE copy", $count(['lang' => 'de']) === 1, (string) $count(['lang' => 'de']));
$check("lang => '' lists every language", $count(['lang' => '']) === $count(['lang' => 'fr']) + $count(['lang' => 'de']));
$check('no lang var in WP-CLI lists every language too (no current language)', $count([]) === $count(['lang' => '']));

// ---------------------------------------------------------------- 8. an empty language is hidden from the browser detection
$visible = static fn (): array => array_map(static fn ($language) => $language->slug, PLL()->model->languages->filter('hide_empty')->get_list());
$before = $visible();
$note("languages offered to the browser detection before any EN post (filter 'hide_empty')", implode(',', $before));
$check('en is hidden while it has no post', !in_array('en', $before, true));
$en = wp_insert_post(['post_type' => 'project', 'post_title' => 'zz-spike English sheet', 'post_name' => 'zz-spike-en', 'post_status' => 'publish', 'post_content' => '<p>Spike: English version.</p>'], true);
if (!is_wp_error($en)) {
    pll_set_post_language((int) $en, 'en');
    PLL()->model->clean_languages_cache();
    $after = $visible();
    $note('languages offered once an EN post exists', implode(',', $after));
    $check('en is offered as soon as it has a post', in_array('en', $after, true));
}

// ---------------------------------------------------------------- 9. the plugin's REST routes called in-process (no PLL_REST_Request here)
$request = new WP_REST_Request('GET', '/portfolio/v1/projects');
$request->set_query_params(['per_page' => 100]);
$note('in-process GET /portfolio/v1/projects → X-WP-Total (documents only: the spike copies have none)', (string) (rest_do_request($request)->get_headers()['X-WP-Total'] ?? '?'));

// ---------------------------------------------------------------- 10. taxonomies
$note('translatable taxonomies (Polylang option)', json_encode($options->get('taxonomies')));
$check('tech_tag is not translated by Polylang', !pll_is_translated_taxonomy('tech_tag'));

WP_CLI::log('');
if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed. The zz-spike posts are kept for the HTTP checks; run with `cleanup` afterwards.");
}
WP_CLI::success('All CLI checks passed. Now: wp rewrite flush --hard, then tools/polylang-http-spike.sh, then this script with `cleanup`.');
