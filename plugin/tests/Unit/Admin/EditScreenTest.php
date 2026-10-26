<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Admin\EditScreen;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;
use WP_Post;

/**
 * The edit screen of an article or a project loses every control that
 * duplicates the document; other post types are left exactly as they are.
 */
#[CoversClass(EditScreen::class)]
final class EditScreenTest extends TestCase
{
    private EditScreen $screen;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        $this->screen = new EditScreen();
    }

    #[Test]
    public function hooks_the_filters_and_actions_it_needs(): void
    {
        $this->screen->hook();

        $names = array_map(static fn (array $hook): string => $hook['type'] . ':' . $hook['hook'], WordPressSpy::$hooks);
        self::assertSame([
            'filter:use_block_editor_for_post_type',
            'filter:get_sample_permalink_html',
            'filter:enter_title_here',
            'filter:post_row_actions',
            'action:load-post.php',
            'action:load-post-new.php',
            'action:add_meta_boxes',
            'filter:bulk_actions-edit-article',
            'filter:bulk_actions-edit-project',
        ], $names);
        self::assertSame(5, WordPressSpy::hooksOn('filter', 'get_sample_permalink_html')[0]['args']);
        self::assertSame(20, WordPressSpy::hooksOn('action', 'add_meta_boxes')[0]['priority']);
    }

    #[Test]
    public function turns_the_block_editor_off_for_articles_and_projects_only(): void
    {
        self::assertFalse($this->screen->useBlockEditor(true, 'article'));
        self::assertFalse($this->screen->useBlockEditor(true, 'project'));
        self::assertTrue($this->screen->useBlockEditor(true, 'post'));
        self::assertFalse($this->screen->useBlockEditor(false, 'page'));
    }

    #[Test]
    public function hides_the_permalink_editor_of_its_posts_only(): void
    {
        $html = '<strong>Permalink:</strong> <a href="…">…</a> <button>Edit</button>';

        self::assertSame('', $this->screen->permalinkHtml($html, 5, null, null, new WP_Post(['ID' => 5, 'post_type' => 'project'])));
        self::assertSame($html, $this->screen->permalinkHtml($html, 6, null, null, new WP_Post(['ID' => 6, 'post_type' => 'page'])));
        self::assertSame($html, $this->screen->permalinkHtml($html, 7, null, null, null));
    }

    #[Test]
    public function tells_where_the_title_comes_from_on_its_posts_only(): void
    {
        self::assertStringContainsString('document Markdown', $this->screen->titlePlaceholder('Add title', new WP_Post(['post_type' => 'article'])));
        self::assertSame('Add title', $this->screen->titlePlaceholder('Add title', new WP_Post(['post_type' => 'post'])));
    }

    #[Test]
    public function withdraws_quick_edit_from_its_rows_only(): void
    {
        $actions = ['edit' => 'Edit', 'inline hide-if-no-js' => 'Quick Edit', 'trash' => 'Trash', 'view' => 'View'];

        self::assertSame(['edit' => 'Edit', 'trash' => 'Trash', 'view' => 'View'], $this->screen->rowActions($actions, new WP_Post(['post_type' => 'article'])));
        self::assertSame($actions, $this->screen->rowActions($actions, new WP_Post(['post_type' => 'post'])));
    }

    #[Test]
    public function withdraws_bulk_edit(): void
    {
        self::assertSame(['trash' => 'Move to Trash'], $this->screen->bulkActions(['edit' => 'Edit', 'trash' => 'Move to Trash']));
    }

    #[Test]
    public function removes_the_content_editor_from_the_screens_of_its_types_only(): void
    {
        WordPressSpy::$currentScreen = (object) ['post_type' => 'project'];
        $this->screen->trimCurrentScreen();
        self::assertSame([['postType' => 'project', 'feature' => 'editor']], WordPressSpy::$removedSupports);

        WordPressSpy::$removedSupports = [];
        WordPressSpy::$currentScreen = (object) ['post_type' => 'post'];
        $this->screen->trimCurrentScreen();
        self::assertSame([], WordPressSpy::$removedSupports);

        WordPressSpy::$currentScreen = null;
        $this->screen->trimCurrentScreen();
        self::assertSame([], WordPressSpy::$removedSupports);
    }

    #[Test]
    public function removes_the_slug_and_taxonomy_boxes_of_its_types_only(): void
    {
        $this->screen->removeMetaBoxes('project');

        self::assertSame([
            ['id' => 'slugdiv', 'screen' => 'project', 'context' => 'normal'],
            ['id' => 'tagsdiv-tech_tag', 'screen' => 'project', 'context' => 'side'],
            ['id' => 'tagsdiv-project_status', 'screen' => 'project', 'context' => 'side'],
            ['id' => 'skilldiv', 'screen' => 'project', 'context' => 'side'],
        ], WordPressSpy::$removedMetaBoxes);

        WordPressSpy::$removedMetaBoxes = [];
        $this->screen->removeMetaBoxes('page');
        self::assertSame([], WordPressSpy::$removedMetaBoxes);
    }
}
