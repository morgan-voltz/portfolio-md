<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\LanguageGatewayException;
use Voltz\PortfolioMd\Storage\PolylangLanguageGateway;
use Voltz\PortfolioMd\Tests\Support\WordPressSpy;

/**
 * The adapter against a Polylang double that mimics the three traps of the
 * real API (tests/Support/wp-stubs.php): an unknown slug clears the
 * language, a saved group drops a member of the wrong language and only a
 * group of two or more is written as a term.
 */
#[CoversClass(PolylangLanguageGateway::class)]
final class PolylangLanguageGatewayTest extends TestCase
{
    private PolylangLanguageGateway $gateway;

    protected function setUp(): void
    {
        WordPressSpy::reset();
        $this->gateway = new PolylangLanguageGateway(static fn (): bool => true);
    }

    #[Test]
    public function sets_the_language_of_a_post_and_reads_it_back(): void
    {
        $this->gateway->setLanguage(41, Language::De);

        self::assertSame('de', WordPressSpy::$pllPostLanguages[41]);
        self::assertSame([['set', 41, 'de']], WordPressSpy::$pllCalls);
        self::assertSame('de', $this->gateway->languageOf(41));
    }

    #[Test]
    public function a_language_already_set_is_not_written_again(): void
    {
        WordPressSpy::$pllPostLanguages[41] = 'fr';

        $this->gateway->setLanguage(41, Language::Fr);

        self::assertSame([], WordPressSpy::$pllCalls, 'Polylang would answer false, which is not an error; the write is spared');
    }

    #[Test]
    public function a_language_the_site_does_not_have_is_refused_before_any_write(): void
    {
        WordPressSpy::$pllLanguages = ['fr', 'de'];

        try {
            $this->gateway->setLanguage(41, Language::En);
            self::fail('an unknown slug would clear the language of the post');
        } catch (LanguageGatewayException $exception) {
            self::assertStringContainsString('"en" is not configured on the site (found: fr, de)', $exception->getMessage());
        }
        self::assertSame([], WordPressSpy::$pllCalls);
    }

    #[Test]
    public function a_language_polylang_did_not_keep_is_reported(): void
    {
        WordPressSpy::$pllRefuseWrites = true;

        $this->expectException(LanguageGatewayException::class);
        $this->expectExceptionMessage('Post 41 did not take the language "de"');

        $this->gateway->setLanguage(41, Language::De);
    }

    #[Test]
    public function does_nothing_and_answers_nothing_when_polylang_is_absent(): void
    {
        $gateway = new PolylangLanguageGateway(static fn (): bool => false);

        $gateway->setLanguage(41, Language::De);
        $gateway->linkTranslations(['fr' => 40, 'de' => 41]);

        self::assertSame([], WordPressSpy::$pllCalls);
        self::assertNull($gateway->configuredLanguages());
        self::assertNull($gateway->languageOf(41));
        self::assertSame([], $gateway->translationsOf(41));
    }

    #[Test]
    public function links_a_group_after_setting_every_language_with_the_french_post_first(): void
    {
        $this->gateway->linkTranslations(['de' => 41, 'fr' => 40]);

        self::assertSame([['set', 41, 'de'], ['set', 40, 'fr'], ['save', ['fr' => 40, 'de' => 41]]], WordPressSpy::$pllCalls);
        self::assertSame(['fr' => 40, 'de' => 41], $this->gateway->translationsOf(40));
        self::assertSame(['de' => 41, 'fr' => 40], $this->gateway->translationsOf(41));
    }

    #[Test]
    public function a_group_saved_or_dissolved_cleans_the_languages_cache_of_polylang(): void
    {
        $model = new class {
            public int $cleaned = 0;

            public function clean_languages_cache(): void
            {
                $this->cleaned++;
            }
        };
        WordPressSpy::$pll = (object) ['model' => $model];

        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);

