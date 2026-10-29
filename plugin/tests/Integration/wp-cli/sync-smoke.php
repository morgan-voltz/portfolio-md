<?php

/**
 * Smoke test of the Git door against a real WordPress, run through WP-CLI:
 * `POST /portfolio/v1/sync` is called through the REST server (route,
 * rights, parameters, answers) with batches of synthetic files, and what
 * it did to the posts is checked. It exercises
 * Storage\WordPressContentRepository::trash() and the whole chain behind
 * the route, which the unit suite covers with an in-memory store.
 *
 * From the repository root, with the stack up, the plugin activated and the
 * skills grid synchronized (`wp portfolio skills sync …`):
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/sync-smoke.php --user=<admin login>
 *
 * `--user` is required: the route runs as that user, who authors the posts.
 * The script creates zz-smoke-sync-* posts, a tech tag and a subscriber,
 * and deletes them at the end (trashed posts included); it saves the option
 * `portfolio_md_last_sync` and puts it back. The grid of the repository is
 * pushed twice: the first pass is what `wp portfolio skills sync` does, the
 * second must be unchanged. The Basic-auth path with an
 * application password is checked over HTTP from the host (see
 * plugin-README.md), not here: `rest_do_request()` runs no authentication.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Rest\Controller\SyncController;
use Voltz\PortfolioMd\Rest\RouteRegistrar;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\SystemClock;
use Voltz\PortfolioMd\Storage\WordPressContentRepository;
use Voltz\PortfolioMd\Storage\WordPressSkillTermGateway;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}

if (get_current_user_id() === 0) {
    WP_CLI::error('Run this script with --user=<admin login>: the route runs as a user who may write every post.');
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

$adminId = get_current_user_id();
$route = '/' . RouteRegistrar::NAMESPACE . SyncController::ROUTE;

/** What the CI script sends: a JSON body, through the REST server and its permission and parameter checks. */
$sync = static function (array $body) use ($route): WP_REST_Response {
    $request = new WP_REST_Request('POST', $route);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body((string) wp_json_encode($body));

    return rest_do_request($request);
};

