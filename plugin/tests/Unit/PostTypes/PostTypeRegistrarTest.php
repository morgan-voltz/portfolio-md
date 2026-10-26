<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\PostTypes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\PostTypes\PostTypeRegistrar;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(PostTypeRegistrar::class)]
final class PostTypeRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressSpy::reset();
        (new PostTypeRegistrar())->register();
    }

    #[Test]
    public function registers_article_and_project(): void
    {
        self::assertSame([PostTypeRegistrar::ARTICLE, PostTypeRegistrar::PROJECT], array_keys(WordPressSpy::$postTypes));
        self::assertSame('article', PostTypeRegistrar::ARTICLE);
        self::assertSame('project', PostTypeRegistrar::PROJECT);
    }

    #[Test]
    public function both_post_types_are_public_gutenberg_ready_and_support_custom_fields(): void
    {
        foreach (['article' => 'articles', 'project' => 'projets'] as $postType => $urlSlug) {
            $args = WordPressSpy::$postTypes[$postType];

            self::assertTrue($args['public'], $postType);
            self::assertTrue($args['show_in_rest'], $postType);
            self::assertTrue($args['has_archive'], $postType);
            self::assertSame($urlSlug, $args['rewrite']['slug'], $postType);
            self::assertContains('custom-fields', $args['supports'], $postType);
        }
    }

    #[Test]
    public function only_projects_support_a_cover_image(): void
    {
        self::assertNotContains('thumbnail', WordPressSpy::$postTypes['article']['supports']);
        self::assertContains('thumbnail', WordPressSpy::$postTypes['project']['supports']);
    }
}
