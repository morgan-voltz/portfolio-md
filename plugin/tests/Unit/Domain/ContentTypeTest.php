<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentType;

#[CoversClass(ContentType::class)]
final class ContentTypeTest extends TestCase
{
    #[Test]
    public function each_kind_has_a_folder_in_the_content_repository(): void
    {
        self::assertSame('articles', ContentType::Article->folder());
        self::assertSame('projects', ContentType::Project->folder());
        self::assertSame('pages', ContentType::Page->folder());

        self::assertSame(ContentType::Project, ContentType::fromFolder('projects'));
        self::assertSame(ContentType::Page, ContentType::fromFolder('pages'));
        self::assertNull(ContentType::fromFolder('images'));
    }

    #[Test]
    public function each_kind_is_a_collection_of_the_rest_api(): void
    {
        self::assertSame('articles', ContentType::Article->collection());
        self::assertSame('projects', ContentType::Project->collection());
        self::assertSame('pages', ContentType::Page->collection());
    }

    #[Test]
    public function each_kind_has_a_place_in_the_url_tree_of_the_site(): void
    {
        self::assertSame('/articles/rust/', ContentType::Article->path('rust'));
        self::assertSame('/projets/momocad/', ContentType::Project->path('momocad'));
        self::assertSame('/a-propos/', ContentType::Page->path('a-propos'));
        self::assertSame('', ContentType::Page->urlBase());
    }
}
