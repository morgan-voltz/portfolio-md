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
        self::assertSame(['de' => 41, 'fr' => 40], $this->gateway->translationsOf(41), 'the removed member still sees the old group from its side, as Polylang leaves it');

        WordPressSpy::$pllCalls = [];
        $this->gateway->linkTranslations(['fr' => 40]);
        self::assertSame([], WordPressSpy::$pllCalls, 'already alone: nothing to dissolve');
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
