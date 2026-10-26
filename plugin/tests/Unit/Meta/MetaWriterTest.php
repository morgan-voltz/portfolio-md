<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Meta;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Meta\MetaWriter;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

#[CoversClass(MetaWriter::class)]
final class MetaWriterTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressSpy::reset();
    }

    #[Test]
    public function update_slashes_strings_so_that_wordpress_unslashing_restores_them(): void
    {
        $markdown = 'Escaped \*asterisk\* and C:\path';

        $result = MetaWriter::update(42, '_portfolio_md_source', $markdown);

        self::assertTrue($result);
        self::assertSame(
            [['postId' => 42, 'key' => '_portfolio_md_source', 'value' => addslashes($markdown)]],
            WordPressSpy::$updatedPostMetas,
        );
        self::assertSame($markdown, stripslashes(WordPressSpy::$updatedPostMetas[0]['value']));
    }

    #[Test]
    public function update_leaves_non_string_values_untouched(): void
    {
        MetaWriter::update(42, '_portfolio_featured', true);
        MetaWriter::update(42, '_portfolio_reading_time', 12);

        self::assertTrue(WordPressSpy::$updatedPostMetas[0]['value']);
        self::assertSame(12, WordPressSpy::$updatedPostMetas[1]['value']);
    }
}