        self::assertSame(1, $model->cleaned, 'Polylang caches the home page of each language until the cache is cleaned (ADR-022, point 11)');

        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);

        self::assertSame(1, $model->cleaned, 'a group every member already sees changes nothing');

        $this->gateway->linkTranslations(['fr' => 40]);

        self::assertSame(2, $model->cleaned, 'dissolving a group cleans it too');
    }

    #[Test]
    public function a_group_every_member_already_sees_is_not_saved_again(): void
    {
        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);
        WordPressSpy::$pllCalls = [];

        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);

        self::assertSame([], WordPressSpy::$pllCalls);
    }

    #[Test]
    public function a_new_member_rewrites_the_whole_group(): void
    {
        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);
        WordPressSpy::$pllCalls = [];

        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41, 'en' => 42]);

        self::assertSame([['set', 42, 'en'], ['save', ['fr' => 40, 'de' => 41, 'en' => 42]]], WordPressSpy::$pllCalls);
        foreach ([40, 41, 42] as $postId) {
            $view = $this->gateway->translationsOf($postId);
            ksort($view);
            self::assertSame(['de' => 41, 'en' => 42, 'fr' => 40], $view, "view of post {$postId}");
        }
    }

    #[Test]
    public function a_group_of_one_dissolves_a_stale_group_and_is_otherwise_a_no_op(): void
    {
        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);
        WordPressSpy::$pllCalls = [];

        $this->gateway->linkTranslations(['fr' => 40]);

        self::assertSame([['save', ['fr' => 40]]], WordPressSpy::$pllCalls);
        self::assertSame([], $this->gateway->translationsOf(40), 'a view of one post is no group');
        self::assertSame([], $this->gateway->translationsOf(41), 'the removed member is detached by Polylang and sees only itself');

        WordPressSpy::$pllCalls = [];
        $this->gateway->linkTranslations(['fr' => 40]);
        self::assertSame([], WordPressSpy::$pllCalls, 'already alone: nothing to dissolve');
    }

    #[Test]
    public function a_member_that_lost_its_view_makes_the_group_be_saved_again(): void
    {
        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);
        unset(WordPressSpy::$pllTermOf[41]); // the German post was detached; the French one still sees the group
        WordPressSpy::$pllCalls = [];

        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);

        self::assertSame([['save', ['fr' => 40, 'de' => 41]]], WordPressSpy::$pllCalls, 'one member seeing the group is not enough: every member must');
        self::assertSame(['de' => 41, 'fr' => 40], $this->gateway->translationsOf(41));
    }

    #[Test]
    public function a_dissolve_polylang_did_not_keep_is_reported(): void
    {
        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);
        WordPressSpy::$pllRefuseGroups = true;

        $this->expectException(LanguageGatewayException::class);
        $this->expectExceptionMessage('The translation group {fr: 40} was not applied');

        $this->gateway->linkTranslations(['fr' => 40]);
    }

    #[Test]
    public function a_configured_slug_the_plugin_does_not_know_is_refused_in_a_group(): void
    {
        WordPressSpy::$pllLanguages = ['fr', 'de', 'en', 'es'];

        $this->expectException(LanguageGatewayException::class);
        $this->expectExceptionMessage('"es" is not configured');

        $this->gateway->linkTranslations(['fr' => 40, 'es' => 43]);
    }

    #[Test]
    public function the_default_probe_finds_no_polylang_in_the_unit_suite(): void
    {
        $gateway = new PolylangLanguageGateway();

        $gateway->setLanguage(41, Language::De);

        self::assertNull($gateway->configuredLanguages(), 'the PLL() stub answers null: no model, no Polylang');
        self::assertSame([], WordPressSpy::$pllCalls);
    }

    #[Test]
    public function a_group_polylang_did_not_keep_is_reported(): void
    {
        WordPressSpy::$pllRefuseGroups = true;

        $this->expectException(LanguageGatewayException::class);
        $this->expectExceptionMessage('The translation group {fr: 40, de: 41} was not applied');

        $this->gateway->linkTranslations(['fr' => 40, 'de' => 41]);
    }

    #[Test]
    public function a_group_with_a_language_the_site_does_not_have_is_refused(): void
    {
        WordPressSpy::$pllLanguages = ['fr', 'de'];

        $this->expectException(LanguageGatewayException::class);
        $this->expectExceptionMessage('"en" is not configured');

        $this->gateway->linkTranslations(['fr' => 40, 'en' => 42]);
    }

    #[Test]
    public function the_inspector_reports_what_polylang_holds(): void
    {
        self::assertSame(['fr', 'de', 'en'], $this->gateway->configuredLanguages());
        self::assertNull($this->gateway->languageOf(99), 'false from Polylang is null here');
        self::assertSame([], $this->gateway->translationsOf(99));

        WordPressSpy::$pllPostLanguages[99] = 'en';

        self::assertSame('en', $this->gateway->languageOf(99));
        self::assertSame([], $this->gateway->translationsOf(99), 'a post that sees only itself has no group');
    }
}
