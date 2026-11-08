<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\LanguageReadiness;
use Voltz\PortfolioMd\Service\ReadinessReport;
use Voltz\PortfolioMd\Tests\Support\Documents;
use Voltz\PortfolioMd\Tests\Support\InMemoryContentCatalog;
use Voltz\PortfolioMd\Tests\Support\InMemoryMenuBindings;

/**
 * The readiness grid of ADR-022 (point 2), played on the in-memory catalog:
 * the same resolution rules as the WordPress store, so "ready" is what the
 * site would serve.
 */
#[CoversClass(LanguageReadiness::class)]
#[CoversClass(ReadinessReport::class)]
final class LanguageReadinessTest extends TestCase
{
    private InMemoryContentCatalog $catalog;
    private InMemoryMenuBindings $menus;

    protected function setUp(): void
    {
        $this->catalog = new InMemoryContentCatalog();
        $this->menus = new InMemoryMenuBindings();
    }

    private function readiness(int $minimumProjects = LanguageReadiness::MINIMUM_PROJECTS): LanguageReadiness
    {
        return new LanguageReadiness($this->catalog, $this->menus, $minimumProjects);
    }

    /** The four French core pages, their German translations, and $twins German twins of French sheets. */
    private function germanSite(int $twins = 3, array $pageOverrides = []): void
    {
        $id = 100;
        foreach (['accueil' => 'startseite', 'a-propos' => 'ueber-mich', 'cv' => 'lebenslauf', 'contact' => 'kontakt'] as $key => $germanSlug) {
            $this->catalog->add(
                Documents::page(['id' => $id++, 'slug' => $key, 'translationKey' => $key]),
                Documents::page(['id' => $id++, 'slug' => $germanSlug, 'lang' => Language::De, 'translationKey' => $key, ...($pageOverrides[$key] ?? [])]),
            );
        }
        for ($i = 1; $i <= 5; $i++) {
            $this->catalog->add(Documents::project(['id' => 200 + $i, 'slug' => "sheet-{$i}", 'translationKey' => "sheet-{$i}"]));
            if ($i <= $twins) {
                $this->catalog->add(Documents::project(['id' => 300 + $i, 'slug' => "sheet-{$i}-de", 'lang' => Language::De, 'translationKey' => "sheet-{$i}"]));
            }
        }
        $this->menus->withMenu = [Language::Fr, Language::De];
    }

    #[Test]
    public function the_language_of_the_site_is_always_ready(): void
    {
        $report = $this->readiness()->assess(Language::Fr);

        self::assertTrue($report->isReady());
        self::assertSame([], $report->missing());
        self::assertCount(1, $report->checks);
    }

    #[Test]
    public function an_empty_language_misses_everything_and_says_what(): void
    {
        $report = $this->readiness()->assess(Language::De);

        self::assertFalse($report->isReady());
        self::assertSame(['page accueil', 'page a-propos', 'page cv', 'page contact', 'menu', 'projects (3 translated at least)'], $report->missing());
        self::assertSame('no published page startseite', $report->checks[0]['detail']);
        self::assertSame('no published page kontakt', $report->checks[3]['detail']);
        self::assertSame('0 translated sheet(s)', $report->checks[5]['detail']);
        self::assertSame('de', $report->toArray()['language']);
        self::assertFalse($report->toArray()['ready']);
    }

    #[Test]
    public function a_language_with_its_four_pages_a_menu_and_three_twins_is_ready(): void
    {
        $this->germanSite(twins: 3);

        $report = $this->readiness()->assess(Language::De);

        self::assertTrue($report->isReady(), implode(' | ', array_column($report->checks, 'detail')));
        self::assertSame('page startseite published', $report->checks[0]['detail']);
        self::assertSame('3 translated sheet(s)', $report->checks[5]['detail']);
    }

    #[Test]
    public function two_translated_sheets_are_not_enough_unless_the_minimum_says_so(): void
    {
        $this->germanSite(twins: 2);

        self::assertSame(['projects (3 translated at least)'], $this->readiness()->assess(Language::De)->missing());
        self::assertTrue($this->readiness(minimumProjects: 2)->assess(Language::De)->isReady());
    }

    #[Test]
    public function a_french_sheet_served_as_a_fallback_does_not_count_as_translated(): void
    {
        $this->germanSite(twins: 3);
        // An English twin is served to a German reader along the chain: a fallback, not a translation.
        $this->catalog->add(Documents::project(['id' => 404, 'slug' => 'sheet-4-en', 'lang' => Language::En, 'translationKey' => 'sheet-4']));

        $report = $this->readiness()->assess(Language::De);

        self::assertSame('3 translated sheet(s)', $report->checks[5]['detail']);
    }

    #[Test]
    public function a_missing_menu_is_the_only_thing_missing_when_the_content_is_there(): void
    {
        $this->germanSite();
        $this->menus->withMenu = [Language::Fr];

        self::assertSame(['menu'], $this->readiness()->assess(Language::De)->missing());
    }

    #[Test]
    public function a_page_under_the_right_slug_but_in_another_language_is_refused(): void
    {
        $this->germanSite(pageOverrides: ['cv' => ['lang' => Language::Fr]]);

        $report = $this->readiness()->assess(Language::De);

        self::assertSame(['page cv'], $report->missing());
        self::assertSame('the page lebenslauf is served in fr, not in de', $report->checks[2]['detail']);
    }

    #[Test]
    public function a_page_outside_its_translation_group_is_refused(): void
    {
        $this->germanSite(pageOverrides: ['contact' => ['translationKey' => 'kontakt']]);

        $report = $this->readiness()->assess(Language::De);

        self::assertSame(['page contact'], $report->missing());
        self::assertSame('the page kontakt carries the translation key kontakt, not contact', $report->checks[3]['detail']);
    }

    #[Test]
    public function the_grid_reads_the_catalog_in_the_language_assessed(): void
    {
        $this->germanSite();

        $this->readiness()->assess(Language::En);

        $languages = array_unique([
            ...array_map(static fn (array $call): string => $call['language']->value, $this->catalog->findCalls),
            ...array_map(static fn (array $call): string => $call['query']->language->value, $this->catalog->listCalls),
        ]);
        self::assertSame(['en'], array_values($languages));
        self::assertCount(4, $this->catalog->findCalls, 'one lookup per core page');
    }

    #[Test]
    public function counts_the_translated_sheets_across_every_page_of_the_collection(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->catalog->add(Documents::project(['id' => 1000 + $i, 'slug' => "many-{$i}", 'translationKey' => "many-{$i}", 'date' => Documents::utc('2026-01-01 00:00:00')->modify("+{$i} minutes")]));
        }
        foreach ([1, 60, 120] as $i) {
            $this->catalog->add(Documents::project(['id' => 2000 + $i, 'slug' => "many-{$i}-de", 'lang' => Language::De, 'translationKey' => "many-{$i}"]));
        }

        $report = $this->readiness()->assess(Language::De);

        self::assertSame('3 translated sheet(s)', $report->checks[5]['detail']);
        self::assertCount(2, $this->catalog->listCalls, 'two pages of one hundred');
    }

}
