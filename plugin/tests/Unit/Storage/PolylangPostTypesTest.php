<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Storage\PolylangPostTypes;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(PolylangPostTypes::class)]
final class PolylangPostTypesTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressSpy::reset();
    }

    #[Test]
    public function hooks_the_polylang_filter_with_both_arguments(): void
    {
        (new PolylangPostTypes())->register();

        $hooks = WordPressSpy::hooksOn('filter', 'pll_get_post_types');
        self::assertCount(1, $hooks);
        self::assertSame(2, $hooks[0]['args']);
    }

    #[Test]
    public function adds_articles_and_projects_keyed_by_slug_and_keeps_what_polylang_had(): void
    {
        $types = (new PolylangPostTypes())->manage(['post' => 'post', 'page' => 'page'], false);

        self::assertSame(['post' => 'post', 'page' => 'page', 'article' => 'article', 'project' => 'project'], $types);
    }

    #[Test]
    public function leaves_the_list_of_the_settings_screen_alone(): void
    {
        $types = (new PolylangPostTypes())->manage(['post' => 'post'], true);

        self::assertSame(['post' => 'post'], $types);
    }

    #[Test]
    public function never_adds_the_native_page_type_itself(): void
    {
        self::assertArrayNotHasKey('page', (new PolylangPostTypes())->manage([], false));
    }
}
