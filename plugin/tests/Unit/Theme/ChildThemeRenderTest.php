<?php

declare(strict_types=1);

namespace Voltz\PortfolioMd\Tests\Unit\Theme;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Voltz\PortfolioMd\Domain\Language;
use Voltz\PortfolioMd\Service\ListItem;

/**
 * The rendering functions of the child theme (theme/portfolio-md-child/inc,
 * ADR-022 points 5 and 8): pure functions, exercised here with the escaping
 * stubs of the suite. The hooks that feed them run only inside WordPress.
 */
final class ChildThemeRenderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $theme = dirname(__DIR__, 4) . '/theme/portfolio-md-child/inc';
        require_once $theme . '/strings.php';
        require_once $theme . '/render.php';
    }

    /**
     * @return array<string, array<string, mixed>> the raw elements of Polylang 3.9, keyed by slug
     */
    private static function elements(): array
    {
        return [
            'fr' => ['slug' => 'fr', 'url' => 'http://example.test/fr/projets/', 'name' => 'Français', 'is_current' => true, 'has_translations' => true, 'current_lang' => true, 'no_translation' => false],
            'de' => ['slug' => 'de', 'url' => 'http://example.test/de/', 'name' => 'Deutsch', 'is_current' => false, 'has_translations' => false, 'current_lang' => false, 'no_translation' => true],
            'en' => ['slug' => 'en', 'url' => 'http://example.test/en/projets/', 'name' => 'English', 'is_current' => false, 'has_translations' => true, 'current_lang' => false, 'no_translation' => false],
        ];
    }

    #[Test]
    public function the_switcher_keeps_the_open_languages_in_the_order_of_the_option(): void
    {
        $items = portfolio_md_child_switcher_items(self::elements(), ['fr', 'en']);

        self::assertSame(['fr', 'en'], array_column($items, 'slug'));
        self::assertSame([
            ['slug' => 'fr', 'url' => 'http://example.test/fr/projets/', 'name' => 'Français', 'current' => true, 'noTranslation' => false],
            ['slug' => 'en', 'url' => 'http://example.test/en/projets/', 'name' => 'English', 'current' => false, 'noTranslation' => false],
        ], $items);
    }

    #[Test]
    public function the_switcher_reads_the_legacy_keys_objects_and_skips_what_it_cannot_read(): void
    {
        $legacy = [
            'fr' => (object) ['slug' => 'fr', 'url' => 'http://example.test/fr/', 'name' => 'Français', 'current_lang' => true, 'no_translation' => false],
            'de' => ['slug' => 'de', 'url' => 'http://example.test/de/', 'current_lang' => false, 'no_translation' => true],
            'xx' => ['url' => 'http://example.test/xx/'],
            'junk' => 'not an element',
        ];

        $items = portfolio_md_child_switcher_items($legacy, ['fr', 'de', 'en']);

        self::assertSame([
            ['slug' => 'fr', 'url' => 'http://example.test/fr/', 'name' => 'Français', 'current' => true, 'noTranslation' => false],
            ['slug' => 'de', 'url' => 'http://example.test/de/', 'name' => 'DE', 'current' => false, 'noTranslation' => true],
        ], $items, 'a name missing falls back on the code; a language Polylang does not know is skipped');
    }

    #[Test]
    public function the_switcher_renders_nothing_with_fewer_than_two_open_languages(): void
    {
        self::assertSame('', portfolio_md_child_render_switcher(portfolio_md_child_switcher_items(self::elements(), ['fr']), 'Langues'));
        self::assertSame('', portfolio_md_child_render_switcher([], 'Langues'));
    }

    #[Test]
    public function the_switcher_renders_the_current_language_as_text_and_the_others_as_links(): void
    {
        $html = portfolio_md_child_render_switcher(portfolio_md_child_switcher_items(self::elements(), ['fr', 'de', 'en']), 'Langues');

        self::assertStringStartsWith('<span class="lang-switcher" role="group" aria-label="Langues">', $html);
        self::assertStringContainsString('<span class="menu-bar-item lang-item current-lang"><span class="lang-current" lang="fr" aria-current="true"><span aria-hidden="true">FR</span><span class="screen-reader-text">Français</span></span></span>', $html);
        self::assertStringContainsString('<span class="menu-bar-item lang-item no-translation"><a href="http://example.test/de/" lang="de" hreflang="de"><span aria-hidden="true">DE</span><span class="screen-reader-text">Deutsch</span></a></span>', $html, 'a language without translation of the page links to its home, as Polylang gave it');
        self::assertStringContainsString('<span class="menu-bar-item lang-item"><a href="http://example.test/en/projets/" lang="en" hreflang="en"><span aria-hidden="true">EN</span><span class="screen-reader-text">English</span></a></span>', $html);
        self::assertStringNotContainsString(' id=', $html, 'GeneratePress renders the menu bar twice: no id');
        self::assertSame(2, substr_count($html, '<a '), 'two links for the two other languages: the current one is not a link');
    }

    #[Test]
    public function the_switcher_escapes_what_polylang_hands_over(): void
    {
        $items = [
            ['slug' => 'fr', 'url' => 'http://example.test/fr/', 'name' => 'Français', 'current' => true, 'noTranslation' => false],
            ['slug' => 'de', 'url' => 'http://example.test/de/?a=1&b=2', 'name' => 'Deutsch <b>', 'current' => false, 'noTranslation' => false],
        ];

        $html = portfolio_md_child_render_switcher($items, 'Langues & co');

        self::assertStringContainsString('aria-label="Langues &amp; co"', $html);
        self::assertStringContainsString('href="http://example.test/de/?a=1&#038;b=2"', $html);
        self::assertStringContainsString('Deutsch &lt;b&gt;', $html);
    }

    #[Test]
    public function a_card_of_the_language_of_the_page_has_no_badge_and_the_markup_of_a_post_of_generatepress(): void
    {
        $item = new ListItem(12, 'MomoCad', 'Un logiciel de dessin 2D', 'http://example.test/fr/projets/momocad/', Language::Fr, Language::Fr, false);

        $html = portfolio_md_child_render_cards([$item], 'fr');

        self::assertSame(
            '<article id="post-12" class="post-12 project type-project status-publish hentry portfolio-card" itemtype="https://schema.org/CreativeWork" itemscope>'
            . '<div class="inside-article">'
            . '<header class="entry-header"><h2 class="entry-title" itemprop="headline"><a href="http://example.test/fr/projets/momocad/" rel="bookmark">MomoCad</a></h2></header>'
            . '<div class="entry-summary"><p>Un logiciel de dessin 2D</p></div>'
            . '</div></article>',
            $html,
        );
    }

    #[Test]
    public function a_card_served_in_another_language_carries_that_language_and_the_badge_in_the_language_of_the_page(): void
    {
        $item = new ListItem(12, 'MomoCad', 'Un logiciel de dessin 2D', 'http://example.test/fr/projets/momocad/', Language::Fr, Language::De, true);

        $html = portfolio_md_child_render_cards([$item], 'de');

        self::assertStringContainsString('<article id="post-12" class="post-12 project type-project status-publish hentry portfolio-card is-fallback" lang="fr" itemtype=', $html);
        self::assertStringContainsString('<a href="http://example.test/fr/projets/momocad/" rel="bookmark" hreflang="fr">MomoCad</a> <span class="lang-badge" title="Diese Seite ist nur auf Französisch verfügbar."><span lang="fr" aria-hidden="true">FR</span><span class="screen-reader-text" lang="de">Diese Seite ist nur auf Französisch verfügbar.</span></span></h2>', $html);
    }

    #[Test]
    public function an_english_fallback_under_a_german_page_wears_an_en_badge_named_in_german(): void
    {
        $html = portfolio_md_child_render_badge('en', 'de');

        self::assertSame('<span class="lang-badge" title="Diese Seite ist nur auf Englisch verfügbar."><span lang="en" aria-hidden="true">EN</span><span class="screen-reader-text" lang="de">Diese Seite ist nur auf Englisch verfügbar.</span></span>', $html);
        self::assertStringContainsString('only available in French', portfolio_md_child_render_badge('fr', 'en'));
    }

    #[Test]
    public function a_card_escapes_the_title_and_the_excerpt_and_omits_an_empty_excerpt(): void
    {
        $item = new ListItem(7, 'C# <3 & co', '', 'http://example.test/fr/projets/c-sharp/?x=1&y=2', Language::Fr, Language::Fr, false);

        $html = portfolio_md_child_render_cards([$item], 'fr');

        self::assertStringContainsString('<a href="http://example.test/fr/projets/c-sharp/?x=1&#038;y=2" rel="bookmark">C# &lt;3 &amp; co</a>', $html);
        self::assertStringNotContainsString('entry-summary', $html);
    }

    #[Test]
    public function an_empty_list_is_one_sentence_in_the_language_of_the_page(): void
    {
        self::assertSame('<p class="no-results">Noch keine Projekte.</p>', portfolio_md_child_render_cards([], 'de'));
        self::assertSame('<p class="no-results">Aucun projet pour le moment.</p>', portfolio_md_child_render_cards([], 'xx'), 'an unknown language reads French');
    }

    #[Test]
    public function the_texts_of_the_theme_fall_back_on_french(): void
    {
        self::assertSame('Projekte', portfolio_md_child_text('projects', 'de'));
        self::assertSame('Projects', portfolio_md_child_text('projects', 'en'));
        self::assertSame('Projets', portfolio_md_child_text('projects', 'xx'));
        self::assertSame('', portfolio_md_child_text('unknown', 'fr'));
        self::assertSame('anglais', portfolio_md_child_language_name('en', 'fr'));
        self::assertSame('XX', portfolio_md_child_language_name('xx', 'de'));
        foreach (['fr', 'de', 'en'] as $language) {
            self::assertSame(['projects', 'no_projects', 'languages', 'only_in'], array_keys(portfolio_md_child_strings()[$language]), "every language has every text ({$language})");
            self::assertSame(['fr', 'de', 'en'], array_keys(portfolio_md_child_language_names()[$language]));
        }
    }
}
