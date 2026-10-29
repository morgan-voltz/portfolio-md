<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Rest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;
use Voltz\PortfolioMd\Parsing\ContentRenderer;
use Voltz\PortfolioMd\Parsing\FrontmatterParser;
use Voltz\PortfolioMd\Parsing\GutenbergTransformer;
use Voltz\PortfolioMd\Parsing\MarkdownParser;
use Voltz\PortfolioMd\Rest\Controller\CollectionController;
use Voltz\PortfolioMd\Rest\Controller\PreviewController;
use Voltz\PortfolioMd\Rest\Controller\SitemapController;
use Voltz\PortfolioMd\Rest\Controller\SyncController;
use Voltz\PortfolioMd\Rest\DocumentPresenter;
use Voltz\PortfolioMd\Rest\RouteRegistrar;
use Voltz\PortfolioMd\Parsing\SkillsGridLoader;
use Voltz\PortfolioMd\Service\ConflictResolver;
use Voltz\PortfolioMd\Service\ContentImportService;
use Voltz\PortfolioMd\Service\ContentSyncService;
use Voltz\PortfolioMd\Service\PreviewService;
use Voltz\PortfolioMd\Service\SkillTermSynchronizer;
use Voltz\PortfolioMd\Tests\Support\FixedClock;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentCatalog;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentRepository;
use Voltz\PortfolioMd\Tests\Support\InMemorySkillTermGateway;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(RouteRegistrar::class)]
final class RouteRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressSpy::reset();
    }

    #[Test]
    public function registers_every_controller_under_the_plugin_namespace_in_order(): void
    {
        $catalog = new InMemoryContentCatalog();
        $presenter = new DocumentPresenter();
        $renderer = new ContentRenderer(new MarkdownParser(), new GutenbergTransformer());
        $skills = new InMemorySkillTermGateway();
        $repository = new InMemoryContentRepository();
        $import = new ContentImportService(new FrontmatterParser(), $renderer, $repository, $skills, new ConflictResolver(), FixedClock::at('2026-10-29T10:00:00'));
        $registrar = new RouteRegistrar(
            new PreviewController(new PreviewService(new FrontmatterParser(), $renderer, $skills)),
            new CollectionController(ContentType::Article, $catalog, $presenter),
            new CollectionController(ContentType::Project, $catalog, $presenter),
            new CollectionController(ContentType::Page, $catalog, $presenter),
            new SitemapController($catalog, $presenter),
            new SyncController(new ContentSyncService($import, $repository, new SkillTermSynchronizer($skills), new SkillsGridLoader()), FixedClock::at('2026-10-29T10:00:00')),
        );

        $registrar->register();

        self::assertSame('portfolio/v1', RouteRegistrar::NAMESPACE);
        self::assertSame(array_fill(0, 9, 'portfolio/v1'), array_column(WordPressSpy::$restRoutes, 'namespace'));
        self::assertSame([
            '/preview',
            '/articles',
            '/articles/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)',
            '/projects',
            '/projects/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)',
            '/pages',
            '/pages/(?P<slug>[a-z0-9]+(?:-[a-z0-9]+)*)',
            '/sitemap',
            '/sync',
        ], array_column(WordPressSpy::$restRoutes, 'route'));
    }

    #[Test]
    public function registers_nothing_without_controllers(): void
    {
        (new RouteRegistrar())->register();

        self::assertSame([], WordPressSpy::$restRoutes);
    }
}
