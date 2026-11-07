<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\ContentSource;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\StoredContent;
use Voltz\PortfolioMd\Service\TranslationGroup;

#[CoversClass(TranslationGroup::class)]
final class TranslationGroupTest extends TestCase
{
    #[Test]
    public function keeps_one_post_per_language_the_first_given_when_two_share_one(): void
    {
        $group = TranslationGroup::fromStored([
            self::stored(41, Language::Fr),
            self::stored(42, Language::De),
            self::stored(43, Language::Fr),
        ]);

        self::assertSame(['fr' => 41, 'de' => 42], $group);
    }

    #[Test]
    public function leaves_out_a_post_without_stored_language(): void
    {
        $group = TranslationGroup::fromStored([self::stored(7, null), self::stored(8, Language::En)]);

        self::assertSame(['en' => 8], $group);
    }

    #[Test]
    public function an_empty_list_is_an_empty_group(): void
    {
        self::assertSame([], TranslationGroup::fromStored([]));
    }

    private static function stored(int $id, ?Language $lang): StoredContent
    {
        return new StoredContent($id, str_repeat('0', 64), ContentSource::Git, '1.2.0', lang: $lang, translationKey: 'projet');
    }
}
