<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Rest\Controller\SitemapController;
use Voltz\PortfolioMd\Rest\DocumentPresenter;
use Voltz\PortfolioMd\Tests\Support\Documents;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentCatalog;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;
use WP_REST_Request;

#[CoversClass(SitemapController::class)]
final class SitemapControllerTest extends TestCase
{
    private InMemoryContentCatalog $catalog;
    private SitemapController $controller;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        $this->catalog = new InMemoryContentCatalog();
        $this->controller = new SitemapController($this->catalog, new DocumentPresenter());
    }

    #[Test]
    public function registers_a_get_route_open_to_anyone(): void
    {
        $this->controller->register('portfolio/v1');

        self::assertCount(1, WordPressSpy::$restRoutes);
        $route = WordPressSpy::$restRoutes[0];
        self::assertSame('portfolio/v1', $route['namespace']);
        self::assertSame('/sitemap', $route['route']);
        self::assertSame('GET', $route['args']['methods']);
        self::assertSame([$this->controller, 'handle'], $route['args']['callback']);
        self::assertSame('__return_true', $route['args']['permission_callback']);
    }

    #[Test]
    public function answers_every_published_document_by_collection_in_slug_order(): void
    {
        $this->catalog->add(Documents::project(['id' => 2, 'slug' => 'vefta', 'updated' => Documents::utc('2026-10-27 13:14:00')]), Documents::project(['id' => 1, 'slug' => 'atlas', 'updated' => Documents::utc('2026-10-20 08:00:00')]));

        $data = $this->controller->handle(new WP_REST_Request('GET', '/portfolio/v1/sitemap'))->get_data();

        self::assertSame([
            ['slug' => 'atlas', 'updated' => '2026-10-20T08:00:00Z'],
            ['slug' => 'vefta', 'updated' => '2026-10-27T13:14:00Z'],
        ], $data['projects']);
    }

    #[Test]
    public function answers_every_published_document_by_collection_with_its_last_modification(): void
    {
        $this->catalog->add(Documents::article(), Documents::project(), Documents::page());

        $response = $this->controller->handle(new WP_REST_Request('GET', '/portfolio/v1/sitemap'));

        self::assertSame(200, $response->get_status());
        self::assertSame([
            'articles' => [['slug' => 'rust-ownership-intuition', 'updated' => '2026-04-22T10:30:00Z']],
            'projects' => [['slug' => 'momocad', 'updated' => '2026-10-27T13:13:00Z']],
            'pages' => [['slug' => 'a-propos', 'updated' => '2026-10-27T12:07:00Z']],
        ], $response->get_data());
    }
}
