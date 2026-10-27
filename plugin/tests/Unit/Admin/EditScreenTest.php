<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Admin\EditScreen;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;
use WP_Post;

/**
 * The edit screen of an article or a project loses every control that
 * duplicates the document, and so does the screen of a page that holds a
 * document; other posts are left exactly as they are.
 */
#[CoversClass(EditScreen::class)]
final class EditScreenTest extends TestCase
{
    private EditScreen $screen;

    /** A page that holds a document, and one that does not. */
    private const DOCUMENTED_PAGE = 9;
    private const BARE_PAGE = 10;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        WordPressSpy::$postMetaValues[self::DOCUMENTED_PAGE][InternalMetaRegistrar::MD_HASH] = str_repeat('a', 64);
        $_GET = [];
        $_POST = [];
        $this->screen = new EditScreen();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
    }

    #[Test]
    public function hooks_the_filters_and_actions_it_needs(): void
    {
        $this->screen->hook();

        $names = array_map(static fn (array $hook): string => $hook['type'] . ':' . $hook['hook'], WordPressSpy::$hooks);
        self::assertSame([
            'filter:use_block_editor_for_post_type',
            'filter:use_block_editor_for_post',
            'filter:get_sample_permalink_html',
            'filter:post_row_actions',
            'filter:page_row_actions',
            'action:load-post.php',
            'action:load-post-new.php',
            'action:add_meta_boxes',
            'filter:bulk_actions-edit-article',
            'filter:bulk_actions-edit-project',
        ], $names);
        self::assertSame(5, WordPressSpy::hooksOn('filter', 'get_sample_permalink_html')[0]['args']);
        self::assertSame(2, WordPressSpy::hooksOn('filter', 'use_block_editor_for_post')[0]['args']);
        self::assertSame(20, WordPressSpy::hooksOn('action', 'add_meta_boxes')[0]['priority']);
        self::assertSame(2, WordPressSpy::hooksOn('action', 'add_meta_boxes')[0]['args']);
    }

    #[Test]
    public function turns_the_block_editor_off_for_a_page_that_holds_a_document_only(): void
    {
        self::assertFalse($this->screen->useBlockEditorForPost(true, new WP_Post(['ID' => self::DOCUMENTED_PAGE, 'post_type' => 'page'])));
        self::assertTrue($this->screen->useBlockEditorForPost(true, new WP_Post(['ID' => self::BARE_PAGE, 'post_type' => 'page'])));
        self::assertFalse($this->screen->useBlockEditorForPost(true, new WP_Post(['ID' => 3, 'post_type' => 'article'])));
        self::assertTrue($this->screen->useBlockEditorForPost(true, new WP_Post(['ID' => self::DOCUMENTED_PAGE, 'post_type' => 'post'])));
    }

    #[Test]
    public function reads_the_post_of_the_request_from_the_query_or_the_form(): void
    {
        self::assertSame(0, EditScreen::requestedPostId());

        $_GET['post'] = '42';
        self::assertSame(42, EditScreen::requestedPostId());

        $_GET = [];
        $_POST['post_ID'] = '43';
        self::assertSame(43, EditScreen::requestedPostId());

        $_POST['post_ID'] = ['not', 'scalar'];
        self::assertSame(0, EditScreen::requestedPostId());
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
        self::assertSame('', $this->screen->permalinkHtml($html, self::DOCUMENTED_PAGE, null, null, new WP_Post(['ID' => self::DOCUMENTED_PAGE, 'post_type' => 'page'])));
        self::assertSame($html, $this->screen->permalinkHtml($html, self::BARE_PAGE, null, null, new WP_Post(['ID' => self::BARE_PAGE, 'post_type' => 'page'])));
        self::assertSame($html, $this->screen->permalinkHtml($html, 7, null, null, null));
    }

    #[Test]
    public function withdraws_quick_edit_from_its_rows_only(): void
    {
        $actions = ['edit' => 'Edit', 'inline hide-if-no-js' => 'Quick Edit', 'trash' => 'Trash', 'view' => 'View'];

        self::assertSame(['edit' => 'Edit', 'trash' => 'Trash', 'view' => 'View'], $this->screen->rowActions($actions, new WP_Post(['post_type' => 'article'])));
        self::assertSame(['edit' => 'Edit', 'trash' => 'Trash', 'view' => 'View'], $this->screen->rowActions($actions, new WP_Post(['ID' => self::DOCUMENTED_PAGE, 'post_type' => 'page'])));
        self::assertSame($actions, $this->screen->rowActions($actions, new WP_Post(['ID' => self::BARE_PAGE, 'post_type' => 'page'])));
        self::assertSame($actions, $this->screen->rowActions($actions, new WP_Post(['post_type' => 'post'])));
    }

    #[Test]
    public function withdraws_bulk_edit(): void
    {
        self::assertSame(['trash' => 'Move to Trash'], $this->screen->bulkActions(['edit' => 'Edit', 'trash' => 'Move to Trash']));
    }

    #[Test]
    public function removes_the_content_editor_and_the_title_field_from_the_screens_of_its_types_only(): void
    {
        WordPressSpy::$currentScreen = (object) ['post_type' => 'project'];
        $this->screen->trimCurrentScreen();
        self::assertSame([
            ['postType' => 'project', 'feature' => 'editor'],
            ['postType' => 'project', 'feature' => 'title'],
        ], WordPressSpy::$removedSupports);

        WordPressSpy::$removedSupports = [];
        WordPressSpy::$currentScreen = (object) ['post_type' => 'post'];
        $this->screen->trimCurrentScreen();
        self::assertSame([], WordPressSpy::$removedSupports);

        WordPressSpy::$currentScreen = null;
        $this->screen->trimCurrentScreen();
        self::assertSame([], WordPressSpy::$removedSupports);
    }

    #[Test]
    public function trims_the_screen_of_a_page_only_when_the_request_names_one_that_holds_a_document(): void
    {
        WordPressSpy::$currentScreen = (object) ['post_type' => 'page'];

        $_GET['post'] = (string) self::DOCUMENTED_PAGE;
        $this->screen->trimCurrentScreen();
        self::assertSame([
            ['postType' => 'page', 'feature' => 'editor'],
            ['postType' => 'page', 'feature' => 'title'],
        ], WordPressSpy::$removedSupports);

        WordPressSpy::$removedSupports = [];
        $_GET['post'] = (string) self::BARE_PAGE;
        $this->screen->trimCurrentScreen();
        self::assertSame([], WordPressSpy::$removedSupports);

        // "Add new page": no post yet, the block editor stays.
        $_GET = [];
        $this->screen->trimCurrentScreen();
        self::assertSame([], WordPressSpy::$removedSupports);

        // The form of a documented page coming back names it by post_ID.
        $_POST['post_ID'] = (string) self::DOCUMENTED_PAGE;
        $this->screen->trimCurrentScreen();
        self::assertCount(2, WordPressSpy::$removedSupports);
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

    #[Test]
    public function removes_the_slug_box_of_a_page_that_holds_a_document_and_nothing_else(): void
    {
        $this->screen->removeMetaBoxes('page', new WP_Post(['ID' => self::DOCUMENTED_PAGE, 'post_type' => 'page']));
        self::assertSame([['id' => 'slugdiv', 'screen' => 'page', 'context' => 'normal']], WordPressSpy::$removedMetaBoxes);

        WordPressSpy::$removedMetaBoxes = [];
        $this->screen->removeMetaBoxes('page', new WP_Post(['ID' => self::BARE_PAGE, 'post_type' => 'page']));
        self::assertSame([], WordPressSpy::$removedMetaBoxes);
    }
}
