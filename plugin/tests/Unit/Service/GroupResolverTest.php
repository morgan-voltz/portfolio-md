<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\GroupMember;
use Voltz\PortfolioMd\Service\GroupResolver;

/**
 * The resolution rules of ADR-021, case by case: they are what the lists and
 * the detail routes of every collection apply, in both catalogs.
 */
#[CoversClass(GroupResolver::class)]
#[CoversClass(GroupMember::class)]
final class GroupResolverTest extends TestCase
{
    #[Test]
    public function keeps_the_oldest_member_per_language_in_the_order_of_the_site(): void
    {
        $winners = GroupResolver::winners([
            new GroupMember(30, Language::En, 'momocad-en'),
            new GroupMember(12, Language::De, 'momocad-de'),
            new GroupMember(10, Language::Fr, 'momocad'),
            new GroupMember(9, Language::De, 'momocad-de-copy'),
        ]);

        self::assertSame(['fr', 'de', 'en'], array_keys($winners));
        self::assertSame(9, $winners['de']->id);
        self::assertSame('momocad-de-copy', $winners['de']->slug);
    }

    #[Test]
    public function an_empty_group_has_no_winner_and_nothing_to_pick(): void
    {
        self::assertSame([], GroupResolver::winners([]));
        self::assertNull(GroupResolver::pick(Language::De, []));
    }

    #[Test]
    public function picks_the_requested_language_when_the_group_holds_it(): void
    {
        $winners = GroupResolver::winners([new GroupMember(1, Language::Fr, 'momocad'), new GroupMember(2, Language::De, 'momocad-de')]);

        self::assertSame(Language::De, GroupResolver::pick(Language::De, $winners));
        self::assertSame(Language::Fr, GroupResolver::pick(Language::Fr, $winners));
    }

    #[Test]
    public function walks_the_chain_one_or_two_steps_down(): void
    {
        $frenchAndEnglish = GroupResolver::winners([new GroupMember(1, Language::Fr, 'momocad'), new GroupMember(3, Language::En, 'momocad-en')]);
        $frenchOnly = GroupResolver::winners([new GroupMember(1, Language::Fr, 'momocad')]);

        self::assertSame(Language::En, GroupResolver::pick(Language::De, $frenchAndEnglish));
        self::assertSame(Language::Fr, GroupResolver::pick(Language::De, $frenchOnly));
        self::assertSame(Language::Fr, GroupResolver::pick(Language::En, $frenchOnly));
    }

    #[Test]
    public function a_french_reader_never_falls_back_to_another_language(): void
    {
        $germanOnly = GroupResolver::winners([new GroupMember(5, Language::De, 'waise-de')]);

        self::assertNull(GroupResolver::pick(Language::Fr, $germanOnly));
    }

    #[Test]
    public function serves_the_translation_when_it_exists(): void
    {
        $french = new GroupMember(1, Language::Fr, 'momocad');
        $german = new GroupMember(2, Language::De, 'momocad-de');

        $resolved = GroupResolver::resolve(Language::De, $french, [$french, $german]);

        self::assertSame($german, $resolved['served']);
        self::assertSame(['fr' => 'momocad', 'de' => 'momocad-de'], $resolved['translations']);
        self::assertFalse($resolved['isFallback']);
    }

    #[Test]
    public function serves_the_anchor_itself_as_a_fallback_when_the_translation_is_missing(): void
    {
        $french = new GroupMember(1, Language::Fr, 'momocad');

        $resolved = GroupResolver::resolve(Language::De, $french, [$french]);

        self::assertSame($french, $resolved['served']);
        self::assertSame(['fr' => 'momocad'], $resolved['translations']);
        self::assertTrue($resolved['isFallback']);
    }

    #[Test]
    public function a_translation_asked_in_french_answers_the_french_original_without_fallback(): void
    {
        $french = new GroupMember(1, Language::Fr, 'momocad');
        $german = new GroupMember(2, Language::De, 'momocad-de');

        $resolved = GroupResolver::resolve(Language::Fr, $german, [$french, $german]);

        self::assertSame($french, $resolved['served']);
        self::assertFalse($resolved['isFallback']);
    }

    #[Test]
    public function a_document_without_french_original_is_served_as_itself_flagged_as_a_fallback(): void
    {
        $german = new GroupMember(5, Language::De, 'waise-de');

        $resolved = GroupResolver::resolve(Language::Fr, $german, [$german]);

        self::assertSame($german, $resolved['served']);
        self::assertSame(['de' => 'waise-de'], $resolved['translations']);
        self::assertTrue($resolved['isFallback']);
    }

    #[Test]
    public function the_document_the_reader_named_is_served_even_when_an_older_twin_won_its_language(): void
    {
        $french = new GroupMember(1, Language::Fr, 'momocad');
        $olderTwin = new GroupMember(2, Language::De, 'momocad-de-copy');
        $named = new GroupMember(3, Language::De, 'momocad-de');

        $resolved = GroupResolver::resolve(Language::De, $named, [$french, $olderTwin, $named]);

        self::assertSame($named, $resolved['served']);
        self::assertSame(['fr' => 'momocad', 'de' => 'momocad-de'], $resolved['translations']);
        self::assertFalse($resolved['isFallback']);
    }

    #[Test]
    public function a_list_entry_substituted_by_a_translation_points_to_the_oldest_twin_of_that_language(): void
    {
        $french = new GroupMember(1, Language::Fr, 'momocad');
        $olderTwin = new GroupMember(2, Language::De, 'momocad-de-copy');
        $newerTwin = new GroupMember(3, Language::De, 'momocad-de');

        $resolved = GroupResolver::resolve(Language::De, $french, [$french, $olderTwin, $newerTwin]);

        self::assertSame($olderTwin, $resolved['served']);
        self::assertSame(['fr' => 'momocad', 'de' => 'momocad-de-copy'], $resolved['translations']);
    }

    #[Test]
    public function the_translations_map_is_ordered_fr_de_en_whatever_the_order_of_the_members(): void
    {
        $french = new GroupMember(1, Language::Fr, 'momocad');
        $english = new GroupMember(2, Language::En, 'momocad-en');
        $german = new GroupMember(3, Language::De, 'momocad-de');

        $resolved = GroupResolver::resolve(Language::En, $french, [$english, $german, $french]);

        self::assertSame(['fr' => 'momocad', 'de' => 'momocad-de', 'en' => 'momocad-en'], $resolved['translations']);
        self::assertSame($english, $resolved['served']);
    }
}
