<?php

/**
 * Smoke test of the two admin doors against a real WordPress, run through
 * WP-CLI: the Markdown box on the edit screen (Admin\EditorMetaBox with
 * Admin\EditScreen) and the paste page (Admin\ImportPage). It plays the
 * form submissions the browser would send, through the real hooks, and
 * checks what WordPress stored.
 *
 * From the repository root, with the stack up and the plugin activated:
 *
 *   podman-compose --profile tools run --rm wpcli wp eval-file \
 *       wp-content/plugins/portfolio-md/tests/Integration/wp-cli/admin-smoke.php --user=<admin login>
 *
 * `--user` is required: nonces and capabilities need a user, and posts need
 * an author. The script creates a few projects (slugs zz-smoke-*), and
 * deletes everything it created, including the tech tag it made and the
 * feedback it stored.
 *
 * No `declare(strict_types=1)`: `wp eval-file` runs the file through eval().
 */

use Voltz\PortfolioMd\Admin\EditorMetaBox;
use Voltz\PortfolioMd\Admin\EditScreen;
use Voltz\PortfolioMd\Admin\ImportPage;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportOptions;
use Voltz\PortfolioMd\Service\ImportOutcome;
use Voltz\PortfolioMd\Service\SystemClock;
use Voltz\PortfolioMd\Storage\WordPressContentRepository;
use Voltz\PortfolioMd\Storage\WordPressSkillTermGateway;
use Voltz\PortfolioMd\Taxonomies\TaxonomyRegistrar;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "This script must be run through `wp eval-file`.\n");
    exit(1);
}

