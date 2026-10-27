<?php

/**
 * Smoke test of the preview route and the editor assets against a real
 * WordPress, run through WP-CLI: `POST /portfolio/v1/preview` is called
 * through the REST server (route, permissions, parameters, answer) and
 * Admin\EditorAssets is asked what it loads on each screen.
 *
 * From the repository root, with the stack up, the plugin activated and the
 * bundle built (`npm ci && npm run build` in plugin/assets):
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/preview-smoke.php --user=<admin login>
 *
 * `--user` is required: the route needs a user who may edit posts. The
 * script creates a subscriber (zz-smoke-subscriber) to check the refusal
 * and deletes it at the end; it writes no post.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Admin\EditorAssets;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Rest\Controller\PreviewController;
use Voltz\PortfolioMd\Rest\RouteRegistrar;
use Voltz\PortfolioMd\Storage\HtmlSanitizer;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}

if (get_current_user_id() === 0) {
    WP_CLI::error('Run this script with --user=<admin login>: the route needs a user who may edit posts.');
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
$route = '/' . RouteRegistrar::NAMESPACE . PreviewController::ROUTE;

// What wp.apiFetch sends: a JSON body, through the REST server and its permission and parameter checks.
$preview = static function (array $body) use ($route): WP_REST_Response {
    $request = new WP_REST_Request('POST', $route);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body((string) wp_json_encode($body));

    return rest_do_request($request);
};

$document = <<<'MD'
---
title: "Projet de fumée (aperçu)"
slug: "zz-smoke-preview"
type: "project"
date: 2026-10-27
status: "en-cours"
skills: []
tags: [php]
---

# Projet de fumée

Un paragraphe avec du `code` et un "guillemet".

- [x] une tâche faite
- [ ] une tâche à faire

MD;

$subscriberId = 0;

try {
    // 1. The route --------------------------------------------------------------------------------

    $routes = rest_get_server()->get_routes(RouteRegistrar::NAMESPACE);
    $check('the preview route is registered under the plugin namespace', isset($routes[$route]));
    $check('as a POST route', isset($routes[$route][0]['methods']['POST']));

    $response = $preview(['markdown' => $document]);
    $data = $response->get_data();
    $check('a document of the admin user is rendered', $response->get_status() === 200 && is_array($data), 'status ' . $response->get_status());
    $check('and reported as importable', ($data['importable'] ?? null) === true && ($data['problems'] ?? null) === []);
    $check('with its title', ($data['title'] ?? null) === 'Projet de fumée (aperçu)');
    $summary = (array) ($data['summary'] ?? []);
    $check('and a summary of its frontmatter', ($summary['slug'] ?? null) === 'zz-smoke-preview' && ($summary['status'] ?? null) === 'En cours' && ($summary['tags'] ?? null) === 'php');
    $html = (string) ($data['html'] ?? '');
    $check('the HTML is the front-end rendering: blocks resolved', $html !== '' && !str_contains($html, '<!-- wp:'), substr($html, 0, 120));
    $check('with the heading and the paragraph', str_contains($html, '<h1 class="wp-block-heading">Projet de fumée</h1>') && str_contains($html, '<code>code</code>'));
    $check('and the quotes texturized as on the site', str_contains($html, '&#8220;guillemet&#8221;') || str_contains($html, '“guillemet”'));
    $check('and the task-list checkboxes kept by the kses pass', substr_count($html, '<input type="checkbox"') === 2 || substr_count($html, 'type="checkbox"') === 2, $html);

    // The pass the preview applies is the one the store applies, and it is a no-op on this document.
    $blocks = (new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()))->render((new FrontmatterParser())->parse($document)->body);
    $check('the kses pass of the store leaves the rendered document untouched', HtmlSanitizer::sanitize($blocks) === $blocks);

    // 2. What the import would refuse ----------------------------------------------------------------

    $data = $preview(['markdown' => str_replace('skills: []', 'skills: ["4.0.2/E99"]', $document)])->get_data();
    $check('an unknown competency code is reported and the body still rendered', ($data['importable'] ?? null) === false && str_contains((string) ($data['problems'][0] ?? ''), '4.0.2/E99') && is_string($data['html'] ?? null));

    $data = $preview(['markdown' => "No frontmatter here.\n"])->get_data();
    $check('a document without frontmatter is refused without HTML', ($data['importable'] ?? null) === false && array_key_exists('html', $data) && $data['html'] === null && array_key_exists('title', $data) && $data['title'] === null && count($data['problems'] ?? []) === 1);

    // 3. The parameter checks of the REST server -----------------------------------------------------

    $response = $preview([]);
    $check('a request without document is rejected by the server', $response->get_status() === 400 && ($response->get_data()['code'] ?? '') === 'rest_missing_callback_param', 'status ' . $response->get_status());

    $response = $preview(['markdown' => str_repeat('a', PreviewController::MAX_LENGTH + 1)]);
    $check('a document above the size cap is rejected by the server', $response->get_status() === 400 && ($response->get_data()['code'] ?? '') === 'rest_invalid_param', 'status ' . $response->get_status());

    // 4. Who may call --------------------------------------------------------------------------------

    wp_set_current_user(0);
    $response = $preview(['markdown' => $document]);
    wp_set_current_user($adminId);
    $check('an anonymous call is refused with 401', $response->get_status() === 401 && ($response->get_data()['code'] ?? '') === 'rest_forbidden', 'status ' . $response->get_status());

    $subscriberId = wp_insert_user(['user_login' => 'zz-smoke-subscriber', 'user_pass' => wp_generate_password(32), 'role' => 'subscriber']);
    if ($subscriberId instanceof WP_Error) {
        $check('a subscriber can be created for the test', false, $subscriberId->get_error_message());
        $subscriberId = 0;
    } else {
        wp_set_current_user($subscriberId);
        $response = $preview(['markdown' => $document]);
        wp_set_current_user($adminId);
        $check('a subscriber (no edit_posts) is refused with 403', $response->get_status() === 403, 'status ' . $response->get_status());
    }

    // 5. The assets ----------------------------------------------------------------------------------

    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH . 'wp-admin/includes/screen.php';

    $pluginFile = WP_PLUGIN_DIR . '/portfolio-md/portfolio-md.php';
    $assets = new EditorAssets($pluginFile);
    $bundle = dirname($pluginFile) . '/assets/dist/admin-editor.js';
    $check('the bundle is built', is_file($bundle), 'run `npm ci && npm run build` in plugin/assets');

    $reset = static function (): void {
        wp_dequeue_script(EditorAssets::HANDLE);
        wp_deregister_script(EditorAssets::HANDLE);
        wp_dequeue_style(EditorAssets::HANDLE);
        wp_deregister_style(EditorAssets::HANDLE);
    };

    set_current_screen('project');
    $assets->enqueue('post.php');
    $script = wp_scripts()->registered[EditorAssets::HANDLE] ?? null;
    $check('the editor script is enqueued on the edit screen of a project', wp_script_is(EditorAssets::HANDLE, 'enqueued'));
    $check('after wp-api-fetch, which WordPress registers', $script !== null && $script->deps === ['wp-api-fetch'] && wp_script_is('wp-api-fetch', 'registered'));
    $check('in the footer', $script !== null && (bool) wp_scripts()->get_data(EditorAssets::HANDLE, 'group'));
    $check('with its stylesheet', wp_style_is(EditorAssets::HANDLE, 'enqueued'));
    $inline = implode("\n", (array) wp_scripts()->get_data(EditorAssets::HANDLE, 'before'));
    $config = json_decode((string) preg_replace('/^.*?window\.portfolioMdEditor = (.*);\s*$/s', '$1', $inline), true);
    $check('its configuration names the route', is_array($config) && ($config['previewPath'] ?? null) === $route, substr($inline, 0, 120));
    $stylesheet = (string) ($config['stylesheets'][0] ?? '');
    $stylesheetFile = ABSPATH . 'wp-includes/' . (string) parse_url(substr($stylesheet, strpos($stylesheet, 'wp-includes/') + strlen('wp-includes/')), PHP_URL_PATH);
    $check('and a block stylesheet that exists in this WordPress', $stylesheet !== '' && is_file($stylesheetFile), $stylesheet);
    $reset();

    set_current_screen('post');
    $assets->enqueue('post.php');
    $check('nothing is loaded on the edit screen of a post', !wp_script_is(EditorAssets::HANDLE, 'enqueued') && !wp_style_is(EditorAssets::HANDLE, 'enqueued'));

    set_current_screen('project');
    $assets->enqueue('edit.php');
    $check('nor on the list of projects', !wp_script_is(EditorAssets::HANDLE, 'enqueued'));

    $assets->enqueue('toplevel_page_portfolio-md-import');
    $check('the editor script is enqueued on the paste page', wp_script_is(EditorAssets::HANDLE, 'enqueued'));
    $reset();
} finally {
    wp_set_current_user($adminId);
    if ($subscriberId > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($subscriberId);
    }
}

$check('the smoke subscriber is cleaned up', get_user_by('login', 'zz-smoke-subscriber') === false);

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');
