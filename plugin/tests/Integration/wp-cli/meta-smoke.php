<?php

/**
 * Smoke test of the content model against a real WordPress, run through WP-CLI.
 *
 * It checks what the unit suite cannot: that WordPress accepted the
 * registrations, that the sanitizers run on update_post_meta(), that the
 * capability rules behave as intended and that the REST API exposes the
 * public metas only.
 *
 * From the repository root, with the stack up and the plugin activated:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/meta-smoke.php \
 *       --user=<admin login>
 *
 * `--user` matters: the capability checks need an administrator session.
 * The script creates one draft project and deletes it at the end.
 *
 * No `declare(strict_types=1)` here: `wp eval-file` runs the file through
 * eval(), where the declaration is no longer the first statement and PHP
 * refuses it.
 */

use Voltz\PortfolioMd\Meta\CommonMetaRegistrar;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Meta\ProjectMetaRegistrar;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
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

$storedAfterUpdate = static function (int $postId, string $metaKey, mixed $value): mixed {
    update_post_meta($postId, $metaKey, $value);

    return get_post_meta($postId, $metaKey, true);
};

// 1. Registrations ------------------------------------------------------------

$check('taxonomy skill exists', taxonomy_exists(TaxonomyRegistrar::SKILL));
$check('taxonomy skill is hierarchical', is_taxonomy_hierarchical(TaxonomyRegistrar::SKILL));
$check(
    'taxonomy skill is attached to project only',
    get_taxonomy(TaxonomyRegistrar::SKILL)->object_type === ['project'],
);

$articleMetas = get_registered_meta_keys('post', 'article');
$projectMetas = get_registered_meta_keys('post', 'project');

$check('common metas registered on article', isset($articleMetas[CommonMetaRegistrar::FEATURED]));
$check('common metas registered on project', isset($projectMetas[CommonMetaRegistrar::FEATURED]));
$check('project metas registered on project', isset($projectMetas[ProjectMetaRegistrar::IS_FIL_ROUGE]));
$check('project metas absent from article', !isset($articleMetas[ProjectMetaRegistrar::IS_FIL_ROUGE]));
$check('internal metas registered on both types', isset($articleMetas[InternalMetaRegistrar::MD_SOURCE], $projectMetas[InternalMetaRegistrar::MD_SOURCE]));
$check('internal metas hidden from REST', $projectMetas[InternalMetaRegistrar::MD_SOURCE]['show_in_rest'] === false);
$check('public metas visible in REST', $projectMetas[CommonMetaRegistrar::FEATURED]['show_in_rest'] === true);
$check('skill code term meta registered', isset(get_registered_meta_keys('term', TaxonomyRegistrar::SKILL)[TaxonomyRegistrar::SKILL_CODE_META]));

// 2. Referential parent terms (inserted at activation) -------------------------

foreach (TaxonomyRegistrar::SKILL_REFERENTIALS as $slug => $label) {
    $term = get_term_by('slug', $slug, TaxonomyRegistrar::SKILL);
    $check("skill referential {$slug} exists with its label", $term instanceof WP_Term && $term->name === $label);
}

// 3. Sanitization through the real update_post_meta() ----------------------------

$postId = wp_insert_post([
    'post_type' => 'project',
    'post_title' => 'meta-smoke temporary project',
    'post_status' => 'draft',
], true);

if (is_wp_error($postId)) {
    WP_CLI::error('could not create the temporary project: ' . $postId->get_error_message());
}

$check('invalid status is stored as empty', $storedAfterUpdate($postId, ProjectMetaRegistrar::PROJECT_STATUS, 'finished') === '');
$check('valid status is stored', $storedAfterUpdate($postId, ProjectMetaRegistrar::PROJECT_STATUS, 'stable') === 'stable');
$check('javascript URL is rejected', $storedAfterUpdate($postId, ProjectMetaRegistrar::PROJECT_REPO_URL, 'javascript:alert(1)') === '');
$check('https URL is kept', $storedAfterUpdate($postId, ProjectMetaRegistrar::PROJECT_REPO_URL, 'https://github.com/morgan-voltz/portfolio-md') === 'https://github.com/morgan-voltz/portfolio-md');
$check('impossible date is rejected', $storedAfterUpdate($postId, ProjectMetaRegistrar::PROJECT_PERIOD_START, '2026-02-30') === '');
$check('valid date is kept', $storedAfterUpdate($postId, ProjectMetaRegistrar::PROJECT_PERIOD_START, '2026-02-28') === '2026-02-28');
$check('reading time above 240 becomes 0', $storedAfterUpdate($postId, CommonMetaRegistrar::READING_TIME, '999') === '0');
$check('reading time in range is kept', $storedAfterUpdate($postId, CommonMetaRegistrar::READING_TIME, '12') === '12');
$check('fil rouge flag "yes" is stored as 1', $storedAfterUpdate($postId, ProjectMetaRegistrar::IS_FIL_ROUGE, 'yes') === '1');
$check('seo description is truncated to 160', strlen((string) $storedAfterUpdate($postId, CommonMetaRegistrar::SEO_DESCRIPTION, str_repeat('a', 300))) === 160);

$markdown = "---\ntitle: X\n---\n\n# Title\n\n<details>kept</details>\n";
$check('markdown source is stored verbatim', $storedAfterUpdate($postId, InternalMetaRegistrar::MD_SOURCE, $markdown) === $markdown);
$check('last sync is normalised to UTC', $storedAfterUpdate($postId, InternalMetaRegistrar::LAST_SYNC_AT, '2026-10-26T09:00:00+02:00') === '2026-10-26T07:00:00Z');
$check('source type outside the enum is rejected', $storedAfterUpdate($postId, InternalMetaRegistrar::SOURCE_TYPE, 'webhook') === '');
$check('source type inside the enum is kept', $storedAfterUpdate($postId, InternalMetaRegistrar::SOURCE_TYPE, 'git') === 'git');

// 4. Capability rules (require --user=<administrator>) ---------------------------

if (get_current_user_id() === 0) {
    WP_CLI::warning('SKIP  capability checks: run with --user=<administrator>');
} else {
    $check('administrator may edit a public meta', current_user_can('edit_post_meta', $postId, CommonMetaRegistrar::FEATURED));
    $check('administrator may edit a project meta', current_user_can('edit_post_meta', $postId, ProjectMetaRegistrar::IS_FIL_ROUGE));
    $check('nobody may edit an internal meta', !current_user_can('edit_post_meta', $postId, InternalMetaRegistrar::MD_SOURCE));
}

// 5. REST representation -----------------------------------------------------------

$request = new WP_REST_Request('GET', '/wp/v2/project/' . $postId);
$request->set_param('context', 'edit');
$response = rest_do_request($request);
$data = $response->get_data();

$check('REST returns the project', $response->get_status() === 200, 'status ' . $response->get_status());
$check('REST exposes public metas', isset($data['meta'][CommonMetaRegistrar::FEATURED], $data['meta'][ProjectMetaRegistrar::IS_FIL_ROUGE]));
$check('REST hides internal metas', !isset($data['meta'][InternalMetaRegistrar::MD_SOURCE]));
$check('REST typed boolean for fil rouge', ($data['meta'][ProjectMetaRegistrar::IS_FIL_ROUGE] ?? null) === true);
$check('REST typed integer for reading time', ($data['meta'][CommonMetaRegistrar::READING_TIME] ?? null) === 12);

// Cleanup --------------------------------------------------------------------------

wp_delete_post($postId, true);

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');