$statuses = static fn (WP_REST_Response $response): array => array_values(array_map(
    static fn (array $line): string => (string) ($line['status'] ?? ''),
    is_array($response->get_data()['results'] ?? null) ? $response->get_data()['results'] : [],
));
$line = static fn (WP_REST_Response $response, int $index): array => (array) (($response->get_data()['results'] ?? [])[$index] ?? []);
$errorCode = static fn (WP_REST_Response $response): string => (string) ($response->get_data()['code'] ?? '');
$postBySlug = static function (string $type, string $slug): ?WP_Post {
    $posts = get_posts(['post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1]);
    if ($posts === []) {
        $posts = get_posts(['post_type' => $type, 'post_status' => 'trash', 'meta_key' => '_wp_desired_post_slug', 'meta_value' => $slug, 'numberposts' => 1]);
    }

    return $posts[0] ?? null;
};

$skillGateway = new WordPressSkillTermGateway();
$skill = null;
foreach ($skillGateway->all() as $term) {
    if ($term->code !== null && $term->parentId !== 0) {
        $skill = $term;
        break;
    }
}
if ($skill === null) {
    WP_CLI::error('No competency term found: run `wp portfolio skills sync` first.');
}

$import = new ContentImportService(
    new FrontmatterParser(),
    new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
    new WordPressContentRepository(),
    $skillGateway,
    new ConflictResolver(),
    new SystemClock(),
);

$tagSlug = 'zz-smoke-sync-tag';
$slugs = ['zz-smoke-sync-page', 'zz-smoke-sync-project', 'zz-smoke-sync-dry', 'zz-smoke-sync-hand', 'zz-smoke-sync-casse'];
$previousOption = get_option(SyncController::LAST_SYNC_OPTION, null);

require_once ABSPATH . 'wp-admin/includes/user.php';
$leftover = get_user_by('login', 'zz-smoke-sync-subscriber');
if ($leftover instanceof WP_User) {
    wp_delete_user($leftover->ID);
}
$subscriberId = wp_insert_user(['user_login' => 'zz-smoke-sync-subscriber', 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
if ($subscriberId instanceof WP_Error) {
    WP_CLI::error('A subscriber could not be created for the rights check: ' . $subscriberId->get_error_message());
}

$page = static fn (string $body = 'Corps de la page.'): string => "---\ntitle: \"Page de fumée (sync)\"\nslug: \"zz-smoke-sync-page\"\ntype: \"page\"\ndate: 2026-10-28\n---\n\n{$body}\n";
$project = static fn (string $body = 'Corps du projet.') => <<<MD
---
title: "Projet de fumée (sync)"
slug: "zz-smoke-sync-project"
type: "project"
date: 2026-10-28
status: "en-cours"
tags: [zz-smoke-sync-tag]
skills: ["{$skill->code}"]
---

{$body}

MD;

try {
    // 1. The route ----------------------------------------------------------------------------------------

    $routes = rest_get_server()->get_routes(RouteRegistrar::NAMESPACE);
    $check('the sync route is registered under the plugin namespace as POST', isset($routes[$route][0]['methods']['POST']));
    $check('this WordPress runs as a local environment, where application passwords work without HTTPS', wp_get_environment_type() === 'local' && wp_is_application_passwords_available(), wp_get_environment_type());

    // 2. A push creates, replays, updates ------------------------------------------------------------------

    $response = $sync(['commit_sha' => 'deadbeef', 'files' => [
        ['path' => 'pages/zz-smoke-sync-page.md', 'content' => $page()],
        ['path' => 'content/projects/zz-smoke-sync-project.md', 'content' => $project()],
        ['path' => 'README.md', 'content' => "# Content\n"],
        ['path' => 'projects/zz-smoke-sync-project/capture.png', 'content' => 'not an image'],
    ]]);
    $data = $response->get_data();
    $check('a push of two documents and two other files answers 200', $response->get_status() === 200, 'status ' . $response->get_status() . ' ' . json_encode($data));
    $check('with a line per file, in order', $statuses($response) === ['created', 'created', 'skipped', 'skipped'], implode(',', $statuses($response)));
    $check('the answer carries the commit, the dry-run flag and the counts', ($data['commit_sha'] ?? null) === 'deadbeef' && ($data['dry_run'] ?? null) === false && ($data['summary']['created'] ?? null) === 2 && ($data['summary']['skipped'] ?? null) === 2);
    $check('a line names the kind, the slug and the post', $line($response, 1)['type'] === 'project' && $line($response, 1)['slug'] === 'zz-smoke-sync-project' && is_int($line($response, 1)['post_id']));
    $pagePost = $postBySlug('page', 'zz-smoke-sync-page');
    $projectPost = $postBySlug(PostTypeRegistrar::PROJECT, 'zz-smoke-sync-project');
    $check('the page and the project exist, published, authored by the caller', $pagePost instanceof WP_Post && $projectPost instanceof WP_Post && $pagePost->post_status === 'publish' && (int) $projectPost->post_author === $adminId);
    $check('written as Git writes', $projectPost instanceof WP_Post && get_post_meta($projectPost->ID, InternalMetaRegistrar::SOURCE_TYPE, true) === ContentSource::Git->value);
    $option = get_option(SyncController::LAST_SYNC_OPTION);
    $check('the last synchronization is recorded with its commit and counts', is_array($option) && ($option['commit'] ?? null) === 'deadbeef' && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($option['at'] ?? '')) === 1 && ($option['counts']['created'] ?? null) === 2, json_encode($option));

    $response = $sync(['files' => [['path' => 'pages/zz-smoke-sync-page.md', 'content' => $page()], ['path' => 'projects/zz-smoke-sync-project.md', 'content' => $project()]]]);
    $check('the same push again changes nothing', $response->get_status() === 200 && $statuses($response) === ['unchanged', 'unchanged']);
    $recorded = get_option(SyncController::LAST_SYNC_OPTION);
    $check('and is recorded without a commit', array_key_exists('commit_sha', (array) $response->get_data()) && $response->get_data()['commit_sha'] === null && is_array($recorded) && array_key_exists('commit', $recorded) && $recorded['commit'] === null, json_encode($recorded));

    $response = $sync(['files' => [['path' => 'projects/zz-smoke-sync-project.md', 'content' => $project('Un autre corps.')]]]);
    $check('a changed document updates its post', $response->get_status() === 200 && $statuses($response) === ['updated'] && $line($response, 0)['post_id'] === $projectPost->ID);
    $check('and the content follows', str_contains((string) get_post($projectPost->ID)->post_content, 'Un autre corps.'));

    // 3. The grid, the conflicts, the failures ------------------------------------------------------------

    $grid = file_get_contents('/srv/portfolio-content/skills.yaml');
    $response = $sync(['files' => [['path' => 'skills.yaml', 'content' => (string) $grid]]]);
    $check('the skills grid of the repository goes through (what `wp portfolio skills sync` does)', $grid !== false && $response->get_status() === 200 && in_array($statuses($response), [['unchanged'], ['updated']], true), json_encode($line($response, 0)));
    $response = $sync(['files' => [['path' => 'skills.yaml', 'content' => (string) $grid]]]);
    $check('and the same grid again is unchanged', $response->get_status() === 200 && $statuses($response) === ['unchanged'], json_encode($line($response, 0)));

    $import->import($project('Depuis l\'admin.'), new ImportOptions(source: ContentSource::Admin));
    $response = $sync(['files' => [['path' => 'projects/zz-smoke-sync-project.md', 'content' => $project('Depuis Git.')], ['path' => 'pages/zz-smoke-sync-page.md', 'content' => $page()]]]);
    $check('a document edited from the admin makes the push answer 409', $response->get_status() === 409 && $statuses($response) === ['conflict', 'unchanged'], 'status ' . $response->get_status());
    $check('the conflicting line says who wrote last', str_contains((string) $line($response, 0)['message'], 'last written from admin'));
    $check('and the admin text stays', str_contains((string) get_post($projectPost->ID)->post_content, 'Depuis l\'admin.'));

    $response = $sync(['files' => [
        ['path' => 'projects/zz-smoke-sync-project.md', 'content' => $project('Depuis Git.')],
        ['path' => 'pages/zz-smoke-sync-casse.md', 'content' => "No frontmatter here.\n"],
        ['path' => 'pages/zz-smoke-sync-project.md', 'content' => $project()],
    ]]);
    $check('a document that cannot be processed makes the push answer 422, conflicts included', $response->get_status() === 422 && $statuses($response) === ['conflict', 'failed', 'failed'], 'status ' . $response->get_status() . ' ' . implode(',', $statuses($response)));
    $check('a document in the folder of another kind is refused with the reason', str_contains((string) $line($response, 2)['message'], '"pages/" folder'));
    $check('nothing was written for the refused files', $postBySlug('page', 'zz-smoke-sync-casse') === null && $postBySlug('page', 'zz-smoke-sync-project') === null);

    // 4. The parameter checks of the REST server -----------------------------------------------------------

    $response = $sync([]);
    $check('a push without files is rejected by the server', $response->get_status() === 400 && $errorCode($response) === 'rest_missing_callback_param', 'status ' . $response->get_status());
    $response = $sync(['files' => []]);
    $check('an empty list of files is rejected', $response->get_status() === 400 && $errorCode($response) === 'rest_invalid_param');
    $response = $sync(['files' => array_fill(0, SyncController::MAX_FILES, ['path' => 'README.md', 'content' => ''])]);
    $check('exactly ' . SyncController::MAX_FILES . ' files are accepted', $response->get_status() === 200 && count($statuses($response)) === SyncController::MAX_FILES, 'status ' . $response->get_status());
    $response = $sync(['files' => array_fill(0, SyncController::MAX_FILES + 1, ['path' => 'README.md', 'content' => ''])]);
    $check('more than ' . SyncController::MAX_FILES . ' files are rejected', $response->get_status() === 400 && $errorCode($response) === 'rest_invalid_param');
    $response = $sync(['files' => [['path' => 'notes/big.txt', 'content' => str_repeat('a', SyncController::MAX_CONTENT_LENGTH)]]]);
    $check('a content of exactly ' . SyncController::MAX_CONTENT_LENGTH . ' characters is accepted', $response->get_status() === 200 && $statuses($response) === ['skipped'], 'status ' . $response->get_status());
    $response = $sync(['files' => [['path' => 'notes/big.txt', 'content' => str_repeat('a', SyncController::MAX_CONTENT_LENGTH + 1)]]]);
    $check('one character more is rejected', $response->get_status() === 400 && $errorCode($response) === 'rest_invalid_param', 'status ' . $response->get_status());
    $response = $sync(['files' => [['content' => 'x']]]);
    $check('a file without path is rejected', $response->get_status() === 400 && $errorCode($response) === 'rest_invalid_param');
    $response = $sync(['files' => [['path' => 'README.md', 'content' => '']], 'commit_sha' => 'not-a-sha']);
    $check('a commit that is not a sha is rejected', $response->get_status() === 400 && $errorCode($response) === 'rest_invalid_param');

    // 5. Dry run -------------------------------------------------------------------------------------------

    $before = get_option(SyncController::LAST_SYNC_OPTION);
    $response = $sync(['dry_run' => true, 'files' => [['path' => 'pages/zz-smoke-sync-dry.md', 'content' => str_replace('zz-smoke-sync-page', 'zz-smoke-sync-dry', $page())]]]);
    $check('a dry run decides without writing', $response->get_status() === 200 && ($response->get_data()['dry_run'] ?? null) === true && $statuses($response) === ['created'] && $postBySlug('page', 'zz-smoke-sync-dry') === null);
    $check('and records nothing', get_option(SyncController::LAST_SYNC_OPTION) === $before);

    // 6. Deletions ----------------------------------------------------------------------------------------

    $handMadeId = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fait main (sync)', 'post_name' => 'zz-smoke-sync-hand', 'post_content' => '<p>Sans document.</p>'], true);
    $response = $sync(['files' => [
        ['path' => 'pages/zz-smoke-sync-page.md', 'deleted' => true],
        ['path' => 'projects/zz-smoke-sync-project.md', 'deleted' => true],
        ['path' => 'pages/zz-smoke-sync-hand.md', 'deleted' => true],
        ['path' => 'articles/zz-smoke-sync-nobody.md', 'deleted' => true],
    ]]);
    $check('a push of deletions answers 409 when one of them is not Git\'s to make', $response->get_status() === 409, 'status ' . $response->get_status());
    $check('Git retires the page it wrote, leaves the admin-edited project and the hand-made page, skips the unknown slug', $statuses($response) === ['deleted', 'conflict', 'conflict', 'skipped'], implode(',', $statuses($response)));
    $check('the page is in the trash', get_post_status($pagePost->ID) === 'trash');
    $check('the project and the hand-made page are not', get_post_status($projectPost->ID) === 'publish' && !($handMadeId instanceof WP_Error) && get_post_status((int) $handMadeId) === 'publish');

    $response = $sync(['files' => [['path' => 'pages/zz-smoke-sync-page.md', 'deleted' => true]]]);
    $check('deleting it again is unchanged', $response->get_status() === 200 && $statuses($response) === ['unchanged'] && str_contains((string) $line($response, 0)['message'], 'already in the trash'));

    $response = $sync(['files' => [['path' => 'pages/zz-smoke-sync-page.md', 'content' => $page()]]]);
    $check('a trashed slug refuses its document until a human restores or removes the post', $response->get_status() === 422 && str_contains((string) $line($response, 0)['message'], 'in the trash'));

    // 7. Who may call ------------------------------------------------------------------------------------

    wp_set_current_user(0);
    $response = $sync(['files' => [['path' => 'README.md', 'content' => '']]]);
    wp_set_current_user($adminId);
    $check('an anonymous call is refused with 401', $response->get_status() === 401 && $errorCode($response) === 'rest_forbidden', 'status ' . $response->get_status());

    wp_set_current_user($subscriberId);
    $response = $sync(['files' => [['path' => 'README.md', 'content' => '']]]);
    wp_set_current_user($adminId);
    $check('a subscriber is refused with 403', $response->get_status() === 403, 'status ' . $response->get_status());
} finally {
    wp_set_current_user($adminId);
    wp_delete_user($subscriberId);
    foreach ([PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT, 'page'] as $type) {
        foreach ($slugs as $slug) {
            $post = $postBySlug($type, $slug);
            if ($post instanceof WP_Post) {
                wp_delete_post($post->ID, true);
            }
        }
    }
    $tag = get_term_by('slug', $tagSlug, TaxonomyRegistrar::TECH_TAG);
    if ($tag instanceof WP_Term) {
        wp_delete_term($tag->term_id, TaxonomyRegistrar::TECH_TAG);
    }
    if ($previousOption === null) {
        delete_option(SyncController::LAST_SYNC_OPTION);
    } else {
        update_option(SyncController::LAST_SYNC_OPTION, $previousOption, false);
    }
}

$left = 0;
foreach ([PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT, 'page'] as $type) {
    foreach ($slugs as $slug) {
        $left += $postBySlug($type, $slug) instanceof WP_Post ? 1 : 0;
    }
}
$check('the smoke posts are cleaned up', $left === 0, "{$left} left");
$check('the smoke tag is cleaned up', get_term_by('slug', $tagSlug, TaxonomyRegistrar::TECH_TAG) === false);
$check('the last-sync option is back to what it was', get_option(SyncController::LAST_SYNC_OPTION, null) === $previousOption);
$check('the smoke subscriber is cleaned up', get_user_by('login', 'zz-smoke-sync-subscriber') === false);

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');
