<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Admin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Admin\EditorAssets;
use Voltz\PortfolioMd\Meta\InternalMetaRegistrar;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

/**
 * The editor script and its configuration: on which screens they load,
 * what they are told, and what happens when the bundle is not built.
 */
#[CoversClass(EditorAssets::class)]
final class EditorAssetsTest extends TestCase
{
    private string $directory;

    private EditorAssets $assets;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        $_GET = [];
        $_POST = [];

        $this->directory = sys_get_temp_dir() . '/portfolio-md-assets-' . bin2hex(random_bytes(4));
        mkdir($this->directory . '/assets/dist', 0o777, true);
        mkdir($this->directory . '/assets/css', 0o777, true);
        file_put_contents($this->directory . '/assets/dist/admin-editor.js', '/* bundle */');
        file_put_contents($this->directory . '/assets/css/admin-editor.css', '/* style */');

        $this->assets = new EditorAssets($this->directory . '/portfolio-md.php');
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        foreach (['/assets/dist/admin-editor.js', '/assets/css/admin-editor.css'] as $file) {
            if (file_exists($this->directory . $file)) {
                unlink($this->directory . $file);
            }
        }
        foreach (['/assets/dist', '/assets/css', '/assets', ''] as $dir) {
            rmdir($this->directory . $dir);
        }
    }

    #[Test]
    public function hooks_the_admin_script_loader(): void
    {
        $this->assets->hook();

        self::assertCount(1, WordPressSpy::hooksOn('action', 'admin_enqueue_scripts'));
    }

    #[Test]
    public function loads_the_editor_on_the_edit_screen_of_a_project(): void
    {
        WordPressSpy::$currentScreen = (object) ['post_type' => 'project'];

        $this->assets->enqueue('post.php');

        $script = WordPressSpy::$enqueuedScripts['portfolio-md-editor'] ?? null;
        self::assertNotNull($script);
        self::assertSame('http://example.test/wp-content/plugins/' . basename($this->directory) . '/assets/dist/admin-editor.js', $script['src']);
        self::assertSame(['wp-api-fetch'], $script['deps']);
        self::assertSame(['in_footer' => true], $script['args']);
        self::assertSame((string) filemtime($this->directory . '/assets/dist/admin-editor.js'), $script['ver']);

        $style = WordPressSpy::$enqueuedStyles['portfolio-md-editor'] ?? null;
        self::assertNotNull($style);
        self::assertStringEndsWith('/assets/css/admin-editor.css', $style['src']);
    }

    #[Test]
    public function tells_the_script_where_the_preview_is_and_how_to_dress_it(): void
    {
        WordPressSpy::$currentScreen = (object) ['post_type' => 'article'];

        $this->assets->enqueue('post-new.php');

        self::assertCount(1, WordPressSpy::$inlineScripts);
        $inline = WordPressSpy::$inlineScripts[0];
        self::assertSame('portfolio-md-editor', $inline['handle']);
        self::assertSame('before', $inline['position']);
        self::assertStringStartsWith('window.portfolioMdEditor = ', $inline['data']);
        self::assertStringEndsWith(';', $inline['data']);

        $config = json_decode(substr($inline['data'], strlen('window.portfolioMdEditor = '), -1), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/portfolio/v1/preview', $config['previewPath']);
        self::assertSame([
            'http://example.test/wp-includes/css/dist/block-library/style.min.css?ver=7.1.3',
            'http://example.test/wp-includes/css/dist/block-library/theme.min.css?ver=7.1.3',
        ], $config['stylesheets']);
        self::assertSame('Aperçu', $config['labels']['preview']);
        self::assertSame('Masquer l’aperçu', $config['labels']['hidePreview']);
        self::assertSame(['type', 'slug', 'date', 'status', 'skills', 'tags'], array_keys($config['labels']['summary']));
        self::assertSame('Compétences', $config['labels']['summary']['skills']);
    }

    #[Test]
    public function loads_the_editor_on_the_edit_screen_of_a_page_that_holds_a_document_only(): void
    {
        WordPressSpy::$postMetaValues[9][InternalMetaRegistrar::MD_HASH] = str_repeat('c', 64);
        WordPressSpy::$currentScreen = (object) ['post_type' => 'page'];

        $_GET['post'] = '9';
        $this->assets->enqueue('post.php');
        self::assertArrayHasKey('portfolio-md-editor', WordPressSpy::$enqueuedScripts);

        WordPressSpy::$enqueuedScripts = [];
        $_GET['post'] = '10';
        $this->assets->enqueue('post.php');
        self::assertSame([], WordPressSpy::$enqueuedScripts);

        $_GET = [];
        $this->assets->enqueue('post-new.php');
        self::assertSame([], WordPressSpy::$enqueuedScripts);

        // "Add new page" with a documented page's id smuggled into the query: still a new page.
        $_GET['post'] = '9';
        $this->assets->enqueue('post-new.php');
        self::assertSame([], WordPressSpy::$enqueuedScripts);
    }

    #[Test]
    public function loads_the_editor_on_the_paste_page_without_looking_at_a_screen(): void
    {
        WordPressSpy::$currentScreen = null;

        $this->assets->enqueue('toplevel_page_portfolio-md-import');

        self::assertArrayHasKey('portfolio-md-editor', WordPressSpy::$enqueuedScripts);
    }

    #[Test]
    public function leaves_other_post_types_and_other_screens_alone(): void
    {
        WordPressSpy::$currentScreen = (object) ['post_type' => 'post'];
        $this->assets->enqueue('post.php');

        WordPressSpy::$currentScreen = (object) ['post_type' => 'project'];
        $this->assets->enqueue('edit.php');

        WordPressSpy::$currentScreen = null;
        $this->assets->enqueue('post.php');

        self::assertSame([], WordPressSpy::$enqueuedScripts);
        self::assertSame([], WordPressSpy::$enqueuedStyles);
        self::assertSame([], WordPressSpy::$inlineScripts);
    }

    #[Test]
    public function keeps_the_textarea_and_says_how_to_build_when_the_bundle_is_missing(): void
    {
        unlink($this->directory . '/assets/dist/admin-editor.js');
        WordPressSpy::$currentScreen = (object) ['post_type' => 'project'];

        $this->assets->enqueue('post.php');

        self::assertSame([], WordPressSpy::$enqueuedScripts);
        self::assertSame([], WordPressSpy::$enqueuedStyles);
        self::assertCount(1, WordPressSpy::hooksOn('action', 'admin_notices'));

        ob_start();
        $this->assets->missingBundleNotice();
        $notice = (string) ob_get_clean();
        self::assertStringContainsString('notice-warning', $notice);
        self::assertStringContainsString('npm ci &amp;&amp; npm run build', $notice);
        self::assertStringContainsString('plugin/assets', $notice);
    }
}
