<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Admin;

use RuntimeException;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportException;
use Voltz\PortfolioMd\Service\ImportOptions;

/**
 * The paste page: the third door of 01-plugin-php.md §6.3.
 *
 * A page of the plugin's own admin menu with one large textarea. The pasted
 * document goes through the very same service as the files of
 * `wp portfolio import`, with `paste` as source: same validation, same
 * rendering, same conflict rule, same guards. The post is found by its
 * slug, as with a file. The page can also check a document without writing
 * anything (a dry run) and create the post as a draft. A pasted document is
 * a human act and always wins over the last write (ADR-005), so there is no
 * force switch.
 *
 * The form posts to itself: the result is rendered on the same request,
 * above the pasted text, so that a refused document is fixed in place.
 * Reaching the page takes `edit_others_posts`: a pasted document updates
 * whichever post carries its slug, whoever wrote it.
 */
final class ImportPage
{
    public const SLUG = 'portfolio-md-import';
    public const CAPABILITY = 'edit_others_posts';

    private const NONCE_ACTION = 'portfolio_md_import';
    private const NONCE_FIELD = 'portfolio_md_nonce';

    private ?ImportFeedback $feedback = null;

    private string $markdown = '';

    private bool $draft = false;

    public function __construct(private readonly ContentImportService $service)
    {
    }

    public function hook(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
    }

    /** Action `admin_menu`. */
    public function addMenu(): void
    {
        $hook = add_menu_page(
            __('Portfolio MD', 'portfolio-md'),
            __('Portfolio', 'portfolio-md'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render'],
            'dashicons-media-text',
            7,
        );
        // Same slug as the menu: names the first entry instead of adding a second one.
        add_submenu_page(self::SLUG, __('Importer un document Markdown', 'portfolio-md'), __('Importer', 'portfolio-md'), self::CAPABILITY, self::SLUG, [$this, 'render']);

        if (is_string($hook) && $hook !== '') {
            add_action("load-{$hook}", [$this, 'handleSubmission']);
        }
    }

    /** Action `load-{page}`, before any output: imports the pasted document when the form was sent. */
    public function handleSubmission(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Vous n’avez pas le droit d’importer des documents.', 'portfolio-md'), 403);
        }

        $submitted = $_POST['markdown'] ?? '';
        $this->markdown = SubmittedMarkdown::normalize(is_string($submitted) ? wp_unslash($submitted) : '');
        $this->draft = isset($_POST['draft']);
        $dryRun = ($_POST['mode'] ?? '') === 'check';

        $options = new ImportOptions(
            source: ContentSource::Paste,
            dryRun: $dryRun,
            postStatus: $this->draft ? 'draft' : 'publish',
        );

        try {
            $this->feedback = ImportFeedback::forResult($this->service->import($this->markdown, $options), $dryRun);
        } catch (MalformedDocumentException | InvalidFrontmatterException | ImportException | RuntimeException $failure) {
            $this->feedback = ImportFeedback::forFailure($failure);
        }
    }

    /** Callback of the page. */
    public function render(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Importer un document Markdown', 'portfolio-md') . '</h1>';
        echo '<p>'
            . esc_html__('Collez un document entier, frontmatter compris. Il passe par le même pipeline que les fichiers du dépôt : le post est retrouvé par son slug, créé s’il n’existe pas, mis à jour sinon ; un document identique à celui déjà importé ne réécrit rien.', 'portfolio-md')
            . '</p>';
        $this->renderFeedback();

        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<textarea name="markdown" rows="24" class="large-text code" spellcheck="false" placeholder="---&#10;title: &quot;…&quot;&#10;slug: &quot;…&quot;&#10;type: &quot;article&quot;&#10;---">'
            . "\n" . esc_textarea($this->markdown) . '</textarea>';
        echo '<p><label><input type="checkbox" name="draft" value="1"' . checked($this->draft, true, false) . '> '
            . esc_html__('Créer le post en brouillon (un post existant garde son statut)', 'portfolio-md')
            . '</label></p>';
        echo '<p class="submit">';
        echo '<button type="submit" name="mode" value="import" class="button button-primary">' . esc_html__('Importer', 'portfolio-md') . '</button> ';
        echo '<button type="submit" name="mode" value="check" class="button">' . esc_html__('Vérifier sans écrire', 'portfolio-md') . '</button>';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    private function renderFeedback(): void
    {
        if ($this->feedback === null) {
            return;
        }

        $links = '';
        if ($this->feedback->postId !== null) {
            $edit = get_edit_post_link($this->feedback->postId);
            if (is_string($edit) && $edit !== '') {
                $links .= ' <a href="' . esc_url($edit) . '">' . esc_html__('Modifier', 'portfolio-md') . '</a>';
            }
            $view = get_permalink($this->feedback->postId);
            if (is_string($view) && $view !== '') {
                $links .= ' <a href="' . esc_url($view) . '">' . esc_html__('Voir', 'portfolio-md') . '</a>';
            }
        }

        echo '<div class="notice notice-' . esc_attr($this->feedback->level) . '"><p>' . esc_html($this->feedback->message) . $links . '</p></div>';
    }
}
