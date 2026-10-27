<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Rest\Controller;

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
 * through the same kses pass as the stored content and then through
 * `the_content`, the filter chain of the front end (blocks, texturize,
 * shortcodes, image attributes): what the preview shows is what the site
 * shows, up to the theme's stylesheet.
 *
 * The route takes `edit_posts`: it writes nothing and reveals nothing but
 * the rendering of what the caller just typed, which is what the editor of
 * any article or project needs. Cookie authentication requires the REST
 * nonce, which `wp-api-fetch` adds and renews for the editor.
 */
final class PreviewController
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
            $html = (string) apply_filters('the_content', HtmlSanitizer::sanitize($html));
        }

        return new WP_REST_Response([
            'importable' => $result->importable(),
            'problems' => $result->problems,
            'title' => $result->title,
            'summary' => (object) $result->summary,
            'html' => $html,
        ]);
    }
}
