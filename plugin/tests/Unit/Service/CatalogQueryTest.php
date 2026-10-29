<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ProjectStatus;
use Voltz\PortfolioMd\Service\CatalogPage;
use Voltz\PortfolioMd\Service\CatalogQuery;

#[CoversClass(CatalogQuery::class)]
#[CoversClass(CatalogPage::class)]
final class CatalogQueryTest extends TestCase
{
    #[Test]
    public function asks_for_the_first_page_of_ten_without_filter_by_default(): void
    {
        $query = new CatalogQuery();

        self::assertSame(1, $query->page);
        self::assertSame(10, $query->perPage);
        self::assertNull($query->tag);
        self::assertNull($query->status);
        self::assertNull($query->skill);
        self::assertFalse($query->isFiltered());
        self::assertSame(0, $query->offset());
    }

    #[Test]
    public function knows_where_a_page_starts_and_whether_it_is_filtered(): void
    {
        self::assertSame(20, (new CatalogQuery(page: 3, perPage: 10))->offset());
        self::assertTrue((new CatalogQuery(tag: 'rust'))->isFiltered());
        self::assertTrue((new CatalogQuery(status: ProjectStatus::Stable))->isFiltered());
        self::assertTrue((new CatalogQuery(skill: '4-0-2-e07'))->isFiltered());
    }

    #[Test]
    public function accepts_the_boundary_page_sizes(): void
    {
        self::assertSame(1, (new CatalogQuery(perPage: 1))->perPage);
        self::assertSame(100, (new CatalogQuery(perPage: 100))->perPage);
        self::assertSame(CatalogQuery::MAX_PER_PAGE, (new CatalogQuery(perPage: CatalogQuery::MAX_PER_PAGE))->perPage);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refused')]
    public function refuses_what_the_route_schema_refuses(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new CatalogQuery(...$arguments);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refused(): iterable
    {
        yield 'page zero' => [['page' => 0], 'The page must be 1 or more, 0 given.'];
        yield 'negative page' => [['page' => -2], 'The page must be 1 or more, -2 given.'];
        yield 'empty page' => [['perPage' => 0], 'The page size must be between 1 and 100, 0 given.'];
        yield 'page above the cap' => [['perPage' => 101], 'The page size must be between 1 and 100, 101 given.'];
        yield 'tag that is not a slug' => [['tag' => 'C#'], 'The tag filter must be a term slug, "C#" given.'];
        yield 'skill that is not a slug' => [['skill' => '4.0.2/E07'], 'The skill filter must be a term slug, "4.0.2/E07" given.'];
    }

    #[Test]
    public function a_page_counts_the_pages_of_its_collection(): void
    {
        self::assertSame(3, (new CatalogPage([], 21, 1, 10))->totalPages());
        self::assertSame(1, (new CatalogPage([], 10, 1, 10))->totalPages());
        self::assertSame(0, (new CatalogPage([], 0, 1, 10))->totalPages());
    }
}
