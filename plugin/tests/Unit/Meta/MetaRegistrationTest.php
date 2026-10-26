<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Meta;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Meta\MetaRegistration;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(MetaRegistration::class)]
final class MetaRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressSpy::reset();
    }

    #[Test]
    public function portfolio_post_types_are_article_and_project(): void
    {
        self::assertSame(['article', 'project'], MetaRegistration::PORTFOLIO_POST_TYPES);
    }

    #[Test]
    public function register_for_post_types_registers_the_same_args_on_each_type(): void
    {
        $args = ['type' => 'string', 'single' => true];

        MetaRegistration::registerForPostTypes(['article', 'project'], '_portfolio_x', $args);

        self::assertSame($args, WordPressSpy::postMeta('article', '_portfolio_x'));
        self::assertSame($args, WordPressSpy::postMeta('project', '_portfolio_x'));
        self::assertNull(WordPressSpy::postMeta('post', '_portfolio_x'));
    }

    #[Test]
    public function authorize_edit_post_asks_whether_the_user_can_edit_that_post(): void
    {
        WordPressSpy::$userCanResult = true;

        $allowed = MetaRegistration::authorizeEditPost(false, '_portfolio_featured', 42, 7);

        self::assertTrue($allowed);
        self::assertSame([[7, 'edit_post', 42]], WordPressSpy::$userCanCalls);
    }

    #[Test]
    public function authorize_edit_post_ignores_the_allowed_flag_passed_by_wordpress(): void
    {
        WordPressSpy::$userCanResult = false;

        self::assertFalse(MetaRegistration::authorizeEditPost(true, '_portfolio_featured', 42, 7));
    }
}
