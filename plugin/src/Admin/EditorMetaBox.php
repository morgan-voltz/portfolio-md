<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Admin;

use RuntimeException;
use Throwable;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\InvalidFrontmatterException;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Parsing\MalformedDocumentException;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ImportException;
use Voltz\PortfolioMd\Service\ImportOptions;
use WP_Post;

/**
 * The Markdown box on the edit screen of an article or a project: the first
 * door of 01-plugin-php.md §6.1.
 *
 * The box shows the whole document, frontmatter included, reloaded from
 * `_portfolio_md_source` at each visit, in a textarea that is both the
 * form field and the fallback: Admin\EditorAssets mounts the CodeMirror
 * editor and the preview on it when the bundle is built. When the post is
 * saved, WordPress writes its own fields first and then fires
 * `save_post_{type}`; the handler reads the submitted text and hands it to
 * the import service with the post as target and `admin` as source, so
 * that the document rewrites the post (title, slug, date, content, terms,
 * metas) and the source_of_truth rule of ADR-005 then protects that edit
 * from a Git import.
 *
 * A document that is refused leaves the post as it was, except that a post
 * which holds no document yet is put back to draft: WordPress may just have
 * published it, and nothing goes live without a document. The rejected text
 * and the reason are kept for ten minutes and shown in the box at the next
 * visit, so that nothing typed is lost.
 *
 * Saves that do not come from this box (Quick Edit, bulk edit, the REST
 * API, WP-CLI, autosaves) carry no nonce of ours and are left alone; the
 * wp_update_post() of the import itself fires the hook again and is left
 * alone too.
 */
final class EditorMetaBox
{
    public const ID = 'portfolio-md-source';
    public const FIELD = 'portfolio_md_source';
    public const NONCE_FIELD = 'portfolio_md_nonce';

    private const NONCE_ACTION = 'portfolio_md_save_';
    private const FEEDBACK_TRANSIENT = 'portfolio_md_feedback_';

    /** How long a refused document waits for its author to come back, in seconds. */
    private const FEEDBACK_TTL = 600;

    private bool $importing = false;

    public function __construct(private readonly ContentImportService $service)
    {
    }

    public function hook(): void
    {
        foreach ([PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT] as $postType) {
            add_action("add_meta_boxes_{$postType}", [$this, 'addMetaBox']);
            add_action("save_post_{$postType}", [$this, 'save'], 10, 2);
        }
    }

    /** Action `add_meta_boxes_{type}`. */
    public function addMetaBox(WP_Post $post): void
    {
        add_meta_box(self::ID, __('Document Markdown', 'portfolio-md'), [$this, 'render'], $post->post_type, 'normal', 'high');
    }

    /** Callback of the meta box: the document, or what was refused at the last save. */
    public function render(WP_Post $post): void
    {
        $feedback = $this->takeFeedback($post->ID);
        $stored = get_post_meta($post->ID, InternalMetaRegistrar::MD_SOURCE, true);
        $text = $feedback['markdown'] ?? (is_string($stored) && $stored !== '' ? $stored : self::template($post));

        wp_nonce_field(self::NONCE_ACTION . $post->ID, self::NONCE_FIELD);

        if ($feedback !== null) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($feedback['message']) . '</p><p>'
                . esc_html__('Le texte ci-dessous est celui qui a été refusé ; rechargez la page pour retrouver le document enregistré.', 'portfolio-md')
                . '</p></div>';
        }

        echo '<p class="description">'
            . esc_html__('Le document entier, frontmatter compris. À l’enregistrement, il réécrit le titre, le slug, la date, le contenu, les tags, le statut, les compétences et les champs du post : rien de tout cela ne se modifie ailleurs.', 'portfolio-md')
            . '</p>';
        echo '<textarea name="' . esc_attr(self::FIELD) . '" id="' . esc_attr(self::FIELD) . '" rows="28" class="large-text code" spellcheck="false" ' . EditorAssets::MARKER . '="1">'
            . "\n" . esc_textarea($text) . '</textarea>';
    }

    /** Action `save_post_{type}`, after WordPress wrote the post row. */
    public function save(int $postId, WP_Post $post): void
    {
        if ($this->importing || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        if (in_array($post->post_status, ['auto-draft', 'trash'], true)) {
            return;
        }

        $nonce = $_POST[self::NONCE_FIELD] ?? null;
        if (!is_string($nonce) || wp_verify_nonce($nonce, self::NONCE_ACTION . $postId) === false) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $submitted = $_POST[self::FIELD] ?? '';
        $markdown = SubmittedMarkdown::normalize(is_string($submitted) ? wp_unslash($submitted) : '');

        $this->importing = true;
        try {
            $this->service->import($markdown, new ImportOptions(source: ContentSource::Admin), targetPostId: $postId);
        } catch (MalformedDocumentException | InvalidFrontmatterException | ImportException | RuntimeException $failure) {
            $this->rememberFailure($postId, self::reasonFor($failure), $markdown);
            if (!self::holdsDocument($postId)) {
                // WordPress may just have published an empty post: nothing goes live without a document.
                wp_update_post(['ID' => $postId, 'post_status' => 'draft']);
            }
        } finally {
            $this->importing = false;
        }
    }

    /**
     * A starting point for a post that holds no document yet: the keys a
     * document of this type needs, to be filled in.
     */
    public static function templateFor(ContentType $type, string $today): string
    {
        $lines = ['---', 'title: ""', 'slug: ""', "type: \"{$type->value}\"", "date: {$today}"];
        if ($type === ContentType::Project) {
            $lines[] = 'status: "en-cours"';
            $lines[] = 'skills: []';
        }
        $lines[] = 'tags: []';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private static function template(WP_Post $post): string
    {
        $type = $post->post_type === PostTypeRegistrar::PROJECT ? ContentType::Project : ContentType::Article;

        return self::templateFor($type, (string) wp_date('Y-m-d'));
    }

    /**
     * The transient under which a refused document waits for its author:
     * one per post and per user, so that nobody sees another's text.
     */
    public static function feedbackKey(int $postId, int $userId): string
    {
        return self::FEEDBACK_TRANSIENT . $postId . '_' . $userId;
    }

    private static function holdsDocument(int $postId): bool
    {
        $hash = get_post_meta($postId, InternalMetaRegistrar::MD_HASH, true);

        return is_string($hash) && $hash !== '';
    }

    /**
     * The reason shown to the author. A refusal that names another post
     * (slug already taken, post in the trash) is detailed only to a user
     * who may edit that post; the others are not told which posts exist.
     */
    private static function reasonFor(Throwable $failure): string
    {
        if ($failure instanceof ImportException && $failure->otherPostId !== null && !current_user_can('edit_post', $failure->otherPostId)) {
            return __('Ce slug est déjà pris par un autre contenu que vous ne pouvez pas modifier : donnez-en un autre à ce document.', 'portfolio-md');
        }

        return $failure->getMessage();
    }

    private function rememberFailure(int $postId, string $message, string $markdown): void
    {
        set_transient(self::feedbackKey($postId, get_current_user_id()), ['message' => $message, 'markdown' => $markdown], self::FEEDBACK_TTL);
    }

    /**
     * @return array{message: string, markdown: string}|null
     */
    private function takeFeedback(int $postId): ?array
    {
        $key = self::feedbackKey($postId, get_current_user_id());
        $feedback = get_transient($key);
        if (!is_array($feedback) || !isset($feedback['message'], $feedback['markdown'])) {
            return null;
        }
        delete_transient($key);

        return ['message' => (string) $feedback['message'], 'markdown' => (string) $feedback['markdown']];
    }
}
