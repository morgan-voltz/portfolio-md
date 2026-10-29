<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Rest\Controller;

use Voltz\PortfolioMd\Rest\DocumentPresenter;
use Voltz\PortfolioMd\Rest\RestController;
use Voltz\PortfolioMd\Service\ContentCatalog;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /portfolio/v1/sitemap` (01-plugin-php.md §7.1): every published
 * document, by collection, with the instant of its last modification, in
 * one unpaginated answer. Meant for a consumer that caches the documents
 * (the C# gateway, 02-api-csharp.md §5.2): it polls this route, compares
 * the instants with those it holds, and fetches again only what changed.
 * Open to anyone, like the collections it summarizes.
 */
final class SitemapController implements RestController
{
    public const ROUTE = '/sitemap';

    public function __construct(
        private readonly ContentCatalog $catalog,
        private readonly DocumentPresenter $presenter,
    ) {
    }

    public function register(string $namespace): void
    {
        register_rest_route($namespace, self::ROUTE, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** Callback of the route. */
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->presenter->presentSitemap($this->catalog->sitemap()));
    }
}
