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
use Voltz\PortfolioMd\Rest\RouteRegistrar;
use Voltz\PortfolioMd\Service\PreviewService;
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
    public function registers_the_preview_under_the_plugin_namespace(): void
    {
        $registrar = new RouteRegistrar(new PreviewController(new PreviewService(
            new FrontmatterParser(),
            new ContentRenderer(new MarkdownParser(), new GutenbergTransformer()),
            new InMemorySkillTermGateway(),
        )));

        $registrar->register();

        self::assertSame('portfolio/v1', RouteRegistrar::NAMESPACE);
        self::assertCount(1, WordPressSpy::$restRoutes);
        self::assertSame('portfolio/v1', WordPressSpy::$restRoutes[0]['namespace']);
        self::assertSame('/preview', WordPressSpy::$restRoutes[0]['route']);
    }
}
