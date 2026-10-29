<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Rest\Controller\CollectionController;
use Voltz\PortfolioMd\Rest\DocumentPresenter;
use Voltz\PortfolioMd\Service\CatalogQuery;
use Voltz\PortfolioMd\Service\DocumentSkill;
use Voltz\PortfolioMd\Service\DocumentTag;
use Voltz\PortfolioMd\Service\PublishedDocument;
use Voltz\PortfolioMd\Tests\Support\Documents;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentCatalog;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The public routes of a collection with an in-memory catalog behind and
 * stubbed WordPress functions: how they are registered, what they list,
 * how they paginate and filter, what they refuse.
 */
#[CoversClass(CollectionController::class)]
final class CollectionControllerTest extends TestCase
{
    private InMemoryContentCatalog $catalog;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        $this->catalog = new InMemoryContentCatalog();
    }

    #[Test]
    public function registers_the_list_and_the_document_routes_of_the_collection_open_to_anyone(): void
    {
        $controller = $this->controller(ContentType::Article);

        $controller->register('portfolio/v1');

        self::assertCount(2, WordPressSpy::$restRoutes);
        [$list, $item] = WordPressSpy::$restRoutes;

        self::assertSame('portfolio/v1', $list['namespace']);
        self::assertSame('/articles', $list['route']);
        self::assertSame('GET', $list['args']['methods']);
        self::assertSame([$controller, 'list'], $list['args']['callback']);
        self::assertSame('__return_true', $list['args']['permission_callback']);
        self::assertSame(['page', 'per_page', 'tag'], array_keys($list['args']['args']));
        self::assertSame(['type' => 'integer', 'default' => 1, 'minimum' => 1], self::schemaOf($list['args']['args']['page']));
        self::assertSame(['type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100], self::schemaOf($list['args']['args']['per_page']));
        self::assertSame(['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'], self::schemaOf($list['args']['args']['tag']));

        self::assertSame('/articles/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)', $item['route']);
        self::assertSame('GET', $item['args']['methods']);
        self::assertSame([$controller, 'item'], $item['args']['callback']);
        self::assertSame('__return_true', $item['args']['permission_callback']);
        self::assertSame(['slug'], array_keys($item['args']['args']));
    }

    /**
     * @param list<string> $parameters
     */
    #[Test]
    #[DataProvider('collections')]
    public function each_collection_declares_the_filters_that_mean_something_for_its_kind(ContentType $type, string $route, array $parameters): void
    {
        $this->controller($type)->register('portfolio/v1');

        self::assertSame($route, WordPressSpy::$restRoutes[0]['route']);
        self::assertSame($parameters, array_keys(WordPressSpy::$restRoutes[0]['args']['args']));
    }

    /**
     * @return iterable<string, array{ContentType, string, list<string>}>
     */
    public static function collections(): iterable
    {
        yield 'articles paginate and filter by tag' => [ContentType::Article, '/articles', ['page', 'per_page', 'tag']];
        yield 'projects also filter by status and skill' => [ContentType::Project, '/projects', ['page', 'per_page', 'tag', 'status', 'skill']];
        yield 'pages only paginate' => [ContentType::Page, '/pages', ['page', 'per_page']];
    }

    #[Test]
    public function the_status_filter_is_the_closed_list_of_the_domain(): void
    {
        $this->controller(ContentType::Project)->register('portfolio/v1');

        self::assertSame(['en-cours', 'stable', 'archive', 'prototype'], WordPressSpy::$restRoutes[0]['args']['args']['status']['enum']);
    }

    #[Test]
    public function lists_the_published_documents_newest_first_with_the_totals_in_the_headers(): void
    {
        $older = Documents::article(['id' => 1, 'slug' => 'older', 'date' => Documents::utc('2026-01-01 00:00:00')]);
        $newer = Documents::article(['id' => 2, 'slug' => 'newer', 'date' => Documents::utc('2026-06-01 00:00:00')]);
        $this->catalog->add($older, $newer, Documents::project());

        $response = $this->controller(ContentType::Article)->list(self::request([]));

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(200, $response->get_status());
        self::assertSame(['newer', 'older'], array_column($response->get_data(), 'slug'));
        self::assertSame((new DocumentPresenter())->present($newer), $response->get_data()[0]);
        self::assertSame(['X-WP-Total' => '2', 'X-WP-TotalPages' => '1'], $response->headers);
    }

    #[Test]
    public function applies_the_defaults_when_the_request_carries_no_parameter(): void
    {
        $this->catalog->add(Documents::article());

        $this->controller(ContentType::Article)->list(self::request([]));

        $query = $this->catalog->listCalls[0]['query'];
        self::assertSame(ContentType::Article, $this->catalog->listCalls[0]['type']);
        self::assertSame(1, $query->page);
        self::assertSame(CatalogQuery::DEFAULT_PER_PAGE, $query->perPage);
        self::assertFalse($query->isFiltered());
    }

    #[Test]
    public function paginates_and_links_the_neighbouring_pages_keeping_the_size_and_the_filters(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->catalog->add(Documents::project(['id' => $i, 'slug' => "project-{$i}", 'date' => Documents::utc("2026-0{$i}-01 00:00:00")]));
        }
        $controller = $this->controller(ContentType::Project);
        $controller->register('portfolio/v1');

        $response = $controller->list(self::request(['page' => '2', 'per_page' => '1', 'status' => 'en-cours', 'skill' => '4-0-2-e07']));

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(['project-2'], array_column($response->get_data(), 'slug'));
        self::assertSame('3', $response->headers['X-WP-Total']);
        self::assertSame('3', $response->headers['X-WP-TotalPages']);
        self::assertSame(
            '<http://example.test/wp-json/portfolio/v1/projects?page=1&per_page=1&status=en-cours&skill=4-0-2-e07>; rel="prev", '
            . '<http://example.test/wp-json/portfolio/v1/projects?page=3&per_page=1&status=en-cours&skill=4-0-2-e07>; rel="next"',
            $response->headers['Link'],
        );

        $first = $controller->list(self::request(['per_page' => '1']));
        self::assertInstanceOf(WP_REST_Response::class, $first);
        self::assertSame('<http://example.test/wp-json/portfolio/v1/projects?page=2&per_page=1>; rel="next"', $first->headers['Link']);

        $last = $controller->list(self::request(['page' => '3', 'per_page' => '1']));
        self::assertInstanceOf(WP_REST_Response::class, $last);
        self::assertSame('<http://example.test/wp-json/portfolio/v1/projects?page=2&per_page=1>; rel="prev"', $last->headers['Link']);

        $filteredLast = $controller->list(self::request(['page' => '3', 'per_page' => '1', 'status' => 'en-cours', 'skill' => '4-0-2-e07']));
        self::assertInstanceOf(WP_REST_Response::class, $filteredLast);
        self::assertSame('<http://example.test/wp-json/portfolio/v1/projects?page=2&per_page=1&status=en-cours&skill=4-0-2-e07>; rel="prev"', $filteredLast->headers['Link']);
    }

    #[Test]
    public function links_the_pages_under_the_plugin_namespace_without_being_registered_first(): void
    {
        $this->catalog->add(Documents::article(['id' => 1, 'slug' => 'one']), Documents::article(['id' => 2, 'slug' => 'two']));

        $response = $this->controller(ContentType::Article)->list(self::request(['per_page' => '1']));

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame('<http://example.test/wp-json/portfolio/v1/articles?page=2&per_page=1>; rel="next"', $response->headers['Link']);
    }

    #[Test]
    public function accepts_the_integers_the_validated_server_hands_over_as_well_as_strings(): void
    {
        $this->catalog->add(Documents::article(['id' => 1, 'slug' => 'one', 'date' => Documents::utc('2026-01-01 00:00:00')]), Documents::article(['id' => 2, 'slug' => 'two', 'date' => Documents::utc('2026-02-01 00:00:00')]));
        $controller = $this->controller(ContentType::Article);

        $typed = $controller->list(self::request(['page' => 2, 'per_page' => 1]));
        $strings = $controller->list(self::request(['page' => '2', 'per_page' => '1']));

        self::assertInstanceOf(WP_REST_Response::class, $typed);
        self::assertInstanceOf(WP_REST_Response::class, $strings);
        self::assertSame(['one'], array_column($typed->get_data(), 'slug'));
        self::assertSame($strings->get_data(), $typed->get_data());
        self::assertSame($strings->headers, $typed->headers);
    }

    #[Test]
    public function at_equal_dates_the_newest_post_comes_first_so_that_pages_stay_disjoint(): void
    {
        $day = Documents::utc('2026-03-01 00:00:00');
        $this->catalog->add(
            Documents::project(['id' => 5, 'slug' => 'five', 'date' => $day]),
            Documents::project(['id' => 9, 'slug' => 'nine', 'date' => $day]),
            Documents::project(['id' => 7, 'slug' => 'seven', 'date' => $day]),
        );
        $controller = $this->controller(ContentType::Project);

        $first = $controller->list(self::request(['per_page' => '2']));
        $second = $controller->list(self::request(['per_page' => '2', 'page' => '2']));

        self::assertInstanceOf(WP_REST_Response::class, $first);
        self::assertInstanceOf(WP_REST_Response::class, $second);
        self::assertSame(['nine', 'seven'], array_column($first->get_data(), 'slug'));
        self::assertSame(['five'], array_column($second->get_data(), 'slug'));
    }

    #[Test]
    public function forwards_the_filters_to_the_catalog_as_typed_values(): void
    {
        $this->catalog->add(Documents::project());

        $this->controller(ContentType::Project)->list(self::request(['tag' => 'csharp', 'status' => 'stable', 'skill' => '4-0-2-e07']));

        $query = $this->catalog->listCalls[0]['query'];
        self::assertSame('csharp', $query->tag);
        self::assertSame(ProjectStatus::Stable, $query->status);
        self::assertSame('4-0-2-e07', $query->skill);
    }

    #[Test]
    public function the_filters_narrow_the_list(): void
    {
        $this->catalog->add(
            Documents::project(['id' => 1, 'slug' => 'tested', 'status' => ProjectStatus::Stable]),
            Documents::project(['id' => 2, 'slug' => 'untested', 'status' => ProjectStatus::Stable, 'skills' => []]),
            Documents::project(['id' => 3, 'slug' => 'ongoing', 'status' => ProjectStatus::EnCours]),
        );
        $controller = $this->controller(ContentType::Project);

        $stable = $controller->list(self::request(['status' => 'stable']));
        $testers = $controller->list(self::request(['skill' => '4-0-2-e07']));
        $nobody = $controller->list(self::request(['tag' => 'cobol']));

        self::assertInstanceOf(WP_REST_Response::class, $stable);
        self::assertInstanceOf(WP_REST_Response::class, $testers);
        self::assertInstanceOf(WP_REST_Response::class, $nobody);
        // Same fixture date for the three: the newest post (highest id) comes first.
        self::assertSame(['untested', 'tested'], array_column($stable->get_data(), 'slug'));
        self::assertSame(['ongoing', 'tested'], array_column($testers->get_data(), 'slug'));
        self::assertSame([], $nobody->get_data());
        self::assertSame(['X-WP-Total' => '0', 'X-WP-TotalPages' => '0'], $nobody->headers);
    }

    #[Test]
    #[DataProvider('taggedCollections')]
    public function the_tag_filter_narrows_articles_and_projects_alike(ContentType $type, PublishedDocument $tagged, PublishedDocument $untagged): void
    {
        $this->catalog->add($tagged, $untagged);

        $response = $this->controller($type)->list(self::request(['tag' => 'rust']));

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame([$tagged->slug], array_column($response->get_data(), 'slug'));
        self::assertSame('1', $response->headers['X-WP-Total']);
    }

    /**
     * @return iterable<string, array{ContentType, PublishedDocument, PublishedDocument}>
     */
    public static function taggedCollections(): iterable
    {
        $rust = [new DocumentTag('rust', 'Rust')];
        $php = [new DocumentTag('php', 'PHP')];

        yield 'articles' => [ContentType::Article, Documents::article(['id' => 1, 'slug' => 'rusty', 'tags' => $rust]), Documents::article(['id' => 2, 'slug' => 'phpish', 'tags' => $php])];
        yield 'projects' => [ContentType::Project, Documents::project(['id' => 1, 'slug' => 'rusty', 'tags' => $rust]), Documents::project(['id' => 2, 'slug' => 'phpish', 'tags' => $php])];
    }

    #[Test]
    public function several_filters_combine_with_and_not_or(): void
    {
        $this->catalog->add(
            Documents::project(['id' => 1, 'slug' => 'tagged-only', 'tags' => [new DocumentTag('rust', 'Rust')], 'skills' => []]),
            Documents::project(['id' => 2, 'slug' => 'skilled-only', 'tags' => [], 'skills' => [new DocumentSkill('4-0-2-e07', '4.0.2/E07', 'Tester')]]),
            Documents::project(['id' => 3, 'slug' => 'both', 'tags' => [new DocumentTag('rust', 'Rust')], 'skills' => [new DocumentSkill('4-0-2-e07', '4.0.2/E07', 'Tester')]]),
        );

        $response = $this->controller(ContentType::Project)->list(self::request(['tag' => 'rust', 'skill' => '4-0-2-e07']));

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(['both'], array_column($response->get_data(), 'slug'));
    }

    #[Test]
    public function ignores_the_filters_a_collection_does_not_declare(): void
    {
        $this->catalog->add(Documents::article(), Documents::page());

        $this->controller(ContentType::Article)->list(self::request(['status' => 'stable', 'skill' => '4-0-2-e07']));
        $this->controller(ContentType::Page)->list(self::request(['tag' => 'rust']));

        self::assertFalse($this->catalog->listCalls[0]['query']->isFiltered());
        self::assertFalse($this->catalog->listCalls[1]['query']->isFiltered());
    }

    #[Test]
    public function a_page_beyond_the_last_answers_400_like_the_native_collections(): void
    {
        $this->catalog->add(Documents::article());

        $response = $this->controller(ContentType::Article)->list(self::request(['page' => '2']));

        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(CollectionController::INVALID_PAGE, $response->get_error_code());
        self::assertSame(['status' => 400], $response->get_error_data());
    }

    #[Test]
    public function the_first_page_of_an_empty_collection_is_an_empty_list_not_an_error(): void
    {
        $response = $this->controller(ContentType::Page)->list(self::request([]));

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame([], $response->get_data());
        self::assertSame(['X-WP-Total' => '0', 'X-WP-TotalPages' => '0'], $response->headers);
    }

    #[Test]
    public function any_page_of_an_empty_collection_is_an_empty_list_like_the_native_collections(): void
    {
        $this->catalog->add(Documents::project());
        $controller = $this->controller(ContentType::Project);

        $emptyCollection = $this->controller(ContentType::Article)->list(self::request(['page' => '2']));
        $emptiedByFilter = $controller->list(self::request(['tag' => 'cobol', 'page' => '3']));

        foreach ([$emptyCollection, $emptiedByFilter] as $response) {
            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame([], $response->get_data());
            self::assertSame(['X-WP-Total' => '0', 'X-WP-TotalPages' => '0'], $response->headers);
        }
    }

    #[Test]
    public function a_parameter_the_query_refuses_answers_400(): void
    {
        $controller = $this->controller(ContentType::Project);

        $size = $controller->list(self::request(['per_page' => '0']));
        $status = $controller->list(self::request(['status' => 'bogus']));
        $tag = $controller->list(self::request(['tag' => 'Not a slug']));

        foreach ([$size, $status, $tag] as $response) {
            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('rest_invalid_param', $response->get_error_code());
            self::assertSame(['status' => 400], $response->get_error_data());
        }
        self::assertStringContainsString('bogus', $status->get_error_message());
        self::assertSame([], $this->catalog->listCalls);
    }

    #[Test]
    public function answers_the_document_under_a_slug(): void
    {
        $project = Documents::project();
        $this->catalog->add(Documents::article(['slug' => 'momocad']), $project);

        $response = $this->controller(ContentType::Project)->item(self::request(['slug' => 'momocad']));

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(200, $response->get_status());
        self::assertSame((new DocumentPresenter())->present($project), $response->get_data());
        self::assertSame([['type' => ContentType::Project, 'slug' => 'momocad']], $this->catalog->findCalls);
    }

    #[Test]
    public function a_slug_without_published_document_answers_404_with_the_plugin_code(): void
    {
        $this->catalog->add(Documents::article());

        $response = $this->controller(ContentType::Article)->item(self::request(['slug' => 'missing']));

        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(CollectionController::NOT_FOUND, $response->get_error_code());
        self::assertSame('No published article has the slug "missing".', $response->get_error_message());
        self::assertSame(['status' => 404], $response->get_error_data());
    }

    #[Test]
    public function a_slug_that_is_not_a_string_is_looked_up_as_an_empty_one_and_answers_404(): void
    {
        $this->catalog->add(Documents::article());

        $response = $this->controller(ContentType::Article)->item(self::request(['slug' => ['nested']]));

        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame('No published article has the slug "".', $response->get_error_message());
        self::assertSame([['type' => ContentType::Article, 'slug' => '']], $this->catalog->findCalls);
    }

    private function controller(ContentType $type): CollectionController
    {
        return new CollectionController($type, $this->catalog, new DocumentPresenter());
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/portfolio/v1/collection');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $arg
     *
     * @return array<string, mixed>
     */
    private static function schemaOf(array $arg): array
    {
        unset($arg['description']);

        return $arg;
    }
}
