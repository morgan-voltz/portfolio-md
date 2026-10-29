<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Rest\Controller;

use Voltz\PortfolioMd\Rest\RestController;
use Voltz\PortfolioMd\Service\PreviewService;
use Voltz\PortfolioMd\Storage\HtmlSanitizer;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /portfolio/v1/preview`: the rendering of a document for the admin
 * editor (01-plugin-php.md §6.1), without writing anything.
 *
 * The request carries the whole document (`markdown`); the answer carries
 * the title, the HTML as the site would print it, a summary of the
 * frontmatter and the problems the import would raise. The HTML goes
 * through the same kses pass as the stored content, then through the
 * rendering of the blocks and the typography of the front end (do_blocks,
 * wptexturize, convert_smilies), and not through the whole `the_content`
 * chain: do_shortcode and the oEmbed handlers hooked there run registered
 * shortcodes and fetch remote URLs (writing an oEmbed cache post) for a
 * text that is merely being typed, and whatever a theme or a plugin hooks
 * there expects a post in the loop. What the preview shows is what the
 * site shows, up to the theme's stylesheet and the shortcodes, which the
 * preview leaves as typed.
 *
 * The route takes `edit_posts`: it writes nothing, runs nothing of the
 * document, and reveals nothing but the rendering of what the caller just
 * typed, which is what the editor of any article or project needs. Cookie
 * authentication requires the REST nonce, which `wp-api-fetch` adds and
 * renews for the editor.
 */
final class PreviewController implements RestController
{
    public const ROUTE = '/preview';
    public const CAPABILITY = 'edit_posts';

    /** Longest document accepted, in characters: a hundred times the longest sheet. */
    public const MAX_LENGTH = 1_000_000;

    public function __construct(private readonly PreviewService $service)
    {
    }

    public function register(string $namespace): void
    {
        register_rest_route($namespace, self::ROUTE, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => [$this, 'permission'],
            'args' => [
                'markdown' => [
                    'description' => 'The whole document, frontmatter included.',
                    'type' => 'string',
                    'required' => true,
                    'maxLength' => self::MAX_LENGTH,
                ],
            ],
        ]);
    }

    /** Permission callback of the route. */
    public function permission(): bool
    {
        return current_user_can(self::CAPABILITY);
    }

    /** Callback of the route. */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $markdown = $request->get_param('markdown');
        $result = $this->service->preview(is_string($markdown) ? $markdown : '');

        $html = $result->html;
        if ($html !== null) {
            $html = self::presentLikeTheSite(HtmlSanitizer::sanitize($html));
        }

        return new WP_REST_Response([
            'importable' => $result->importable(),
            'problems' => $result->problems,
            'title' => $result->title,
            'summary' => (object) $result->summary,
            'html' => $html,
        ]);
    }

    /**
     * The members of the front end's `the_content` chain that only
     * transform the text, in their order on the front end: the blocks are
     * rendered (which is also where block supports add their classes) and
     * the typography is applied. Nothing here runs a shortcode, fetches a
     * URL or writes anything.
     */
    private static function presentLikeTheSite(string $html): string
    {
        return convert_smilies(wptexturize(do_blocks($html)));
    }
}
