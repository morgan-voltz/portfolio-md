<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\PublishedLanguages;
use Voltz\PortfolioMd\Tests\Support\InMemoryPublishedLanguagesStore;

#[CoversClass(PublishedLanguages::class)]
final class PublishedLanguagesTest extends TestCase
{
    #[Test]
    #[DataProvider('rawValues')]
    public function normalizes_whatever_is_stored_into_the_languages_of_the_site_french_first(mixed $raw, array $expected): void
    {
        self::assertSame($expected, PublishedLanguages::normalize($raw));
    }

    /** @return iterable<string, array{mixed, list<string>}> */
    public static function rawValues(): iterable
    {
        yield 'nothing stored' => [null, ['fr']];
        yield 'a string instead of a list' => ['de', ['fr']];
        yield 'french alone' => [['fr'], ['fr']];
        yield 'german without french' => [['de'], ['fr', 'de']];
        yield 'everything, out of order, twice' => [['en', 'de', 'fr', 'de'], ['fr', 'de', 'en']];
        yield 'unknown languages and junk' => [['xx', 3, null, 'EN', 'en'], ['fr', 'en']];
        yield 'an associative array' => [['a' => 'de', 'b' => 'en'], ['fr', 'de', 'en']];
    }

    #[Test]
    public function french_is_always_open_and_reads_as_language_objects(): void
    {
        $languages = new PublishedLanguages(new InMemoryPublishedLanguagesStore(null));

        self::assertSame([Language::Fr], $languages->all());
        self::assertTrue($languages->isPublished(Language::Fr));
        self::assertFalse($languages->isPublished(Language::De));
    }

    #[Test]
    public function opening_a_language_writes_the_normalized_list_once(): void
    {
        $store = new InMemoryPublishedLanguagesStore(['fr']);
        $languages = new PublishedLanguages($store);

        $languages->publish(Language::En);
        $languages->publish(Language::De);

        self::assertSame([['fr', 'en'], ['fr', 'de', 'en']], $store->writes);
        self::assertSame([Language::Fr, Language::De, Language::En], $languages->all());
    }

    #[Test]
    public function opening_an_open_language_writes_the_same_list_again_harmlessly(): void
    {
        $store = new InMemoryPublishedLanguagesStore(['fr', 'de']);

        (new PublishedLanguages($store))->publish(Language::De);

        self::assertSame([['fr', 'de']], $store->writes);
    }

    #[Test]
    public function closing_a_language_removes_it_and_keeps_the_others(): void
    {
        $store = new InMemoryPublishedLanguagesStore(['fr', 'de', 'en']);
        $languages = new PublishedLanguages($store);

        $languages->unpublish(Language::De);

        self::assertSame(['fr', 'en'], $store->value);
        self::assertFalse($languages->isPublished(Language::De));
        self::assertTrue($languages->isPublished(Language::En));
    }

    #[Test]
    public function closing_a_closed_language_changes_nothing(): void
    {
        $store = new InMemoryPublishedLanguagesStore(['fr']);

        (new PublishedLanguages($store))->unpublish(Language::En);

        self::assertSame(['fr'], $store->value);
    }

    #[Test]
    public function the_language_of_the_site_cannot_be_closed(): void
    {
        $store = new InMemoryPublishedLanguagesStore(['fr', 'de']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The language of the site, fr, cannot be closed.');

        try {
            (new PublishedLanguages($store))->unpublish(Language::Fr);
        } finally {
            self::assertSame([], $store->writes, 'nothing is written');
        }
    }

    #[Test]
    public function the_closed_languages_of_a_fallback_chain_are_those_a_reader_could_be_served_unannounced(): void
    {
        $store = new InMemoryPublishedLanguagesStore(['fr']);
        $languages = new PublishedLanguages($store);

        self::assertSame([Language::En], $languages->closedFallbacks(Language::De), 'a German reader falls back on English before French');
        self::assertSame([], $languages->closedFallbacks(Language::En), 'an English reader falls back on French only, which is always open');
        self::assertSame([], $languages->closedFallbacks(Language::Fr));

        $store->value = ['fr', 'en'];

        self::assertSame([], $languages->closedFallbacks(Language::De), 'English open: nothing unannounced on the German chain');
    }

    #[Test]
    public function a_broken_stored_value_is_read_as_french_alone_and_repaired_on_the_next_write(): void
    {
        $store = new InMemoryPublishedLanguagesStore('de,en');
        $languages = new PublishedLanguages($store);

        self::assertSame([Language::Fr], $languages->all());

        $languages->publish(Language::De);

        self::assertSame(['fr', 'de'], $store->value);
    }
}
