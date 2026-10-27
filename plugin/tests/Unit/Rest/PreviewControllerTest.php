<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Rest\Controller\PreviewController;
use Voltz\PortfolioMd\Service\PreviewService;
use Voltz\PortfolioMd\Storage\HtmlSanitizer;
use Voltz\PortfolioMd\Tests\Support\InMemorySkillTermGateway;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;
use WP_REST_Request;

/**
 * The preview route with the real service behind and stubbed WordPress
 * functions: how it is registered, who may call it, what it answers.
 */
#[CoversClass(PreviewController::class)]
#[CoversClass(HtmlSanitizer::class)]
final class PreviewControllerTest extends TestCase
{
    private const DOCUMENT = "---\ntitle: \"Article\"\nslug: \"article\"\ntype: \"article\"\ntags: [php]\n---\n\nUn paragraphe.\n";

    private PreviewController $controller;

    protected function setUp(): void
    {
        WordPressSpy::reset();

        $this->controller = new PreviewController(new PreviewService(
            new FrontmatterParser(),
            new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
            new InMemorySkillTermGateway(),
        ));
    }

    #[Test]
    public function registers_a_post_route_that_requires_a_bounded_document(): void
    {
        $this->controller->register('portfolio/v1');

        self::assertCount(1, WordPressSpy::$restRoutes);
        $route = WordPressSpy::$restRoutes[0];
        self::assertSame('portfolio/v1', $route['namespace']);
        self::assertSame('/preview', $route['route']);
        self::assertSame('POST', $route['args']['methods']);
        self::assertSame([$this->controller, 'handle'], $route['args']['callback']);
        self::assertSame([$this->controller, 'permission'], $route['args']['permission_callback']);
        self::assertSame(['markdown'], array_keys($route['args']['args']));
        self::assertTrue($route['args']['args']['markdown']['required']);
        self::assertSame('string', $route['args']['args']['markdown']['type']);
        self::assertSame(1_000_000, $route['args']['args']['markdown']['maxLength']);
    }

    #[Test]
    public function the_route_is_open_to_whoever_may_edit_posts(): void
    {
        WordPressSpy::$capabilityResults['edit_posts'] = true;
        self::assertTrue($this->controller->permission());

        WordPressSpy::$capabilityResults['edit_posts'] = false;
        self::assertFalse($this->controller->permission());

        self::assertSame([['edit_posts'], ['edit_posts']], WordPressSpy::$currentUserCanCalls);
    }

    #[Test]
    public function answers_the_rendering_as_the_site_would_print_it(): void
    {
        WordPressSpy::$filterResults['do_blocks'] = static fn (string $html): string => '<div class="blocks">' . $html . '</div>';
        WordPressSpy::$filterResults['wptexturize'] = static fn (string $html): string => str_replace('"', '&#8220;', $html);

        $response = $this->controller->handle(self::request(self::DOCUMENT));
        $data = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertTrue($data['importable']);
        self::assertSame([], $data['problems']);
        self::assertSame('Article', $data['title']);
        self::assertSame(['type' => 'article', 'slug' => 'article', 'date' => '', 'skills' => '', 'tags' => 'php'], (array) $data['summary']);
        self::assertStringStartsWith('<div class=&#8220;blocks&#8220;><!-- wp:paragraph -->', $data['html']);
        self::assertStringContainsString('<p>Un paragraphe.</p>', $data['html']);

        // The kses pass of the store first, with the task-list checkbox allowed, then the front end's text passes in order.
        self::assertCount(1, WordPressSpy::$ksesCalls);
        self::assertStringContainsString('<p>Un paragraphe.</p>', WordPressSpy::$ksesCalls[0]['content']);
        self::assertSame(['type' => true, 'checked' => true, 'disabled' => true], WordPressSpy::$ksesCalls[0]['allowed']['input']);
        self::assertSame(['do_blocks', 'wptexturize', 'convert_smilies'], array_column(WordPressSpy::$appliedFilters, 'hook'));
        self::assertSame(WordPressSpy::$ksesCalls[0]['content'], WordPressSpy::$appliedFilters[0]['value']);
    }

    #[Test]
    public function never_runs_the_content_chain_so_shortcodes_and_embeds_stay_as_typed(): void
    {
        $data = $this->controller->handle(self::request(str_replace('Un paragraphe.', "[embed]http://example.com/[/embed]\n\n[gallery]", self::DOCUMENT)))->get_data();

        self::assertNotContains('the_content', array_column(WordPressSpy::$appliedFilters, 'hook'));
        self::assertStringContainsString('<p>[embed]http://example.com/[/embed]</p>', $data['html']);
        self::assertStringContainsString('<p>[gallery]</p>', $data['html']);
    }

    #[Test]
    public function a_refused_document_answers_no_html_and_the_reason(): void
    {
        $data = $this->controller->handle(self::request("A text without frontmatter.\n"))->get_data();

        self::assertFalse($data['importable']);
        self::assertNull($data['html']);
        self::assertNull($data['title']);
        self::assertSame([], (array) $data['summary']);
        self::assertCount(1, $data['problems']);
        self::assertSame([], WordPressSpy::$ksesCalls);
        self::assertSame([], WordPressSpy::$appliedFilters);
    }

    #[Test]
    public function a_document_with_problems_is_rendered_and_flagged_at_once(): void
    {
        $data = $this->controller->handle(self::request(str_replace('tags: [php]', "skills: [\"4.0.2/E99\"]", self::DOCUMENT)))->get_data();

        self::assertFalse($data['importable']);
        self::assertStringContainsString('4.0.2/E99', $data['problems'][0]);
        self::assertStringContainsString('<p>Un paragraphe.</p>', $data['html']);
    }

    #[Test]
    public function a_missing_document_is_treated_as_an_empty_one(): void
    {
        $data = $this->controller->handle(new WP_REST_Request('POST', '/portfolio/v1/preview'))->get_data();

        self::assertFalse($data['importable']);
        self::assertNull($data['html']);
    }

    private static function request(string $markdown): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/portfolio/v1/preview');
        $request->set_param('markdown', $markdown);

        return $request;
    }
}
