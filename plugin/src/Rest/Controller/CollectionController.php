<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Rest\Controller;

use InvalidArgumentException;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\Patterns;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Rest\DocumentPresenter;
use Voltz\PortfolioMd\Rest\RestController;
use Voltz\PortfolioMd\Rest\RouteRegistrar;
use Voltz\PortfolioMd\Service\CatalogPage;
use Voltz\PortfolioMd\Service\CatalogQuery;
use Voltz\PortfolioMd\Service\ContentCatalog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The two public routes of one collection (01-plugin-php.md §7.1): the
 * paginated list, `GET /articles`, and the document under a slug,
 * `GET /articles/{slug}`; the same class serves `/projects` and `/pages`.
 *
 * Open to anyone: the content is published, and the catalog behind the
 * routes only ever reads what the site shows. The parameters are declared
 * with their schema and the REST server validates them before the callbacks
 * run (a page below 1, a size above the cap, a status outside the closed
 * list or a tag that is not a slug answer 400 with the server's own error);
 * the callbacks only translate them into a CatalogQuery.
 *
 * The list follows the conventions of the native REST API, which the C#
 * gateway already knows: `page` and `per_page`, the body is the bare list,
 * the size of the collection is in the `X-WP-Total` and `X-WP-TotalPages`
 * headers and the neighbouring pages in a `Link` header. A page beyond the
 * last of a non-empty collection answers 400 like the native collections;
 * an empty collection answers an empty list whatever the page. A slug that no published
 * document carries answers 404 with the plugin's own error code; a slug
 * with capitals answers 400, because the server matches routes without
 * regard to case and the schema of the parameter then refuses it.
 *
 * The server's `_fields` parameter narrows these answers like any other
 * (`_fields=slug,title`): it is applied to every route when the response
 * is served, which the smoke test shows.
 */
final class CollectionController implements RestController
{
    public const NOT_FOUND = 'portfolio_not_found';

    /** The code the native collections use for the same case. */
    public const INVALID_PAGE = 'rest_post_invalid_page_number';

    /** The namespace the routes were registered under; the plugin's unless register() is told otherwise. */
    private string $namespace = RouteRegistrar::NAMESPACE;

    public function __construct(
        private readonly ContentType $type,
        private readonly ContentCatalog $catalog,
        private readonly DocumentPresenter $presenter,
    ) {
    }

    /** The route of the list; the route of a document is this plus the slug. */
    public function route(): string
    {
        return '/' . $this->type->collection();
    }

    public function register(string $namespace): void
    {
        $this->namespace = $namespace;

        register_rest_route($namespace, $this->route(), [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'list'],
            'permission_callback' => '__return_true',
            'args' => $this->listArgs(),
        ]);

        register_rest_route($namespace, $this->route() . '/(?P<slug>' . Patterns::SLUG_BODY . ')', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'item'],
            'permission_callback' => '__return_true',
            'args' => [
                'slug' => [
                    'description' => 'The slug of the document, as in its frontmatter.',
                    'type' => 'string',
                    'pattern' => '^' . Patterns::SLUG_BODY . '$',
                ],
            ],
        ]);
    }

    /**
     * The parameters of the list, with the schema the REST server enforces.
     * A page only paginates; an article filters by tag; a project also by
     * status and skill.
     *
     * @return array<string, array<string, mixed>>
     */
    private function listArgs(): array
    {
        $args = [
            'page' => [
                'description' => 'The page of the collection, from 1.',
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
            ],
            'per_page' => [
                'description' => 'Documents per page.',
                'type' => 'integer',
                'default' => CatalogQuery::DEFAULT_PER_PAGE,
                'minimum' => 1,
                'maximum' => CatalogQuery::MAX_PER_PAGE,
            ],
        ];

        if ($this->type !== ContentType::Page) {
            $args['tag'] = [
                'description' => 'The slug of a tech tag: only the documents that carry it.',
                'type' => 'string',
                'pattern' => '^' . Patterns::SLUG_BODY . '$',
            ];
        }

        if ($this->type === ContentType::Project) {
            $args['status'] = [
                'description' => 'A project status: only the projects in that state.',
                'type' => 'string',
                'enum' => ProjectStatus::values(),
            ];
            $args['skill'] = [
                'description' => 'The slug of a competency term: only the projects that demonstrate it. The slug of a referential selects every competency under it.',
                'type' => 'string',
                'pattern' => '^' . Patterns::SLUG_BODY . '$',
            ];
        }

        return $args;
    }

    /** Callback of the list route. */
    public function list(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $query = $this->queryFrom($request);
        } catch (InvalidArgumentException $invalid) {
            // The server validated the parameters already; this is the guard of the query itself.
            return new WP_Error('rest_invalid_param', $invalid->getMessage(), ['status' => 400]);
        }

        $page = $this->catalog->list($this->type, $query);
        if ($page->total > 0 && $query->page > $page->totalPages()) {
            return new WP_Error(self::INVALID_PAGE, 'The page number requested is larger than the number of pages available.', ['status' => 400]);
        }

        return new WP_REST_Response(
            array_map($this->presenter->present(...), $page->items),
            200,
            $this->headers($page, $query),
        );
    }

    /** Callback of the document route. */
    public function item(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $slug = $request->get_param('slug');
        $slug = is_string($slug) ? $slug : '';

        $document = $this->catalog->find($this->type, $slug);
        if ($document === null) {
            return new WP_Error(self::NOT_FOUND, sprintf('No published %s has the slug "%s".', $this->type->value, $slug), ['status' => 404]);
        }

        return new WP_REST_Response($this->presenter->present($document));
    }

    /**
     * Only the parameters this collection declares are read: a `status` on
     * `/articles` is not a filter, it is ignored as the server ignores it.
     */
    private function queryFrom(WP_REST_Request $request): CatalogQuery
    {
        $status = null;
        if ($this->type === ContentType::Project) {
            $given = $request->get_param('status');
            if (is_string($given) && $given !== '') {
                $status = ProjectStatus::tryFrom($given)
                    ?? throw new InvalidArgumentException("The status filter must be one of " . implode(', ', ProjectStatus::values()) . ", \"{$given}\" given.");
            }
        }

        return new CatalogQuery(
            page: self::integer($request->get_param('page'), 1),
            perPage: self::integer($request->get_param('per_page'), CatalogQuery::DEFAULT_PER_PAGE),
            tag: $this->type !== ContentType::Page ? self::textOrNull($request->get_param('tag')) : null,
            status: $status,
            skill: $this->type === ContentType::Project ? self::textOrNull($request->get_param('skill')) : null,
        );
    }

    /**
     * The headers of the native collections: the totals, and the previous
     * and next pages as links that keep the size and the filters.
     *
     * @return array<string, string>
     */
    private function headers(CatalogPage $page, CatalogQuery $query): array
    {
        $headers = [
            'X-WP-Total' => (string) $page->total,
            'X-WP-TotalPages' => (string) $page->totalPages(),
        ];

        $links = [];
        // Like the native collections: the previous page never points beyond the last one,
        // and an empty collection has no previous page at all.
        $previous = min($query->page - 1, $page->totalPages());
        if ($previous > 0) {
            $links[] = '<' . $this->pageUrl($query, $previous) . '>; rel="prev"';
        }
        if ($query->page < $page->totalPages()) {
            $links[] = '<' . $this->pageUrl($query, $query->page + 1) . '>; rel="next"';
        }
        if ($links !== []) {
            $headers['Link'] = implode(', ', $links);
        }

        return $headers;
    }

    private function pageUrl(CatalogQuery $query, int $page): string
    {
        $params = ['page' => $page, 'per_page' => $query->perPage];
        if ($query->tag !== null) {
            $params['tag'] = $query->tag;
        }
        if ($query->status !== null) {
            $params['status'] = $query->status->value;
        }
        if ($query->skill !== null) {
            $params['skill'] = $query->skill;
        }

        return add_query_arg($params, rest_url($this->namespace . $this->route()));
    }

    private static function integer(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function textOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
