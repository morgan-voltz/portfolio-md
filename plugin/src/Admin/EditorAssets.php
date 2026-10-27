<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Admin;

use Voltz\PortfolioMd\Rest\Controller\PreviewController;
use Voltz\PortfolioMd\Rest\RouteRegistrar;

/**
 * The browser side of the Markdown box (01-plugin-php.md §6.1): the
 * CodeMirror 6 editor and the server-rendered preview, loaded on the edit
 * screens of articles and projects, on those of the pages that hold a
 * document, and on the paste page.
 *
 * The textarea of the box stays the form field and the fallback: the
 * script replaces it on the page with the editor, copies every change
 * back into it, and asks `POST /portfolio/v1/preview` for the rendering
 * after each pause in typing. The script is built from assets/src with
 * esbuild into assets/dist (plugin-README.md); when the bundle is not
 * there, the box keeps its textarea and a notice says how to build it.
 *
 * The bundle depends on `wp-api-fetch`, the REST client of WordPress,
 * which carries the cookie nonce and renews it when it expires. Its
 * configuration (route, labels, stylesheets of the preview frame) is
 * printed inline before it.
 */
final class EditorAssets
{
    public const HANDLE = 'portfolio-md-editor';

    /** Attribute that marks a textarea the editor mounts on. */
    public const MARKER = 'data-portfolio-md-editor';

    private const SCRIPT = 'assets/dist/admin-editor.js';
    private const STYLE = 'assets/css/admin-editor.css';

    private const EDIT_SCREENS = ['post.php', 'post-new.php'];

    public function __construct(private readonly string $pluginFile)
    {
    }

    public function hook(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /** Action `admin_enqueue_scripts`. */
    public function enqueue(string $hookSuffix): void
    {
        if (!self::wanted($hookSuffix)) {
            return;
        }

        $directory = plugin_dir_path($this->pluginFile);
        if (!is_file($directory . self::SCRIPT)) {
            add_action('admin_notices', [$this, 'missingBundleNotice']);

            return;
        }

        wp_enqueue_style(self::HANDLE, plugins_url(self::STYLE, $this->pluginFile), [], (string) filemtime($directory . self::STYLE));
        wp_enqueue_script(self::HANDLE, plugins_url(self::SCRIPT, $this->pluginFile), ['wp-api-fetch'], (string) filemtime($directory . self::SCRIPT), ['in_footer' => true]);
        wp_add_inline_script(self::HANDLE, 'window.portfolioMdEditor = ' . wp_json_encode(self::config()) . ';', 'before');
    }

    /** Action `admin_notices`, when the bundle is not built. */
    public function missingBundleNotice(): void
    {
        echo '<div class="notice notice-warning"><p><strong>Portfolio MD :</strong> '
            . esc_html__('l’éditeur Markdown n’est pas compilé, la zone de texte simple est utilisée. Lancez', 'portfolio-md')
            . ' <code>npm ci &amp;&amp; npm run build</code> '
            . esc_html__('dans le dossier', 'portfolio-md')
            . ' <code>plugin/assets</code>.</p></div>';
    }

    private static function wanted(string $hookSuffix): bool
    {
        if ($hookSuffix === 'toplevel_page_' . ImportPage::SLUG) {
            return true;
        }
        if (!in_array($hookSuffix, self::EDIT_SCREENS, true)) {
            return false;
        }
        $postType = get_current_screen()?->post_type;
        // A post being created holds no document, whatever the query string says.
        $postId = $hookSuffix === 'post.php' ? EditScreen::requestedPostId() : 0;

        return is_string($postType) && MarkdownPosts::editsDocumentOf($postType, $postId);
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        $suffix = wp_scripts_get_suffix();
        $version = (string) get_bloginfo('version');

        return [
            'previewPath' => '/' . RouteRegistrar::NAMESPACE . PreviewController::ROUTE,
            // The stylesheets of the core blocks, as a classic theme loads them on the front.
            'stylesheets' => [
                add_query_arg('ver', $version, includes_url("css/dist/block-library/style{$suffix}.css")),
                add_query_arg('ver', $version, includes_url("css/dist/block-library/theme{$suffix}.css")),
            ],
            'labels' => [
                'preview' => __('Aperçu', 'portfolio-md'),
                'showPreview' => __('Afficher l’aperçu', 'portfolio-md'),
                'hidePreview' => __('Masquer l’aperçu', 'portfolio-md'),
                'rendering' => __('Rendu en cours…', 'portfolio-md'),
                'empty' => __('Rien à prévisualiser.', 'portfolio-md'),
                'refused' => __('Ce document serait refusé à l’enregistrement :', 'portfolio-md'),
                'unavailable' => __('Aperçu indisponible :', 'portfolio-md'),
                'untitled' => __('(sans titre)', 'portfolio-md'),
                'summary' => [
                    'type' => __('Type', 'portfolio-md'),
                    'slug' => __('Slug', 'portfolio-md'),
                    'date' => __('Date', 'portfolio-md'),
                    'status' => __('Statut', 'portfolio-md'),
                    'skills' => __('Compétences', 'portfolio-md'),
                    'tags' => __('Tags', 'portfolio-md'),
                ],
            ],
        ];
    }
}
