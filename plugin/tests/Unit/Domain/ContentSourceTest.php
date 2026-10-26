<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentSource;

#[CoversClass(ContentSource::class)]
final class ContentSourceTest extends TestCase
{
    #[Test]
    public function values_lists_the_three_backed_values_in_declaration_order(): void
    {
        self::assertSame(['admin', 'git', 'paste'], ContentSource::values());
    }

    #[Test]
    public function backed_values_round_trip(): void
    {
        self::assertSame(ContentSource::Git, ContentSource::from('git'));
        self::assertNull(ContentSource::tryFrom('ftp'));
    }
}