if (get_current_user_id() === 0) {
    WP_CLI::error('Run this script with --user=<admin login>: nonces, capabilities and posts need a user.');
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

$countBySlug = static function (string $type, string $slug): int {
    return count(get_posts(['post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => -1]));
};

$meta = static fn (int $postId, string $key): mixed => get_post_meta($postId, $key, true);

$feedbackKey = static fn (int $postId): string => EditorMetaBox::feedbackKey($postId, get_current_user_id());

$service = new ContentImportService(
    new FrontmatterParser(),
    new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
    new WordPressContentRepository(),
    new WordPressSkillTermGateway(),
    new ConflictResolver(),
    new SystemClock(),
);
$screen = new EditScreen();
$box = new EditorMetaBox($service);
$page = new ImportPage($service);
$screen->hook();
$box->hook();

// What the browser sends when the edit screen is saved: our nonce and the textarea, slashed by WordPress.
$submit = static function (int $postId, string $markdown) use ($box): void {
    $_POST = [
        EditorMetaBox::NONCE_FIELD => wp_create_nonce('portfolio_md_save_' . $postId),
        EditorMetaBox::FIELD => wp_slash($markdown),
    ];
    try {
        $box->save($postId, get_post($postId));
    } finally {
        $_POST = [];
    }
};

$renderBox = static function (int $postId) use ($box): string {
    ob_start();
    $box->render(get_post($postId));

    return (string) ob_get_clean();
};

$tagSlug = 'zz-smoke-admin-tag';
$createdPosts = [];

$document = <<<'MD'
---
title: "Projet de fumée (admin)"
slug: "zz-smoke-admin"
type: "project"
date: 2026-10-26
status: "en-cours"
skills: []
tags: [zz-smoke-admin-tag]
---

# Projet de fumée

Un paragraphe avec `C:\chemin\fichier`.

MD;

try {
    // 1. The edit screen -----------------------------------------------------------------------

    $check('the block editor is off for projects', apply_filters('use_block_editor_for_post_type', true, PostTypeRegistrar::PROJECT) === false);
    $check('the block editor is off for articles', apply_filters('use_block_editor_for_post_type', true, PostTypeRegistrar::ARTICLE) === false);
    $check('the block editor is left on for posts', apply_filters('use_block_editor_for_post_type', true, 'post') === true);

    $screen->trimScreen(PostTypeRegistrar::PROJECT);
    $check('the content editor and the title field leave the project screen', !post_type_supports(PostTypeRegistrar::PROJECT, 'editor') && !post_type_supports(PostTypeRegistrar::PROJECT, 'title'));
    add_post_type_support(PostTypeRegistrar::PROJECT, 'editor');
    add_post_type_support(PostTypeRegistrar::PROJECT, 'title');

    $shellId = wp_insert_post(['post_type' => PostTypeRegistrar::PROJECT, 'post_status' => 'publish', 'post_title' => 'Coquille', 'post_name' => 'zz-smoke-shell'], true);
    if (!is_int($shellId) || $shellId === 0) {
        WP_CLI::error('Cannot create the shell post: ' . (is_wp_error($shellId) ? $shellId->get_error_message() : 'no id'));
    }
    $createdPosts[] = $shellId;

    $rowActions = apply_filters('post_row_actions', ['edit' => 'e', 'inline hide-if-no-js' => 'q', 'trash' => 't'], get_post($shellId));
    $check('Quick Edit is withdrawn from the project rows', !isset($rowActions['inline hide-if-no-js']) && isset($rowActions['edit'], $rowActions['trash']));
    $bulk = apply_filters('bulk_actions-edit-' . PostTypeRegistrar::PROJECT, ['edit' => 'e', 'trash' => 't']);
    $check('Bulk Edit is withdrawn from the project list', !isset($bulk['edit']) && isset($bulk['trash']));

    require_once ABSPATH . 'wp-admin/includes/post.php';
    $check('the permalink editor is hidden under the title', get_sample_permalink_html($shellId) === '');

    // The real meta box registry, filled the way the edit screen fills it, then trimmed by our hooks.
    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH . 'wp-admin/includes/screen.php';
    require_once ABSPATH . 'wp-admin/includes/template.php';
    require_once ABSPATH . 'wp-admin/includes/meta-boxes.php';
    global $wp_meta_boxes;
    $wp_meta_boxes = [];
    set_current_screen(PostTypeRegistrar::PROJECT);
    register_and_do_post_meta_boxes(get_post($shellId));
    $registered = $wp_meta_boxes[PostTypeRegistrar::PROJECT] ?? [];
    // remove_meta_box() leaves the id in place with `false` as its value.
    $live = static function (string $context, string $id) use ($registered): bool {
        foreach (['high', 'core', 'default', 'low'] as $priority) {
            if (!empty($registered[$context][$priority][$id])) {
                return true;
            }
        }

        return false;
    };
    $check('the slug box is gone from the real registry', !$live('normal', 'slugdiv'));
    $check('the tech tag box is gone', !$live('side', 'tagsdiv-' . TaxonomyRegistrar::TECH_TAG));
    $check('the project status box is gone', !$live('side', 'tagsdiv-' . TaxonomyRegistrar::PROJECT_STATUS));
    $check('the skill box is gone', !$live('side', TaxonomyRegistrar::SKILL . 'div'));
    $check('the publish box and the featured image stay', $live('side', 'submitdiv') && $live('side', 'postimagediv'));
    $check('the Markdown box is registered high in the main column', !empty($registered['normal']['high'][EditorMetaBox::ID]));

    // 1 bis. A page is edited as its document only when it holds one ------------------------------

    $liveIn = static function (array $boxes, string $context, string $id): bool {
        foreach (['high', 'core', 'default', 'low'] as $priority) {
            if (!empty($boxes[$context][$priority][$id])) {
                return true;
            }
        }

        return false;
    };

    $bareId = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page nue', 'post_name' => 'zz-smoke-bare-page'], true);
    if (!is_int($bareId) || $bareId === 0) {
        WP_CLI::error('Cannot create the bare page: ' . (is_wp_error($bareId) ? $bareId->get_error_message() : 'no id'));
    }
    $createdPosts[] = $bareId;
    $pageDocument = "---\ntitle: \"Page de fumée\"\nslug: \"zz-smoke-page\"\ntype: \"page\"\ndate: 2026-10-26\nseo_description: \"Page de fumée.\"\n---\n\n# Page de fumée\n\nCorps.\n";
    $pageId = $service->import($pageDocument, new ImportOptions())->postId ?? 0;
    if ($pageId > 0) {
        $createdPosts[] = $pageId;
    }
    $check('a page holding a document loses the block editor, a bare page keeps it', $pageId > 0 && !use_block_editor_for_post($pageId) && use_block_editor_for_post($bareId));

    set_current_screen('page');
    $_GET['post'] = (string) $pageId;
    $screen->trimCurrentScreen();
    $check('its screen loses the content editor and the title field', !post_type_supports('page', 'editor') && !post_type_supports('page', 'title'));
    add_post_type_support('page', 'editor');
    add_post_type_support('page', 'title');
    $_GET['post'] = (string) $bareId;
    $screen->trimCurrentScreen();
    $check('the screen of a bare page keeps them', post_type_supports('page', 'editor') && post_type_supports('page', 'title'));
    unset($_GET['post']);

    $check('the permalink editor is hidden for the documented page only', get_sample_permalink_html($pageId) === '' && get_sample_permalink_html($bareId) !== '');
    // Pages are hierarchical: their list goes through page_row_actions, not post_row_actions.
    $documentedActions = apply_filters('page_row_actions', ['edit' => 'e', 'inline hide-if-no-js' => 'q'], get_post($pageId));
    $bareActions = apply_filters('page_row_actions', ['edit' => 'e', 'inline hide-if-no-js' => 'q'], get_post($bareId));
    $check('Quick Edit is withdrawn from the documented page only', !isset($documentedActions['inline hide-if-no-js']) && isset($bareActions['inline hide-if-no-js']));

    $wp_meta_boxes = [];
    register_and_do_post_meta_boxes(get_post($pageId));
    $pageBoxes = $wp_meta_boxes['page'] ?? [];
    $check('the documented page gets the Markdown box, loses the slug box and keeps its attributes', !empty($pageBoxes['normal']['high'][EditorMetaBox::ID]) && !$liveIn($pageBoxes, 'normal', 'slugdiv') && $liveIn($pageBoxes, 'side', 'pageparentdiv'));
    $check('and shows its document in the box', str_contains($renderBox($pageId), 'slug: &quot;zz-smoke-page&quot;'));

    $wp_meta_boxes = [];
    register_and_do_post_meta_boxes(get_post($bareId));
    $bareBoxes = $wp_meta_boxes['page'] ?? [];
    $check('the bare page keeps its slug box and gets no Markdown box', $liveIn($bareBoxes, 'normal', 'slugdiv') && empty($bareBoxes['normal']['high'][EditorMetaBox::ID]));
    set_current_screen(PostTypeRegistrar::PROJECT);

    // 2. A post just created receives its first document through the box ----------------------

    $html = $renderBox($shellId);
    $check('the box offers a dated template to a post without document', str_contains($html, 'type: &quot;project&quot;') && str_contains($html, 'date: ' . wp_date('Y-m-d')));

    $submit($shellId, str_replace("\n", "\r\n", $document));
    $post = get_post($shellId);
    $check('the document renamed and retitled the post', $post->post_name === 'zz-smoke-admin' && $post->post_title === 'Projet de fumée (admin)', "{$post->post_name} / {$post->post_title}");
    $check('the post is still published', $post->post_status === 'publish', $post->post_status);
    $check('the content is Gutenberg HTML', str_starts_with($post->post_content, '<!-- wp:heading'));
    $check('the stored Markdown lost the CRLF of the form', $meta($shellId, InternalMetaRegistrar::MD_SOURCE) === $document);
    $check('the hash is the one of the LF document', $meta($shellId, InternalMetaRegistrar::MD_HASH) === hash('sha256', $document));
    $check('the source is admin', $meta($shellId, InternalMetaRegistrar::SOURCE_TYPE) === 'admin');
    $check('the tag of the frontmatter was set', has_term($tagSlug, TaxonomyRegistrar::TECH_TAG, $shellId));
    $check('no feedback was left behind', get_transient($feedbackKey($shellId)) === false);

    $syncedAt = $meta($shellId, InternalMetaRegistrar::LAST_SYNC_AT);
    $submit($shellId, $document);
    $check('saving the same document again writes nothing', $meta($shellId, InternalMetaRegistrar::LAST_SYNC_AT) === $syncedAt && get_transient($feedbackKey($shellId)) === false);

    // 3. The real hook: a save through WordPress fires save_post_project with our form ----------

    $edited = str_replace('Un paragraphe', 'Un paragraphe modifié', $document);
    $_POST = [
        EditorMetaBox::NONCE_FIELD => wp_create_nonce('portfolio_md_save_' . $shellId),
        EditorMetaBox::FIELD => wp_slash($edited),
    ];
    try {
        wp_update_post(['ID' => $shellId, 'post_title' => 'Titre tapé dans le champ'], true);
    } finally {
        $_POST = [];
    }
    $post = get_post($shellId);
    $check('the typed title gave way to the document', $post->post_title === 'Projet de fumée (admin)', $post->post_title);
    $check('the edited body was rendered', str_contains($post->post_content, 'Un paragraphe modifié'));
    $check('the stored Markdown follows', $meta($shellId, InternalMetaRegistrar::MD_SOURCE) === $edited);

    // 4. Git and the admin edit: no false conflict, a real one, and --force --------------------

    $git = $service->import($edited, new ImportOptions(), 'zz-smoke-admin');
    $check('the same text imported from Git is unchanged, not a conflict', $git->outcome === ImportOutcome::Unchanged, $git->outcome->value);
    $changed = str_replace('Un paragraphe modifié', 'Un paragraphe changé dans Git', $edited);
    $conflict = $service->import($changed, new ImportOptions(), 'zz-smoke-admin');
    $check('a changed file conflicts with the admin edit', $conflict->outcome === ImportOutcome::Conflict, $conflict->outcome->value);
    $forced = $service->import($changed, new ImportOptions(force: true), 'zz-smoke-admin');
    $check('--force overwrites it and records git', $forced->outcome === ImportOutcome::Updated && $meta($shellId, InternalMetaRegistrar::SOURCE_TYPE) === 'git', $forced->outcome->value);

    // 5. A URL slug changed behind the document's back is not a rename: the document wins ------

    wp_update_post(['ID' => $shellId, 'post_name' => 'zz-smoke-drifted'], true);
    $check('WordPress let the URL slug drift', get_post($shellId)->post_name === 'zz-smoke-drifted');
    $current = str_replace('Un paragraphe changé dans Git', 'Un paragraphe après la dérive', $changed);
    $submit($shellId, $current);
    $check('the next write of the document puts its slug back', get_post($shellId)->post_name === 'zz-smoke-admin' && get_transient($feedbackKey($shellId)) === false, (string) get_post($shellId)->post_name);

    // 6. The guards, through the box -------------------------------------------------------------

    $other = str_replace(['zz-smoke-admin"', 'Projet de fumée (admin)'], ['zz-smoke-other"', 'Autre projet de fumée'], $current);
    $otherResult = $service->import($other, new ImportOptions());
    if ($otherResult->postId !== null) {
        $createdPosts[] = $otherResult->postId;
    }

    $submit($shellId, $other);
    $feedback = get_transient($feedbackKey($shellId));
    $check('a slug owned by another post is refused', is_array($feedback) && str_contains((string) $feedback['message'], 'already belongs to post'), is_array($feedback) ? (string) $feedback['message'] : 'no feedback');
    $check('the refused text is kept for the author', is_array($feedback) && $feedback['markdown'] === $other);
    $check('the post was not touched', get_post($shellId)->post_name === 'zz-smoke-admin' && get_post($shellId)->post_status === 'publish');
    delete_transient($feedbackKey($shellId));

    $submit($shellId, str_replace('zz-smoke-admin"', 'zz-smoke-renamed"', $current));
    $feedback = get_transient($feedbackKey($shellId));
    $check('changing the slug line of a post that holds a document is refused', is_array($feedback) && str_contains((string) $feedback['message'], 'holds a document whose slug is "zz-smoke-admin" and keeps it'), is_array($feedback) ? (string) $feedback['message'] : 'no feedback');
    $check('its slug did not change', get_post($shellId)->post_name === 'zz-smoke-admin');
    delete_transient($feedbackKey($shellId));

    $article = "---\ntitle: \"Article de fumée\"\nslug: \"zz-smoke-admin\"\ntype: \"article\"\n---\n\nCorps.\n";
    $submit($shellId, $article);
    $feedback = get_transient($feedbackKey($shellId));
    $check('a document of another type is refused', is_array($feedback) && str_contains((string) $feedback['message'], 'is not an existing article'), is_array($feedback) ? (string) $feedback['message'] : 'no feedback');
    $check('the post is still a project', get_post($shellId)->post_type === PostTypeRegistrar::PROJECT);
    delete_transient($feedbackKey($shellId));

    $freshId = wp_insert_post(['post_type' => PostTypeRegistrar::PROJECT, 'post_status' => 'publish', 'post_title' => 'Fraîche', 'post_name' => 'zz-smoke-fresh'], true);
    if (is_int($freshId) && $freshId > 0) {
        $createdPosts[] = $freshId;
        $submit($freshId, 'pas de frontmatter');
        $feedback = get_transient($feedbackKey($freshId));
        $check('a broken document on a post without document is refused with a reason', is_array($feedback) && (string) $feedback['message'] !== '' && $feedback['markdown'] === "pas de frontmatter\n");
        $check('that post went back to draft: nothing goes live without a document', get_post_status($freshId) === 'draft', (string) get_post_status($freshId));
        delete_transient($feedbackKey($freshId));
    } else {
        $check('a fresh post can be created', false);
    }

    // 7. A save that does not come from the box is left to WordPress ---------------------------

    wp_update_post(['ID' => $shellId, 'post_title' => 'Titre de Quick Edit'], true);
    $check('a save without the box (REST, CLI) is left alone', get_post($shellId)->post_title === 'Titre de Quick Edit');

    // 8. The box shows the stored document --------------------------------------------------------

    $html = $renderBox($shellId);
    $check('the box renders a nonce and the stored document', str_contains($html, 'name="' . EditorMetaBox::NONCE_FIELD . '"') && str_contains($html, esc_textarea($current)));

    // 9. The paste page ------------------------------------------------------------------------

    $pasted = str_replace(['zz-smoke-admin"', 'Projet de fumée (admin)'], ['zz-smoke-pasted"', 'Projet collé'], $current);
    $paste = static function (string $mode, string $markdown) use ($page): string {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['portfolio_md_nonce' => wp_create_nonce('portfolio_md_import'), 'markdown' => wp_slash($markdown), 'mode' => $mode];
        $_REQUEST = $_POST;
        try {
            $page->handleSubmission();
            ob_start();
            $page->render();

            return (string) ob_get_clean();
        } finally {
            $_POST = [];
            $_REQUEST = [];
            unset($_SERVER['REQUEST_METHOD']);
        }
    };

    $html = $paste('check', $pasted);
    $check('the paste page checks a document without writing', str_contains($html, 'serait créé') && $countBySlug(PostTypeRegistrar::PROJECT, 'zz-smoke-pasted') === 0);

    $html = $paste('import', $pasted);
    $pastedId = get_posts(['post_type' => PostTypeRegistrar::PROJECT, 'name' => 'zz-smoke-pasted', 'post_status' => 'any', 'numberposts' => 1])[0]->ID ?? 0;
    if ($pastedId > 0) {
        $createdPosts[] = $pastedId;
    }
    $check('the paste page creates the post', $pastedId > 0 && str_contains($html, "Créé : project/zz-smoke-pasted (post {$pastedId})"));
    $check('with paste as its source', $meta($pastedId, InternalMetaRegistrar::SOURCE_TYPE) === 'paste');
    $check('and offers the edit and view links', str_contains($html, '>Modifier</a>') && str_contains($html, '>Voir</a>'));

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $page->addMenu();
    $check('the page hangs under the plugin menu', isset($GLOBALS['admin_page_hooks'][ImportPage::SLUG]));
} finally {
    foreach ($createdPosts as $postId) {
        delete_transient($feedbackKey($postId));
        wp_delete_post($postId, true);
    }
    $tag = get_term_by('slug', $tagSlug, TaxonomyRegistrar::TECH_TAG);
    if ($tag instanceof WP_Term) {
        wp_delete_term($tag->term_id, TaxonomyRegistrar::TECH_TAG);
    }
}

foreach (['zz-smoke-shell', 'zz-smoke-admin', 'zz-smoke-drifted', 'zz-smoke-other', 'zz-smoke-fresh', 'zz-smoke-pasted'] as $slug) {
    $check("the smoke post {$slug} is cleaned up", $countBySlug(PostTypeRegistrar::PROJECT, $slug) === 0);
}
foreach (['zz-smoke-page', 'zz-smoke-bare-page'] as $slug) {
    $check("the smoke page {$slug} is cleaned up", $countBySlug('page', $slug) === 0);
}
$check('the smoke tag is cleaned up', get_term_by('slug', $tagSlug, TaxonomyRegistrar::TECH_TAG) === false);

if ($failures > 0) {
    WP_CLI::error("{$failures} check(s) failed");
}

WP_CLI::success('all checks passed');
